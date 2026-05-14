<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Cli;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Strings\L10nFileWriter;
use Samsiani\CodeonMultilingual\Strings\PoExporter;
use Samsiani\CodeonMultilingual\Strings\PoImporter;
use Samsiani\CodeonMultilingual\Strings\Scanner;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;
use WP_CLI;
use WP_CLI_Command;

/**
 * String catalog operations: scan, export, import, count.
 *
 * Wires Scanner / StringTranslator / L10nFileWriter to the CLI, with PO and
 * JSON round-tripping via PoExporter / PoImporter. Output formats are chosen
 * so a `wp cml strings export ... | wp cml strings import -` round-trip is
 * byte-stable (the test suite asserts this).
 *
 * Examples
 *
 *     wp cml strings scan --all
 *     wp cml strings scan --theme=twentytwentyfour --plugin=woocommerce
 *     wp cml strings export --lang=ka --format=po --output=ka.po
 *     wp cml strings export --lang=ka --domain=woocommerce --format=json
 *     wp cml strings import ka.po --lang=ka
 *     wp cml strings count
 */
final class StringsCommand extends WP_CLI_Command {

	/**
	 * Walks theme and/or plugin source for gettext calls, inserting new strings.
	 *
	 * Idempotent — INSERT IGNORE on the hash unique key. Re-scans add nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--theme=<slug>]
	 * : Scan a specific theme by stylesheet directory name. Repeatable via --all.
	 *
	 * [--plugin=<slug>]
	 * : Scan a specific plugin by folder name. Repeatable via --all.
	 *
	 * [--all]
	 * : Scan every active theme + every active plugin. Overrides --theme/--plugin.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function scan( array $args, array $assoc_args ): void {
		$theme  = (string) ( $assoc_args['theme'] ?? '' );
		$plugin = (string) ( $assoc_args['plugin'] ?? '' );
		$all    = isset( $assoc_args['all'] );

		if ( ! $all && '' === $theme && '' === $plugin ) {
			WP_CLI::error( 'Specify --theme=<slug>, --plugin=<slug>, or --all.' );
		}

		$targets = array();
		if ( $all ) {
			$active_theme   = wp_get_theme();
			$targets[]      = array(
				'kind' => 'theme',
				'slug' => (string) $active_theme->get_stylesheet(),
			);
			$active_plugins = (array) get_option( 'active_plugins', array() );
			foreach ( $active_plugins as $relative ) {
				$slug = (string) explode( '/', (string) $relative, 2 )[0];
				if ( '' !== $slug ) {
					$targets[] = array(
						'kind' => 'plugin',
						'slug' => $slug,
					);
				}
			}
		} else {
			if ( '' !== $theme ) {
				$targets[] = array(
					'kind' => 'theme',
					'slug' => $theme,
				);
			}
			if ( '' !== $plugin ) {
				$targets[] = array(
					'kind' => 'plugin',
					'slug' => $plugin,
				);
			}
		}

		$total = 0;
		$start = microtime( true );
		foreach ( $targets as $target ) {
			$inserted = 'theme' === $target['kind']
				? Scanner::scan_theme( $target['slug'] )
				: Scanner::scan_plugin( $target['slug'] );
			$total   += $inserted;
			WP_CLI::log( sprintf( '  scanned %s/%s — %d new', $target['kind'], $target['slug'], $inserted ) );
		}
		$elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );
		WP_CLI::success( "Scan complete: {$total} new strings in {$elapsed}ms." );
	}

	/**
	 * Exports source strings (with optional translations) to PO or JSON.
	 *
	 * ## OPTIONS
	 *
	 * --lang=<lang>
	 * : Target language whose translations to include alongside sources.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: po
	 * options:
	 *   - po
	 *   - json
	 * ---
	 *
	 * [--domain=<domain>]
	 * : Restrict to a single text-domain (e.g. woocommerce, twentytwentyfour).
	 *
	 * [--output=<file>]
	 * : Write to a file. Defaults to STDOUT.
	 *
	 * [--include-untranslated]
	 * : Include rows that have no translation yet (msgstr "" entries). Off by default.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function export( array $args, array $assoc_args ): void {
		$lang = (string) ( $assoc_args['lang'] ?? '' );
		if ( '' === $lang ) {
			WP_CLI::error( '--lang=<code> is required.' );
		}
		if ( ! Languages::exists( $lang ) ) {
			WP_CLI::error( "Unknown language '{$lang}'." );
		}

		$format               = (string) ( $assoc_args['format'] ?? 'po' );
		$domain               = (string) ( $assoc_args['domain'] ?? '' );
		$output               = (string) ( $assoc_args['output'] ?? '' );
		$include_untranslated = isset( $assoc_args['include-untranslated'] );

		global $wpdb;

		$where = '';
		$prep  = array( $lang );
		if ( '' !== $domain ) {
			$where  = ' AND s.domain = %s';
			$prep[] = $domain;
		}
		$join = $include_untranslated ? 'LEFT JOIN' : 'INNER JOIN';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.domain, s.source, s.context, COALESCE(st.translation, '') AS translation
				 FROM {$wpdb->prefix}cml_strings s
				 {$join} {$wpdb->prefix}cml_string_translations st
				   ON st.string_id = s.id AND st.language = %s
				 WHERE 1=1 {$where}
				 ORDER BY s.domain ASC, s.id ASC",
				...$prep
			)
		);

		$entries = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$entries[] = array(
					'domain'      => (string) $row->domain,
					'source'      => (string) $row->source,
					'context'     => (string) $row->context,
					'translation' => (string) $row->translation,
				);
			}
		}

		$body = 'json' === $format
			? PoExporter::to_json( $entries, $lang )
			: PoExporter::to_po( $entries, $lang );

		if ( '' !== $output ) {
			if ( false === file_put_contents( $output, $body ) ) {
				WP_CLI::error( "Could not write to {$output}." );
			}
			WP_CLI::success( sprintf( 'Exported %d entries to %s.', count( $entries ), $output ) );
			return;
		}

		WP_CLI::log( $body );
	}

	/**
	 * Imports translations from a PO or JSON file. Idempotent (REPLACE INTO).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the PO or JSON file. Use '-' to read from STDIN.
	 *
	 * [--format=<format>]
	 * : Force format. Auto-detected by extension when omitted.
	 * ---
	 * options:
	 *   - po
	 *   - json
	 *   - auto
	 * default: auto
	 * ---
	 *
	 * [--lang=<lang>]
	 * : Override the target language. Falls back to the file's Language: header or
	 *   JSON `language` field if not supplied.
	 *
	 * [--regenerate-l10n]
	 * : After import, rewrite the native .l10n.php files (only meaningful if
	 *   `use_native_l10n_files` is enabled in settings).
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function import( array $args, array $assoc_args ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file ) {
			WP_CLI::error( 'File path is required (use - for stdin).' );
		}

		$content = '-' === $file ? (string) file_get_contents( 'php://stdin' ) : (string) @file_get_contents( $file );
		if ( '' === $content ) {
			WP_CLI::error( "Empty or unreadable input: {$file}" );
		}

		$format = (string) ( $assoc_args['format'] ?? 'auto' );
		if ( 'auto' === $format ) {
			$format = self::detect_format( $file, $content );
		}

		$lang_override = (string) ( $assoc_args['lang'] ?? '' );

		$parsed = 'json' === $format
			? PoImporter::from_json( $content, $lang_override )
			: PoImporter::from_po( $content, $lang_override );

		$language = '' !== $lang_override ? $lang_override : $parsed['language'];
		if ( '' === $language ) {
			WP_CLI::error( 'Target language could not be determined. Provide --lang=<code>.' );
		}
		if ( ! Languages::exists( $language ) ) {
			WP_CLI::error( "Unknown language '{$language}'." );
		}

		foreach ( $parsed['warnings'] as $w ) {
			WP_CLI::warning( $w );
		}

		$stats = self::write_entries_to_db( $parsed['entries'], $language );
		StringTranslator::flush_cache();

		if ( isset( $assoc_args['regenerate-l10n'] ) && L10nFileWriter::is_enabled() ) {
			$result = L10nFileWriter::regenerate_all();
			WP_CLI::log( sprintf( '  regenerated %d .l10n.php files (%d errors).', $result['count'], count( $result['errors'] ) ) );
		}

		WP_CLI::success(
			sprintf(
				'Imported %d entries into %s (%d new strings, %d updated, %d skipped missing source).',
				count( $parsed['entries'] ),
				$language,
				$stats['inserted'],
				$stats['updated'],
				$stats['skipped']
			)
		);
	}

	/**
	 * Prints catalog counts (total sources, translations, breakdown by language).
	 */
	public function count(): void {
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cml_strings" );
		$rows  = $wpdb->get_results(
			"SELECT language, COUNT(*) AS n
			 FROM {$wpdb->prefix}cml_string_translations
			 GROUP BY language
			 ORDER BY language ASC"
		);
		WP_CLI::log( "Source strings: {$total}" );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				WP_CLI::log( sprintf( '  %s: %d translations', (string) $r->language, (int) $r->n ) );
			}
		}
	}

	/**
	 * @param array<int,array{domain:string,source:string,context:string,translation:string}> $entries
	 * @return array{inserted:int, updated:int, skipped:int}
	 */
	private static function write_entries_to_db( array $entries, string $language ): array {
		global $wpdb;

		$inserted = 0;
		$updated  = 0;
		$skipped  = 0;

		foreach ( $entries as $entry ) {
			$source = $entry['source'];
			if ( '' === $source ) {
				continue;
			}
			$translation = $entry['translation'];

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}cml_strings WHERE hash = UNHEX(MD5(%s))",
					$entry['domain'] . '|' . $entry['context'] . '|' . $source
				)
			);

			if ( ! $row ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->prefix}cml_strings (hash, domain, context, source, source_language, created_at) VALUES (UNHEX(MD5(%s)), %s, %s, %s, %s, %d)",
						$entry['domain'] . '|' . $entry['context'] . '|' . $source,
						$entry['domain'],
						$entry['context'],
						$source,
						StringTranslator::detect_source_language( $source ),
						time()
					)
				);
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}cml_strings WHERE hash = UNHEX(MD5(%s))",
						$entry['domain'] . '|' . $entry['context'] . '|' . $source
					)
				);
			}

			if ( ! $row ) {
				++$skipped;
				continue;
			}

			$string_id = (int) $row->id;

			if ( '' === trim( $translation ) ) {
				$deleted = $wpdb->delete(
					$wpdb->prefix . 'cml_string_translations',
					array(
						'string_id' => $string_id,
						'language'  => $language,
					),
					array( '%d', '%s' )
				);
				if ( false !== $deleted && $deleted > 0 ) {
					++$updated;
				}
				continue;
			}

			$result = $wpdb->query(
				$wpdb->prepare(
					"REPLACE INTO {$wpdb->prefix}cml_string_translations (string_id, language, translation, updated_at) VALUES (%d, %s, %s, %d)",
					$string_id,
					$language,
					$translation,
					time()
				)
			);
			if ( 1 === (int) $result ) {
				++$inserted;
			} else {
				++$updated;
			}
		}

		return array(
			'inserted' => $inserted,
			'updated'  => $updated,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Pure helper: choose import format from filename extension, falling back to
	 * content sniffing for stdin. Extracted so tests don't need the filesystem.
	 */
	public static function detect_format( string $file, string $content ): string {
		if ( '-' !== $file ) {
			$ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
			if ( 'json' === $ext ) {
				return 'json';
			}
			if ( 'po' === $ext ) {
				return 'po';
			}
		}
		$trimmed = ltrim( $content );
		if ( '' !== $trimmed && '{' === $trimmed[0] ) {
			return 'json';
		}
		return 'po';
	}
}
