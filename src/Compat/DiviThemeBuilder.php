<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Compat;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;

/**
 * Divi Theme Builder layouts, per language.
 *
 * Divi's Theme Builder keeps the header, body and footer of a template in
 * separate posts (`et_header_layout`, `et_body_layout`, `et_footer_layout`)
 * and resolves them once per request. Those posts are content like any other,
 * but Divi looks them up by id, so language scoping never applies and every
 * language renders the default language's header and footer — a site can have
 * fully translated pages and still show a Georgian footer on /en/.
 *
 * Divi exposes `et_theme_builder_template_layouts` after resolution, which is
 * the natural seam: swap each layout id for its sibling in the current
 * language, leaving Divi's own template-matching logic untouched. This is the
 * same mapping `Woo\PageMapping` performs for WooCommerce page options.
 *
 * Untranslated layouts fall through to the original id, matching the
 * untranslated-content fallback used elsewhere, so a partially translated site
 * degrades to the source language instead of rendering nothing.
 */
final class DiviThemeBuilder {

	private static bool $registered = false;

	/** @var array<string, int> */
	private static array $cache = array();

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'et_theme_builder_template_layouts', array( self::class, 'translate_layouts' ), 10, 1 );
	}

	/**
	 * Divi hands over a map of layout type => layout descriptor, each carrying
	 * at least an `id` and an `enabled` flag.
	 *
	 * @param mixed $layouts
	 * @return mixed
	 */
	public static function translate_layouts( $layouts ) {
		if ( ! is_array( $layouts ) || array() === $layouts ) {
			return $layouts;
		}
		if ( Languages::is_default( CurrentLanguage::code() ) ) {
			return $layouts;
		}

		foreach ( $layouts as $type => $layout ) {
			if ( ! is_array( $layout ) || empty( $layout['id'] ) ) {
				continue;
			}
			$translated = self::translate_id( (int) $layout['id'] );
			if ( $translated > 0 && $translated !== (int) $layout['id'] ) {
				$layouts[ $type ]['id'] = $translated;
			}
		}

		return $layouts;
	}

	/**
	 * Resolve one layout id into the current language, or return it unchanged
	 * when there is no sibling to switch to.
	 */
	public static function translate_id( int $layout_id ): int {
		if ( $layout_id <= 0 ) {
			return 0;
		}

		$lang = CurrentLanguage::code();
		$key  = $layout_id . '|' . $lang;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$group_id = TranslationGroups::get_group_id( $layout_id );
		if ( null === $group_id ) {
			self::$cache[ $key ] = $layout_id;
			return $layout_id;
		}

		$siblings   = TranslationGroups::get_siblings( $group_id );
		$translated = (int) ( array_search( $lang, $siblings, true ) ?: 0 );

		if ( $translated <= 0 || 'publish' !== get_post_status( $translated ) ) {
			self::$cache[ $key ] = $layout_id;
			return $layout_id;
		}

		self::$cache[ $key ] = $translated;
		return $translated;
	}
}
