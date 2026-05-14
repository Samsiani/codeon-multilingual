<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

/**
 * One-shot background backfill: tags every legacy post with a cml_post_language row.
 *
 * Cron-driven, batched at 500/run with a 5s gap, idempotent (INSERT IGNORE on
 * a PRIMARY KEY collision). A transient lock guards against parallel runs.
 *
 * Status flow:  idle  ->  pending  ->  running  ->  done
 */
final class Backfill {

	public const HOOK           = 'cml_backfill_run';
	public const OPTION_STATUS  = 'cml_backfill_status';
	public const OPTION_LAST_ID = 'cml_backfill_last_id';

	private const BATCH_SIZE     = 500;
	private const LOCK_TTL       = 60;
	private const TRANSIENT_LOCK = 'cml_backfill_lock';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	public static function schedule(): void {
		update_option( self::OPTION_STATUS, 'pending', false );
		update_option( self::OPTION_LAST_ID, 0, false );

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	public static function run(): void {
		if ( 'done' === get_option( self::OPTION_STATUS ) ) {
			return;
		}
		if ( get_transient( self::TRANSIENT_LOCK ) ) {
			// Another batch is in flight — wait it out.
			wp_schedule_single_event( time() + 30, self::HOOK );
			return;
		}
		set_transient( self::TRANSIENT_LOCK, 1, self::LOCK_TTL );

		try {
			self::process_batch();
		} finally {
			delete_transient( self::TRANSIENT_LOCK );
		}
	}

	/**
	 * Synchronous variant for CLI / one-shot callers. Runs one batch and
	 * returns counts so the caller can loop until done without depending on
	 * wp-cron. Status options still mutate identically to the cron path.
	 *
	 * @return array{processed:int, remaining:int, done:bool}
	 */
	public static function run_batch_now(): array {
		global $wpdb;
		self::process_batch();
		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->prefix}cml_post_language pl ON pl.post_id = p.ID
			 WHERE pl.post_id IS NULL
			   AND p.post_status NOT IN ('auto-draft', 'trash')
			   AND p.post_type != 'revision'"
		);
		return array(
			'processed' => (int) $wpdb->rows_affected,
			'remaining' => $remaining,
			'done'      => 'done' === (string) get_option( self::OPTION_STATUS, 'idle' ),
		);
	}

	private static function process_batch(): void {
		global $wpdb;

		$last_id      = (int) get_option( self::OPTION_LAST_ID, 0 );
		$default_lang = Languages::default_code();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->prefix}cml_post_language pl ON pl.post_id = p.ID
				 WHERE pl.post_id IS NULL
				   AND p.ID > %d
				   AND p.post_status NOT IN ('auto-draft', 'trash')
				   AND p.post_type != 'revision'
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$last_id,
				self::BATCH_SIZE
			)
		);

		if ( empty( $ids ) ) {
			update_option( self::OPTION_STATUS, 'done', false );
			return;
		}

		$values       = array();
		$placeholders = array();
		foreach ( $ids as $id ) {
			$id_int         = (int) $id;
			$values[]       = $id_int;
			$values[]       = $id_int; // group_id = post_id (self-group on backfill)
			$values[]       = $default_lang;
			$placeholders[] = '(%d, %d, %s)';
		}

		$sql = "INSERT IGNORE INTO {$wpdb->prefix}cml_post_language (post_id, group_id, language) VALUES "
			. implode( ',', $placeholders );

		$wpdb->query( $wpdb->prepare( $sql, ...$values ) );

		$max_id = (int) max( array_map( 'intval', $ids ) );
		update_option( self::OPTION_LAST_ID, $max_id, false );
		update_option( self::OPTION_STATUS, 'running', false );

		wp_schedule_single_event( time() + 5, self::HOOK );
	}

	/**
	 * @return array{status:string, last_id:int, remaining:int}
	 */
	public static function status(): array {
		global $wpdb;

		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->prefix}cml_post_language pl ON pl.post_id = p.ID
			 WHERE pl.post_id IS NULL
			   AND p.post_status NOT IN ('auto-draft', 'trash')
			   AND p.post_type != 'revision'"
		);

		return array(
			'status'    => (string) get_option( self::OPTION_STATUS, 'idle' ),
			'last_id'   => (int) get_option( self::OPTION_LAST_ID, 0 ),
			'remaining' => $remaining,
		);
	}

	public static function reset(): void {
		delete_option( self::OPTION_STATUS );
		delete_option( self::OPTION_LAST_ID );
		delete_transient( self::TRANSIENT_LOCK );
		wp_clear_scheduled_hook( self::HOOK );
	}
}
