<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Activation hook target.
 *
 * Invalidates our own opcache bytecode first (re-installs leak stale bytecode
 * which puts WP into false recovery mode), then installs the database schema.
 */
final class Activator {

	public static function activate(): void {
		self::flush_opcache();

		Schema::install();
		Backfill::schedule();
		self::backfill_terms();

		update_option( 'cml_db_version', Schema::VERSION, true );
		update_option( 'cml_activated_at', time(), false );
	}

	/**
	 * Schema upgrade entry point — called from admin_init on every admin request.
	 *
	 * Auto-update via wp-admin doesn't fire activation hooks, so we can't rely
	 * on Activator::activate() running. Stored cml_db_version vs Schema::VERSION
	 * decides whether dbDelta needs to run.
	 *
	 * Adds new columns (Schema v2 added cml_strings.source_language) without
	 * destroying existing data. Backfill runs once per upgrade.
	 */
	public static function maybe_upgrade(): void {
		$stored = (string) get_option( 'cml_db_version', '0' );
		if ( version_compare( $stored, Schema::VERSION, '>=' ) ) {
			return;
		}

		Schema::install();
		update_option( 'cml_db_version', Schema::VERSION, true );

		// One-time backfill for the new source_language column on existing rows.
		self::backfill_source_languages();
	}

	/**
	 * Walk every cml_strings row and stamp source_language based on character set
	 * detection. Runs once per schema upgrade; ~3 ms per 100 rows.
	 */
	private static function backfill_source_languages(): void {
		global $wpdb;
		$batch_size = 500;
		$last_id    = 0;

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, source FROM {$wpdb->prefix}cml_strings WHERE id > %d ORDER BY id ASC LIMIT %d",
					$last_id,
					$batch_size
				)
			);
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$detected = \Samsiani\CodeonMultilingual\Strings\StringTranslator::detect_source_language( (string) $row->source );
				$wpdb->update(
					$wpdb->prefix . 'cml_strings',
					array( 'source_language' => $detected ),
					array( 'id' => (int) $row->id ),
					array( '%s' ),
					array( '%d' )
				);
				$last_id = (int) $row->id;
			}

			if ( count( $rows ) < $batch_size ) {
				break;
			}
		}
	}

	/**
	 * Term backfill runs synchronously — term counts are bounded (hundreds, not
	 * millions) so a single INSERT IGNORE ... SELECT is fine.
	 */
	private static function backfill_terms(): void {
		global $wpdb;
		$default = Languages::default_code();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}cml_term_language (term_id, group_id, language)
				 SELECT term_id, term_id, %s FROM {$wpdb->terms}",
				$default
			)
		);
	}

	private static function flush_opcache(): void {
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}
		if ( ! ini_get( 'opcache.enable' ) && ! ini_get( 'opcache.enable_cli' ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( CML_PATH, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				@opcache_invalidate( $file->getPathname(), true );
			}
		}
	}
}
