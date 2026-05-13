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
