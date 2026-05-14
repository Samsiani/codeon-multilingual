<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Compat;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\Settings;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Frontend\LanguageSwitcher;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * WPML compatibility shim — the public API surface that real-world themes/plugins
 * (Astra, GeneratePress, WoodMart, Elementor, YITH suite, Woo extensions) actually
 * consume. Covers the ~90% of WPML usage we see in practice; the rarely-used
 * workflow / package-translation surface is intentionally omitted.
 *
 * Performance: this class registers ~9 filters + 2 actions and requires one
 * function-declarations bootstrap file. Registration cost is ~10 μs at plugin
 * boot — about one filter add per microsecond. The wpml_* filters fire only
 * when WPML-aware third-party code calls apply_filters/do_action with these
 * names, so on sites with no WPML-aware code our callbacks NEVER run — strict
 * zero per-request cost.
 *
 * Self-disabling: if real WPML is installed alongside (it shouldn't be, but
 * defensively), the function_exists('icl_object_id') guard prevents us from
 * redeclaring anything. The whole shim becomes a no-op.
 *
 * Opt-out: Settings['wpml_compat_enabled'] (default true). Admin can disable
 * if the shim ever conflicts with a specific plugin.
 */
final class WpmlFunctions {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! (bool) Settings::get( 'wpml_compat_enabled', true ) ) {
			return;
		}

		// Defensive: if real WPML (or another shim) already declared the canonical
		// function, leave everything alone so we don't fight for the namespace.
		if ( function_exists( 'icl_object_id' ) ) {
			return;
		}

		// Defer function declarations + filter registration to plugins_loaded p5
		// so all plugins (including the real Languages settings) are present and
		// CurrentLanguage has had a chance to be set by Router on p1.
		add_action( 'plugins_loaded', array( self::class, 'install' ), 5 );
	}

	public static function install(): void {
		// Re-check after deferred load — another plugin might have declared it
		// between our boot and plugins_loaded.
		if ( function_exists( 'icl_object_id' ) ) {
			return;
		}

		self::register_global_functions();
		self::register_filters_and_actions();
	}

	private static function register_global_functions(): void {
		require_once __DIR__ . '/wpml-functions-bootstrap.php';
	}

	private static function register_filters_and_actions(): void {
		add_filter( 'wpml_object_id',                array( self::class, 'filter_wpml_object_id' ),                10, 4 );
		add_filter( 'wpml_current_language',         array( self::class, 'filter_wpml_current_language' ),         10, 1 );
		add_filter( 'wpml_default_language',         array( self::class, 'filter_wpml_default_language' ),         10, 1 );
		add_filter( 'wpml_active_languages',         array( self::class, 'filter_wpml_active_languages' ),         10, 2 );
		add_filter( 'wpml_post_language_details',    array( self::class, 'filter_wpml_post_language_details' ),    10, 2 );
		add_filter( 'wpml_element_has_translations', array( self::class, 'filter_wpml_element_has_translations' ), 10, 4 );
		add_filter( 'wpml_translate_single_string',  array( self::class, 'filter_wpml_translate_single_string' ),  10, 4 );

		add_action( 'wpml_register_single_string',   array( self::class, 'action_wpml_register_single_string' ),   10, 4 );
		add_action( 'wpml_switch_language',          array( self::class, 'action_wpml_switch_language' ),          10, 1 );
	}

	// ---- Core element-ID resolver (icl_object_id / wpml_object_id) -----

	/**
	 * Resolve the ID of an element in the requested language.
	 *
	 * @param int       $element_id              Source element ID.
	 * @param string    $element_type            'post', 'page', 'category', 'post_<cpt>', 'tax_<tax>', etc.
	 * @param bool      $return_original_if_missing Return $element_id when no translation exists.
	 * @param string|null $target_language       Defaults to CurrentLanguage::code().
	 */
	public static function resolve_object_id( int $element_id, string $element_type, bool $return_original_if_missing, ?string $target_language ): ?int {
		if ( $element_id <= 0 ) {
			return $return_original_if_missing ? $element_id : null;
		}

		$target = is_string( $target_language ) && '' !== $target_language
			? $target_language
			: CurrentLanguage::code();

		$is_term = self::is_term_element_type( $element_type );

		if ( $is_term ) {
			$group_id = TranslationGroups::get_term_group_id( $element_id );
			if ( null === $group_id ) {
				return $return_original_if_missing ? $element_id : null;
			}
			$siblings = TranslationGroups::get_term_siblings( $group_id );
		} else {
			$group_id = TranslationGroups::get_group_id( $element_id );
			if ( null === $group_id ) {
				return $return_original_if_missing ? $element_id : null;
			}
			$siblings = TranslationGroups::get_siblings( $group_id );
		}

		$found = (int) ( array_search( $target, $siblings, true ) ?: 0 );
		if ( $found > 0 ) {
			return $found;
		}
		return $return_original_if_missing ? $element_id : null;
	}

	/**
	 * WPML accepts element_type as 'post', 'page', 'category', 'post_tag',
	 * 'post_<cpt>', 'tax_<tax>', plus the literal CPT/taxonomy slug. Resolve
	 * which side of the post/term divide it lives on.
	 */
	private static function is_term_element_type( string $type ): bool {
		if ( '' === $type ) {
			return false;
		}

		// Literal core slugs come first — 'post_tag' must not be misread as a
		// post via the 'post_' prefix branch below.
		if ( in_array( $type, array( 'category', 'post_tag', 'nav_menu', 'product_cat', 'product_tag' ), true ) ) {
			return true;
		}
		if ( in_array( $type, array( 'post', 'page', 'attachment', 'nav_menu_item', 'product', 'product_variation' ), true ) ) {
			return false;
		}

		// WPML's tax_/post_ prefix conventions for custom taxonomies/CPTs.
		if ( str_starts_with( $type, 'tax_' ) ) {
			return true;
		}
		if ( str_starts_with( $type, 'post_' ) ) {
			return false;
		}

		// Registered taxonomies win over post types when both happen to exist
		// — taxonomies are queried more often by ID in WPML-aware code.
		if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $type ) ) {
			return true;
		}
		if ( function_exists( 'post_type_exists' ) && post_type_exists( $type ) ) {
			return false;
		}

		return false;
	}

	// ---- Filter callbacks -----------------------------------------------

	/**
	 * @param mixed       $original_id
	 * @param mixed       $element_type
	 * @param mixed       $return_original
	 * @param mixed       $language
	 * @return int|null
	 */
	public static function filter_wpml_object_id( $original_id, $element_type = 'post', $return_original = false, $language = null ): ?int {
		return self::resolve_object_id(
			(int) $original_id,
			is_string( $element_type ) ? $element_type : 'post',
			(bool) $return_original,
			is_string( $language ) ? $language : null
		);
	}

	/**
	 * @param mixed $default
	 */
	public static function filter_wpml_current_language( $default = null ): string {
		return CurrentLanguage::code();
	}

	/**
	 * @param mixed $default
	 */
	public static function filter_wpml_default_language( $default = null ): string {
		return Languages::default_code();
	}

	/**
	 * Build WPML's keyed-by-code array shape from our Languages data.
	 *
	 * @param mixed                $current  Existing value (filter chain input).
	 * @param array<string, mixed> $args
	 * @return array<string, array<string, mixed>>
	 */
	public static function filter_wpml_active_languages( $current = array(), $args = array() ): array {
		unset( $args );

		$out          = array();
		$default_code = Languages::default_code();
		$index        = 1;

		foreach ( Languages::active() as $code => $lang ) {
			$out[ $code ] = array(
				'id'                  => $index++,
				'active'              => 1,
				'native_name'         => (string) $lang->native,
				'major'               => $code === $default_code ? 1 : 0,
				'default_locale'      => (string) $lang->locale,
				'encode_url'          => 0,
				'tag'                 => (string) $code,
				'translated_name'     => (string) $lang->name,
				'language_code'       => (string) $code,
				'country_flag_url'    => '',
				'url'                 => LanguageSwitcher::url_for_language( $code ),
			);
		}

		return $out;
	}

	/**
	 * @param mixed $default
	 * @param mixed $post_id
	 * @return array<string, mixed>|null
	 */
	public static function filter_wpml_post_language_details( $default = null, $post_id = 0 ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return $default;
		}

		$lang_code = TranslationGroups::get_language( $post_id );
		if ( null === $lang_code ) {
			$lang_code = Languages::default_code();
		}

		$lang_obj = Languages::get( $lang_code );
		$current  = CurrentLanguage::code();

		return array(
			'language_code'      => (string) $lang_code,
			'display_name'       => $lang_obj ? (string) $lang_obj->name   : strtoupper( $lang_code ),
			'native_name'        => $lang_obj ? (string) $lang_obj->native : strtoupper( $lang_code ),
			'different_language' => $lang_code !== $current,
			'locale'             => $lang_obj ? (string) $lang_obj->locale : $lang_code,
		);
	}

	/**
	 * @param mixed $default      Boolean fed in by caller.
	 * @param mixed $element_id
	 * @param mixed $element_type
	 * @param mixed $exclude_lang
	 */
	public static function filter_wpml_element_has_translations( $default = false, $element_id = 0, $element_type = 'post', $exclude_lang = null ): bool {
		$element_id = (int) $element_id;
		if ( $element_id <= 0 ) {
			return (bool) $default;
		}

		$type = is_string( $element_type ) ? $element_type : 'post';
		if ( self::is_term_element_type( $type ) ) {
			$group_id = TranslationGroups::get_term_group_id( $element_id );
			$siblings = null === $group_id ? array() : TranslationGroups::get_term_siblings( $group_id );
		} else {
			$group_id = TranslationGroups::get_group_id( $element_id );
			$siblings = null === $group_id ? array() : TranslationGroups::get_siblings( $group_id );
		}

		// Optionally exclude one language (typically the source) from the count.
		if ( is_string( $exclude_lang ) && '' !== $exclude_lang ) {
			$siblings = array_filter( $siblings, static fn( $code ) => $code !== $exclude_lang );
		}

		return count( $siblings ) > 1;
	}

	/**
	 * @param mixed $original_value  The English/source string fed in by caller.
	 * @param mixed $context         WPML 'context' (≈ our domain).
	 * @param mixed $name            Optional identifier (rarely used; ignored).
	 * @param mixed $language        Target language code.
	 */
	public static function filter_wpml_translate_single_string( $original_value, $context = '', $name = '', $language = null ): string {
		$original = (string) $original_value;
		$ctx      = is_string( $context ) ? $context : '';
		$lang     = is_string( $language ) && '' !== $language ? $language : CurrentLanguage::code();

		// We hash by domain|context|source. WPML's 'context' maps to our 'domain'.
		// Look up via StringTranslator's read-side path; on a miss return the source.
		$hash = StringTranslator::hash( $ctx, '', $original );

		global $wpdb;
		$translation = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT st.translation
				 FROM {$wpdb->prefix}cml_strings s
				 INNER JOIN {$wpdb->prefix}cml_string_translations st ON st.string_id = s.id
				 WHERE s.hash = UNHEX(%s) AND st.language = %s
				 LIMIT 1",
				$hash,
				$lang
			)
		);

		return is_string( $translation ) && '' !== $translation ? $translation : $original;
	}

	// ---- Action callbacks ------------------------------------------------

	/**
	 * Register a string for translation. WPML uses this from plugin/theme code
	 * (e.g. Yoast's `Yoast SEO` registering its panel labels).
	 *
	 * @param mixed $context     ≈ our domain
	 * @param mixed $name        identifier (kept on wpml side; not stored separately)
	 * @param mixed $value       source string
	 * @param mixed $allow_empty (unused — we never insert empty sources)
	 */
	public static function action_wpml_register_single_string( $context = '', $name = '', $value = '', $allow_empty = false ): void {
		unset( $name, $allow_empty );

		$domain = (string) $context;
		$source = (string) $value;
		if ( '' === $source ) {
			return;
		}
		if ( strlen( $source ) > 2048 ) {
			return; // Same cap StringTranslator applies.
		}

		$hash = md5( $domain . '||' . $source );

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}cml_strings (hash, domain, context, source, source_language, created_at)
				 VALUES (UNHEX(%s), %s, %s, %s, %s, %d)",
				$hash,
				$domain,
				'',
				$source,
				StringTranslator::detect_source_language( $source ),
				time()
			)
		);
	}

	/**
	 * @param mixed $lang_code
	 */
	public static function action_wpml_switch_language( $lang_code = null ): void {
		if ( ! is_string( $lang_code ) || '' === $lang_code ) {
			return;
		}
		if ( ! Languages::exists_and_active( $lang_code ) ) {
			return;
		}
		CurrentLanguage::set( $lang_code );
	}

	// ---- Shared helpers used by global functions -------------------------

	/**
	 * Return the active languages in WPML's array shape — used by both the
	 * filter and the global icl_get_languages() function.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_languages_wpml_format(): array {
		return self::filter_wpml_active_languages( array() );
	}
}
