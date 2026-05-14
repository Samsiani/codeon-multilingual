<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

/**
 * Bundled catalog of selectable languages used by the Setup Wizard and the
 * "Add Language" admin page.
 *
 * Sourced from WPML's curated language list (66 entries with native names),
 * joined with their ISO code + locale tables and overlaid with ISO 3166-1
 * alpha-2 country codes so the plugin can render flag emoji without bundling
 * image assets.
 *
 * Stored as JSON in res/languages.json (~9 KB) so the data is editable
 * without touching code. Loaded once per request and cached in static memory.
 *
 * Entry shape:
 *   array{code:string, name:string, native:string, locale:string,
 *         flag:string, rtl:int, major:int}
 */
final class LanguageCatalog {

	/** @var array<int, array{code:string,name:string,native:string,locale:string,flag:string,rtl:int,major:int}>|null */
	private static ?array $cache = null;

	/**
	 * @return array<int, array{code:string,name:string,native:string,locale:string,flag:string,rtl:int,major:int}>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$path = self::file_path();
		if ( ! is_readable( $path ) ) {
			self::$cache = array();
			return array();
		}
		$raw = (string) file_get_contents( $path );
		/** @var mixed $decoded */
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			self::$cache = array();
			return array();
		}

		$entries = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['code'] ) ) {
				continue;
			}
			$entries[] = array(
				'code'   => (string) $row['code'],
				'name'   => (string) ( $row['name'] ?? '' ),
				'native' => (string) ( $row['native'] ?? '' ),
				'locale' => (string) ( $row['locale'] ?? '' ),
				'flag'   => (string) ( $row['flag'] ?? '' ),
				'rtl'    => (int) ( $row['rtl'] ?? 0 ),
				'major'  => (int) ( $row['major'] ?? 0 ),
			);
		}

		self::$cache = $entries;
		return $entries;
	}

	/**
	 * @return array{code:string,name:string,native:string,locale:string,flag:string,rtl:int,major:int}|null
	 */
	public static function get( string $code ): ?array {
		foreach ( self::all() as $entry ) {
			if ( $entry['code'] === $code ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Substring search across code, English name, and native name (case-insensitive).
	 *
	 * Major-flagged languages are returned first when there's a tie so a user
	 * typing "en" lands on English rather than scrolling past every entry whose
	 * code happens to contain the letters.
	 *
	 * @return array<int, array{code:string,name:string,native:string,locale:string,flag:string,rtl:int,major:int}>
	 */
	public static function search( string $query ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return self::all();
		}
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );

		$hits = array();
		foreach ( self::all() as $entry ) {
			$haystack = function_exists( 'mb_strtolower' )
				? mb_strtolower( $entry['code'] . '|' . $entry['name'] . '|' . $entry['native'] )
				: strtolower( $entry['code'] . '|' . $entry['name'] . '|' . $entry['native'] );
			if ( false !== ( function_exists( 'mb_strpos' ) ? mb_strpos( $haystack, $needle ) : strpos( $haystack, $needle ) ) ) {
				$hits[] = $entry;
			}
		}
		usort(
			$hits,
			static function ( array $a, array $b ): int {
				return ( $b['major'] <=> $a['major'] ) ?: strnatcasecmp( $a['name'], $b['name'] );
			}
		);
		return $hits;
	}

	/**
	 * @return array<int, array{code:string,name:string,native:string,locale:string,flag:string,rtl:int,major:int}>
	 */
	public static function major(): array {
		return array_values(
			array_filter(
				self::all(),
				static fn( array $e ): bool => 1 === $e['major']
			)
		);
	}

	/**
	 * @return array{code:string,name:string,native:string,locale:string,flag:string,rtl:int,major:int}|null
	 */
	public static function by_locale( string $locale ): ?array {
		foreach ( self::all() as $entry ) {
			if ( $entry['locale'] === $locale ) {
				return $entry;
			}
		}
		// Loose fallback: match by language part (e.g. en_GB → en).
		$lang = strtolower( substr( $locale, 0, 2 ) );
		foreach ( self::all() as $entry ) {
			if ( $entry['code'] === $lang ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Render a flag emoji from an ISO 3166-1 alpha-2 country code by composing
	 * two Regional Indicator Symbol letters. Returns '' for empty / non-alpha
	 * inputs so the caller can omit the flag span entirely for languages
	 * without a country (Esperanto, Yiddish).
	 */
	public static function flag_emoji( string $country_code ): string {
		$code = strtolower( trim( $country_code ) );
		if ( 2 !== strlen( $code ) || ! ctype_alpha( $code ) ) {
			return '';
		}
		$base = 0x1F1E6 - ord( 'a' );
		return mb_chr( ord( $code[0] ) + $base, 'UTF-8' ) . mb_chr( ord( $code[1] ) + $base, 'UTF-8' );
	}

	/** Filesystem path to the bundled catalog JSON. Exposed for tests. */
	public static function file_path(): string {
		if ( defined( 'CML_PATH' ) ) {
			return CML_PATH . 'res/languages.json';
		}
		return __DIR__ . '/../../res/languages.json';
	}

	/** Reset the request-static cache. Call this from tests after pointing file_path elsewhere. */
	public static function flush_cache(): void {
		self::$cache = null;
	}
}
