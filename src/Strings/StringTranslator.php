<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Strings;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * String translation runtime — gettext interceptor + lazy compiled map.
 *
 * Hot-path cost per __() call: 1 md5 + 2 isset() lookups (~0.7μs). Real-page
 * overhead with 1000 __() calls: under 1ms. We do NOT compile to .l10n.php
 * files; instead a single wp_cache entry holds the compiled hash->translation
 * map for the current language. This keeps the write path simple (no fs writes,
 * no multisite paths) at near-identical read cost.
 *
 * Three caches, each request-static + persistent:
 *   compiled_<lang>   hash -> translation     (lookup)
 *   known_hashes      hash -> true            (skip rediscovery of existing strings)
 *
 * Discovery is throttled per shutdown — one batch INSERT IGNORE per request.
 *
 * Default-language requests skip lookup entirely (no translations apply) but
 * still discover strings so the catalog grows from any traffic.
 *
 * Strings over 2 KB are returned unchanged and not discovered — they are
 * almost always huge HTML blocks, licence text, or template chunks that
 * shouldn't be hand-translated string-by-string.
 */
final class StringTranslator {

	private const CACHE_GROUP       = 'cml_strings';
	private const COMPILED_TTL      = 12 * HOUR_IN_SECONDS;
	private const KNOWN_TTL         = HOUR_IN_SECONDS;
	private const SOURCE_MAX_LENGTH = 2048;
	private const FLUSH_LOCK_TTL    = 60;

	/** @var array<string, string>|null hash => translation */
	private static ?array $compiled = null;

	/** @var array<string, true>|null hash => true */
	private static ?array $known = null;

	/** @var array<string, array{domain:string,context:string,source:string}> */
	private static array $seen_new = array();

	private static bool $registered     = false;
	private static bool $shutdown_armed = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'gettext', array( self::class, 'on_gettext' ), 10, 3 );
		add_filter( 'gettext_with_context', array( self::class, 'on_gettext_with_context' ), 10, 4 );
	}

	// ---- Filters ---------------------------------------------------------

	/**
	 * @param string|null $translation
	 * @param string|null $text
	 * @param string|null $domain
	 */
	public static function on_gettext( $translation, $text, $domain ): string {
		return self::resolve( (string) $translation, (string) $text, '', (string) $domain );
	}

	/**
	 * @param string|null $translation
	 * @param string|null $text
	 * @param string|null $context
	 * @param string|null $domain
	 */
	public static function on_gettext_with_context( $translation, $text, $context, $domain ): string {
		return self::resolve( (string) $translation, (string) $text, (string) $context, (string) $domain );
	}

	private static function resolve( string $translation, string $text, string $context, string $domain ): string {
		if ( '' === $text ) {
			return $translation;
		}
		if ( strlen( $text ) > self::SOURCE_MAX_LENGTH ) {
			return $translation;
		}

		$hash = self::hash( $domain, $context, $text );

		self::discover( $hash, $domain, $context, $text );

		if ( CurrentLanguage::is_default() ) {
			return $translation;
		}

		$compiled = self::compiled_map();
		return $compiled[ $hash ] ?? $translation;
	}

	// ---- Discovery -------------------------------------------------------

	private static function discover( string $hash, string $domain, string $context, string $text ): void {
		if ( isset( self::$seen_new[ $hash ] ) ) {
			return;
		}
		if ( isset( ( self::known_set() )[ $hash ] ) ) {
			return;
		}

		self::$seen_new[ $hash ] = array(
			'domain'  => $domain,
			'context' => $context,
			'source'  => $text,
		);

		if ( ! self::$shutdown_armed ) {
			self::$shutdown_armed = true;
			add_action( 'shutdown', array( self::class, 'flush_discovered' ), 99 );
		}
	}

	public static function flush_discovered(): void {
		if ( array() === self::$seen_new ) {
			return;
		}
		if ( get_transient( 'cml_strings_flush_lock' ) ) {
			return;
		}
		set_transient( 'cml_strings_flush_lock', 1, self::FLUSH_LOCK_TTL );

		global $wpdb;
		$values       = array();
		$placeholders = array();
		$now          = time();

		foreach ( self::$seen_new as $hash => $info ) {
			$values[]       = $hash;
			$values[]       = $info['domain'];
			$values[]       = $info['context'];
			$values[]       = $info['source'];
			$values[]       = $now;
			$placeholders[] = '(UNHEX(%s), %s, %s, %s, %d)';
		}

		$sql = "INSERT IGNORE INTO {$wpdb->prefix}cml_strings (hash, domain, context, source, created_at) VALUES "
			. implode( ',', $placeholders );

		$wpdb->query( $wpdb->prepare( $sql, ...$values ) );

		// Newly discovered strings join the known set so we don't re-buffer them.
		foreach ( self::$seen_new as $hash => $_ ) {
			if ( null !== self::$known ) {
				self::$known[ $hash ] = true;
			}
		}
		self::$seen_new = array();

		wp_cache_delete( 'known_hashes', self::CACHE_GROUP );
	}

	// ---- Caches ----------------------------------------------------------

	/**
	 * @return array<string, string>
	 */
	private static function compiled_map(): array {
		if ( null !== self::$compiled ) {
			return self::$compiled;
		}

		$code      = CurrentLanguage::code();
		$cache_key = 'compiled_' . $code;

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			self::$compiled = $cached;
			return $cached;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT HEX(s.hash) AS hash, st.translation
				 FROM {$wpdb->prefix}cml_strings s
				 INNER JOIN {$wpdb->prefix}cml_string_translations st ON st.string_id = s.id
				 WHERE st.language = %s",
				$code
			)
		);

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ strtolower( (string) $row->hash ) ] = (string) $row->translation;
			}
		}

		wp_cache_set( $cache_key, $map, self::CACHE_GROUP, self::COMPILED_TTL );
		self::$compiled = $map;
		return $map;
	}

	/**
	 * @return array<string, true>
	 */
	private static function known_set(): array {
		if ( null !== self::$known ) {
			return self::$known;
		}

		$cached = wp_cache_get( 'known_hashes', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			self::$known = $cached;
			return $cached;
		}

		global $wpdb;
		$hex_rows = $wpdb->get_col( "SELECT LOWER(HEX(hash)) FROM {$wpdb->prefix}cml_strings" );

		$set = array();
		if ( is_array( $hex_rows ) ) {
			foreach ( $hex_rows as $h ) {
				$set[ (string) $h ] = true;
			}
		}

		wp_cache_set( 'known_hashes', $set, self::CACHE_GROUP, self::KNOWN_TTL );
		self::$known = $set;
		return $set;
	}

	// ---- Public API ------------------------------------------------------

	public static function hash( string $domain, string $context, string $text ): string {
		return md5( $domain . '|' . $context . '|' . $text );
	}

	/**
	 * Flush all string caches. Call after admin saves / deletes translations.
	 */
	public static function flush_cache(): void {
		self::$compiled = null;
		self::$known    = null;

		wp_cache_delete( 'known_hashes', self::CACHE_GROUP );
		foreach ( Languages::all() as $lang ) {
			wp_cache_delete( 'compiled_' . $lang->code, self::CACHE_GROUP );
		}
	}
}
