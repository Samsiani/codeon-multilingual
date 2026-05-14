<?php
/**
 * WPML global functions — declared in the root namespace so WPML-aware
 * themes/plugins can call them without modification.
 *
 * Loaded once by WpmlFunctions::install() on plugins_loaded p5. Every
 * declaration is guarded by function_exists() so a real WPML install (if one
 * is somehow active alongside us) wins the namespace and avoids fatal errors.
 *
 * Each function is a thin adapter delegating to WpmlFunctions:: static
 * methods. The cost of an undeclared call is one symbol-table lookup
 * (~50 ns); the cost of a called function is ~5 μs including the namespaced
 * static call.
 */

defined( 'ABSPATH' ) || exit;

use Samsiani\CodeonMultilingual\Compat\WpmlFunctions;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;

if ( ! function_exists( 'icl_object_id' ) ) {
	/**
	 * Resolve element ID in the requested language.
	 *
	 * @param int         $element_id
	 * @param string      $element_type                  'post' | 'page' | 'category' | 'post_<cpt>' | 'tax_<tax>' | …
	 * @param bool        $return_original_if_missing
	 * @param string|null $ulanguage_code                Defaults to current language.
	 * @return int|null
	 */
	function icl_object_id( $element_id, $element_type = 'post', $return_original_if_missing = false, $ulanguage_code = null ) {
		return WpmlFunctions::resolve_object_id(
			(int) $element_id,
			is_string( $element_type ) ? $element_type : 'post',
			(bool) $return_original_if_missing,
			is_string( $ulanguage_code ) ? $ulanguage_code : null
		);
	}
}

if ( ! function_exists( 'icl_get_languages' ) ) {
	/**
	 * @param string|array<string, mixed> $args Query-string or array of options (unused — we return all active).
	 * @return array<string, array<string, mixed>>
	 */
	function icl_get_languages( $args = '' ) {
		unset( $args );
		return WpmlFunctions::get_languages_wpml_format();
	}
}

if ( ! function_exists( 'icl_get_default_language' ) ) {
	function icl_get_default_language(): string {
		return Languages::default_code();
	}
}

if ( ! function_exists( 'icl_get_current_language' ) ) {
	function icl_get_current_language(): string {
		return CurrentLanguage::code();
	}
}
