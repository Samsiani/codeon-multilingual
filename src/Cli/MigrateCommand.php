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
	 * [--allow-conflicts]
	 * : Import missing rows even when existing CodeOn data conflicts with WPML.
	 * Conflicting rows are left unchanged; nothing is overwritten.
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
		WP_CLI::log( 'Conflicts:' );
		WP_CLI::log( sprintf( '  language settings:   %d', (int) $summary['conflicts']['language_settings'] ) );
		WP_CLI::log( sprintf( '  post mappings:       %d', (int) $summary['conflicts']['post_mappings'] ) );
		WP_CLI::log( sprintf( '  term mappings:       %d', (int) $summary['conflicts']['term_mappings'] ) );
		WP_CLI::log( sprintf( '  string translations: %d', (int) $summary['conflicts']['string_translations'] ) );

		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( 'Dry run: nothing written.' );
			return;
		}

		$result = WpmlImporter::import_all( isset( $assoc_args['allow-conflicts'] ) );

		WP_CLI::log( 'Imported:' );
		WP_CLI::log( sprintf( '  languages:           %d', $result['languages'] ) );
		WP_CLI::log( sprintf( '  posts:               %d', $result['posts'] ) );
		WP_CLI::log( sprintf( '  terms:               %d', $result['terms'] ) );
		WP_CLI::log( sprintf( '  strings:             %d', $result['strings'] ) );
		WP_CLI::log( sprintf( '  translated strings:  %d', $result['translated_strings'] ) );
		WP_CLI::log( sprintf( '  default set:         %s', $result['default_set'] ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( '  conflicts:           %d', array_sum( $result['conflicts'] ) ) );

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $err ) {
				WP_CLI::warning( $err );
			}
			WP_CLI::error( 'Migration finished with errors. Re-run is safe (idempotent).' );
		}

		WP_CLI::success( 'WPML migration complete.' );
	}
}
