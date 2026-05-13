<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Strings;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * File-system scanner for translatable strings.
 *
 * Walks a directory recursively, reads every .php file, and matches gettext-
 * family calls with literal string arguments. Findings are deduped by hash
 * and bulk-inserted into wp_cml_strings.
 *
 * Regex-based (not PHP tokens) — catches the overwhelming majority of real
 * plugin code (single-line literal args). Misses heredoc/nowdoc, concatenated
 * strings, variable args — those are intentionally out of scope for a
 * lightweight scanner.
 */
final class Scanner {

	/**
	 * Matches: __, _e, esc_html__, esc_html_e, esc_attr__, esc_attr_e, translate
	 *   fn( 'source', 'domain' )
	 */
	private const PATTERN_SIMPLE =
		'/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|translate)\s*\(\s*'
		. '([\'"])((?:\\\\.|(?!\1)[^\\\\])*)\1\s*,\s*'
		. '([\'"])((?:\\\\.|(?!\3)[^\\\\])*)\3\s*\)/s';

	/**
	 * Matches: _x, _ex, esc_html_x, esc_attr_x
	 *   fn( 'source', 'context', 'domain' )
	 */
	private const PATTERN_CONTEXT =
		'/\b(?:_x|_ex|esc_html_x|esc_attr_x)\s*\(\s*'
		. '([\'"])((?:\\\\.|(?!\1)[^\\\\])*)\1\s*,\s*'
		. '([\'"])((?:\\\\.|(?!\3)[^\\\\])*)\3\s*,\s*'
		. '([\'"])((?:\\\\.|(?!\5)[^\\\\])*)\5\s*\)/s';

	public static function scan_plugin( string $slug ): int {
		$path = self::plugins_dir() . '/' . $slug;
		if ( ! is_dir( $path ) ) {
			return 0;
		}
		return self::scan_directory( $path );
	}

	public static function scan_theme( string $slug ): int {
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return 0;
		}
		$path = (string) $theme->get_stylesheet_directory();
		return self::scan_directory( $path );
	}

	public static function scan_directory( string $dir ): int {
		$found = array();
		self::collect( $dir, $found );
		return self::flush( $found );
	}

	/**
	 * @param array<string, array{domain:string,context:string,source:string}> $found
	 */
	private static function collect( string $dir, array &$found ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$content = @file_get_contents( $file->getPathname() );
			if ( false === $content ) {
				continue;
			}
			self::scan_content( $content, $found );
		}
	}

	/**
	 * @param array<string, array{domain:string,context:string,source:string}> $found
	 */
	private static function scan_content( string $content, array &$found ): void {
		if ( preg_match_all( self::PATTERN_SIMPLE, $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$source = self::unescape( $m[2], $m[1] );
				$domain = self::unescape( $m[4], $m[3] );
				if ( '' === $source ) {
					continue;
				}
				$hash           = md5( $domain . '||' . $source );
				$found[ $hash ] = array(
					'domain'  => $domain,
					'context' => '',
					'source'  => $source,
				);
			}
		}

		if ( preg_match_all( self::PATTERN_CONTEXT, $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$source  = self::unescape( $m[2], $m[1] );
				$context = self::unescape( $m[4], $m[3] );
				$domain  = self::unescape( $m[6], $m[5] );
				if ( '' === $source ) {
					continue;
				}
				$hash           = md5( $domain . '|' . $context . '|' . $source );
				$found[ $hash ] = array(
					'domain'  => $domain,
					'context' => $context,
					'source'  => $source,
				);
			}
		}
	}

	private static function unescape( string $raw, string $quote ): string {
		if ( '"' === $quote ) {
			return stripcslashes( $raw );
		}
		return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $raw );
	}

	/**
	 * @param array<string, array{domain:string,context:string,source:string}> $found
	 */
	private static function flush( array $found ): int {
		if ( empty( $found ) ) {
			return 0;
		}

		global $wpdb;
		$values       = array();
		$placeholders = array();
		$now          = time();

		foreach ( $found as $hash => $info ) {
			$values[]       = $hash;
			$values[]       = $info['domain'];
			$values[]       = $info['context'];
			$values[]       = $info['source'];
			$values[]       = StringTranslator::detect_source_language( $info['source'] );
			$values[]       = $now;
			$placeholders[] = '(UNHEX(%s), %s, %s, %s, %s, %d)';
		}

		$sql = "INSERT IGNORE INTO {$wpdb->prefix}cml_strings (hash, domain, context, source, source_language, created_at) VALUES "
			. implode( ',', $placeholders );

		$wpdb->query( $wpdb->prepare( $sql, ...$values ) );
		StringTranslator::flush_cache();

		return (int) $wpdb->rows_affected;
	}

	private static function plugins_dir(): string {
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			return WP_PLUGIN_DIR;
		}
		return WP_CONTENT_DIR . '/plugins';
	}
}
