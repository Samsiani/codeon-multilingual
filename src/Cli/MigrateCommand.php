<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Cli;

use Samsiani\CodeonMultilingual\Migration\MigrationSourceRegistry;
use Samsiani\CodeonMultilingual\Migration\PolylangImporter;
use Samsiani\CodeonMultilingual\Migration\WpmlImporter;
use Samsiani\CodeonMultilingual\Migration\WpmlMigrationSnapshot;
use WP_CLI;
use WP_CLI\Utils;
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
 *     wp cml migrate wpml --snapshot=/secure/backups/codeon-before-wpml.json
 *     wp cml migrate wpml
 *     wp cml migrate polylang --dry-run
 *     wp cml migrate polylang --snapshot=/secure/backups/codeon-before-polylang.json
 *     wp cml migrate export --output=/secure/backups/codeon-before-wpml.json
 *     wp cml migrate rollback /secure/backups/codeon-before-wpml.json --dry-run
 *     wp cml migrate rollback /secure/backups/codeon-before-wpml.json --confirm-rollback
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
	 * [--snapshot=<file>]
	 * : Export a rollback snapshot of current CodeOn migration tables before importing.
	 * Refuses to overwrite an existing file unless --force-snapshot is also passed.
	 *
	 * [--force-snapshot]
	 * : Allow --snapshot to overwrite an existing file.
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
			if ( isset( $assoc_args['snapshot'] ) ) {
				WP_CLI::error( '--dry-run cannot be combined with --snapshot because dry runs are read-only.' );
			}
			if ( array_sum( $summary['conflicts'] ) > 0 ) {
				WP_CLI::warning( 'Dry run found conflicts. A real import will stop unless --allow-conflicts is supplied.' );
			}
			WP_CLI::success( 'Dry run: nothing written.' );
			return;
		}

		$snapshot = (string) ( $assoc_args['snapshot'] ?? '' );
		if ( '' !== $snapshot ) {
			if ( '-' === $snapshot ) {
				WP_CLI::error( '--snapshot must be a file path when used with import.' );
			}
			self::write_snapshot_file( $snapshot, isset( $assoc_args['force-snapshot'] ), '--force-snapshot' );
			WP_CLI::log( "Rollback snapshot written to {$snapshot}." );
		} else {
			WP_CLI::warning( 'No CodeOn rollback snapshot was exported. Use --snapshot=<file> before production imports.' );
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

	/**
	 * Imports languages and post/term translation relationships from Polylang.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be imported and exit without writing.
	 *
	 * [--allow-conflicts]
	 * : Import missing rows even when existing CodeOn data conflicts with Polylang.
	 * Conflicting rows are left unchanged; nothing is overwritten.
	 *
	 * [--snapshot=<file>]
	 * : Export a rollback snapshot of current CodeOn migration tables before importing.
	 * Refuses to overwrite an existing file unless --force-snapshot is also passed.
	 *
	 * [--force-snapshot]
	 * : Allow --snapshot to overwrite an existing file.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function polylang( array $args, array $assoc_args ): void {
		if ( ! PolylangImporter::is_available() ) {
			WP_CLI::error( 'No Polylang data found: language taxonomy rows are not present.' );
		}

		$summary = PolylangImporter::summary();
		WP_CLI::log( 'Polylang data available:' );
		WP_CLI::log( sprintf( '  languages:           %d', (int) $summary['languages'] ) );
		WP_CLI::log( sprintf( '  posts:               %d', (int) $summary['posts'] ) );
		WP_CLI::log( sprintf( '  terms:               %d', (int) $summary['terms'] ) );
		WP_CLI::log( sprintf( '  string stores:       %d', (int) $summary['strings'] ) );
		WP_CLI::log( sprintf( '  default language:    %s', (string) ( $summary['default_language'] ?? '—' ) ) );
		WP_CLI::log( 'Conflicts:' );
		WP_CLI::log( sprintf( '  language settings:   %d', (int) $summary['conflicts']['language_settings'] ) );
		WP_CLI::log( sprintf( '  post mappings:       %d', (int) $summary['conflicts']['post_mappings'] ) );
		WP_CLI::log( sprintf( '  term mappings:       %d', (int) $summary['conflicts']['term_mappings'] ) );
		foreach ( $summary['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			if ( isset( $assoc_args['snapshot'] ) ) {
				WP_CLI::error( '--dry-run cannot be combined with --snapshot because dry runs are read-only.' );
			}
			if ( array_sum( $summary['conflicts'] ) > 0 ) {
				WP_CLI::warning( 'Dry run found conflicts. A real import will stop unless --allow-conflicts is supplied.' );
			}
			WP_CLI::success( 'Dry run: nothing written.' );
			return;
		}

		$snapshot = (string) ( $assoc_args['snapshot'] ?? '' );
		if ( '' !== $snapshot ) {
			if ( '-' === $snapshot ) {
				WP_CLI::error( '--snapshot must be a file path when used with import.' );
			}
			self::write_snapshot_file( $snapshot, isset( $assoc_args['force-snapshot'] ), '--force-snapshot' );
			WP_CLI::log( "Rollback snapshot written to {$snapshot}." );
		} else {
			WP_CLI::warning( 'No CodeOn rollback snapshot was exported. Use --snapshot=<file> before production imports.' );
		}

		$result = PolylangImporter::import_all( isset( $assoc_args['allow-conflicts'] ) );

		WP_CLI::log( 'Imported:' );
		WP_CLI::log( sprintf( '  languages:           %d', $result['languages'] ) );
		WP_CLI::log( sprintf( '  posts:               %d', $result['posts'] ) );
		WP_CLI::log( sprintf( '  terms:               %d', $result['terms'] ) );
		WP_CLI::log( sprintf( '  strings:             %d', $result['strings'] ) );
		WP_CLI::log( sprintf( '  default set:         %s', $result['default_set'] ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( '  conflicts:           %d', array_sum( $result['conflicts'] ) ) );
		foreach ( $result['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $err ) {
				WP_CLI::warning( $err );
			}
			WP_CLI::error( 'Migration finished with errors. Re-run is safe (idempotent).' );
		}

		WP_CLI::success( 'Polylang migration complete.' );
	}

	/**
	 * Lists known migration sources and the current support level.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function sources( array $args, array $assoc_args ): void {
		$rows = MigrationSourceRegistry::cli_rows();

		Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Exports current CodeOn migration tables as a rollback snapshot.
	 *
	 * ## OPTIONS
	 *
	 * [--output=<file>]
	 * : Destination path. Use - or omit to write JSON to STDOUT.
	 *
	 * [--force]
	 * : Overwrite an existing output file.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function export( array $args, array $assoc_args ): void {
		$output = (string) ( $assoc_args['output'] ?? '-' );

		if ( '-' === $output || '' === $output ) {
			WP_CLI::log( WpmlMigrationSnapshot::to_json() );
			return;
		}

		self::write_snapshot_file( $output, isset( $assoc_args['force'] ), '--force' );
		$report = WpmlMigrationSnapshot::report();
		WP_CLI::success(
			sprintf(
				'Exported CodeOn migration snapshot to %s (%d rows).',
				$output,
				(int) $report['total_rows']
			)
		);
	}

	/**
	 * Restores CodeOn migration tables from a snapshot exported by `wp cml migrate export`.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Snapshot JSON file to restore.
	 *
	 * [--dry-run]
	 * : Validate and report the snapshot without writing anything.
	 *
	 * [--confirm-rollback]
	 * : Required for a real restore. Replaces current CodeOn migration tables.
	 *
	 * [--allow-site-mismatch]
	 * : Allow restoring a snapshot generated on another site URL.
	 *
	 * [--allow-prefix-mismatch]
	 * : Allow restoring a snapshot generated with another database table prefix.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function rollback( array $args, array $assoc_args ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file ) {
			WP_CLI::error( 'Snapshot file path is required.' );
		}

		$json = (string) @file_get_contents( $file );
		if ( '' === $json ) {
			WP_CLI::error( "Empty or unreadable snapshot: {$file}" );
		}

		$result = WpmlMigrationSnapshot::restore_from_json(
			$json,
			array(
				'dry_run'               => isset( $assoc_args['dry-run'] ),
				'confirm_destructive'   => isset( $assoc_args['confirm-rollback'] ),
				'allow_site_mismatch'   => isset( $assoc_args['allow-site-mismatch'] ),
				'allow_prefix_mismatch' => isset( $assoc_args['allow-prefix-mismatch'] ),
			)
		);

		WP_CLI::log( 'Snapshot rows:' );
		foreach ( $result['counts'] as $table => $count ) {
			WP_CLI::log( sprintf( '  %s: %d', $table, $count ) );
		}
		foreach ( $result['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				WP_CLI::warning( $error );
			}
			WP_CLI::error( 'Rollback was not applied.' );
		}

		if ( $result['dry_run'] ) {
			WP_CLI::success( 'Dry run: snapshot is valid; nothing written.' );
			return;
		}

		WP_CLI::success( 'CodeOn migration tables restored from snapshot.' );
	}

	private static function write_snapshot_file( string $path, bool $force, string $force_flag ): void {
		if ( file_exists( $path ) && ! $force ) {
			WP_CLI::error( "Snapshot already exists: {$path}. Pass {$force_flag} to overwrite." );
		}

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			WP_CLI::error( "Snapshot directory is not writable: {$dir}" );
		}

		$json = WpmlMigrationSnapshot::to_json();
		if ( false === file_put_contents( $path, $json ) ) {
			WP_CLI::error( "Could not write snapshot to {$path}." );
		}
	}
}
