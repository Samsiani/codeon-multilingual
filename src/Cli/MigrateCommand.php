<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Cli;

use Samsiani\CodeonMultilingual\Migration\WpmlImporter;
use WP_CLI;
use WP_CLI_Command;

/**
 * Run the WPML data migration from the command line.
 *
 * Wraps WpmlImporter for ops contexts where the admin migration page is
 * impractical (large catalogs, headless deploys, scripted staging refreshes).
 * --dry-run prints the would-be import counts without writing anything.
 *
 * Examples
 *
 *     wp cml migrate wpml --dry-run
 *     wp cml migrate wpml
 */
final class MigrateCommand extends WP_CLI_Command {

	/**
	 * Imports languages, post/term translations, and string catalog from WPML's icl_* tables.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be imported and exit without writing.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function wpml( array $args, array $assoc_args ): void {
		if ( ! WpmlImporter::is_available() ) {
			WP_CLI::error( 'No WPML data found: wp_icl_translations table is not present.' );
		}

		$summary = WpmlImporter::summary();
		WP_CLI::log( 'WPML data available:' );
		WP_CLI::log( sprintf( '  languages:           %d', (int) $summary['languages'] ) );
		WP_CLI::log( sprintf( '  posts:               %d', (int) $summary['posts'] ) );
		WP_CLI::log( sprintf( '  terms:               %d', (int) $summary['terms'] ) );
		WP_CLI::log( sprintf( '  strings:             %d', (int) $summary['strings'] ) );
		WP_CLI::log( sprintf( '  translated strings:  %d', (int) $summary['translated_strings'] ) );
		WP_CLI::log( sprintf( '  default language:    %s', (string) ( $summary['default_language'] ?? '—' ) ) );

		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( 'Dry run: nothing written.' );
			return;
		}

		$result = WpmlImporter::import_all();

		WP_CLI::log( 'Imported:' );
		WP_CLI::log( sprintf( '  languages:           %d', $result['languages'] ) );
		WP_CLI::log( sprintf( '  posts:               %d', $result['posts'] ) );
		WP_CLI::log( sprintf( '  terms:               %d', $result['terms'] ) );
		WP_CLI::log( sprintf( '  strings:             %d', $result['strings'] ) );
		WP_CLI::log( sprintf( '  translated strings:  %d', $result['translated_strings'] ) );
		WP_CLI::log( sprintf( '  default set:         %s', $result['default_set'] ? 'yes' : 'no' ) );

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $err ) {
				WP_CLI::warning( $err );
			}
			WP_CLI::error( 'Migration finished with errors. Re-run is safe (idempotent).' );
		}

		WP_CLI::success( 'WPML migration complete.' );
	}
}
