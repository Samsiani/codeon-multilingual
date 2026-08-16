<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;

/**
 * Static front page and posts page, per language.
 *
 * A site using "A static page" for its front page stores one post id in
 * `page_on_front`. WordPress resolves `/` — and, with a language prefix,
 * `/en/` and `/ru/` — straight to that id, and `PostsClauses::should_skip()`
 * deliberately bypasses language scoping for explicit `page_id` lookups.
 *
 * The net effect is that every language renders the default language's home
 * page: translated sub-pages work, the home page silently does not. This maps
 * both options onto the current language's sibling, which is the same
 * treatment `Woo\PageMapping` gives WooCommerce's shop/cart/checkout pages.
 *
 * Falls through to the original id when no translation exists or the sibling
 * is not published, so a partially translated site keeps a working home page.
 */
final class FrontPageMapping {

	private const OPTIONS = array( 'page_on_front', 'page_for_posts' );

	private static bool $registered = false;

	/** @var array<string, int> */
	private static array $cache = array();

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		foreach ( self::OPTIONS as $option ) {
			add_filter( 'option_' . $option, array( self::class, 'translate_option' ), 10, 1 );
		}
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function translate_option( $value ) {
		$id = (int) $value;
		if ( $id <= 0 ) {
			return $value;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			// The admin must keep seeing the real setting, or saving Reading
			// Settings would rewrite the front page to a translation.
			return $value;
		}

		$translated = self::translate( $id );
		return $translated > 0 ? $translated : $value;
	}

	public static function translate( int $page_id ): int {
		if ( $page_id <= 0 ) {
			return 0;
		}

		$lang = CurrentLanguage::code();
		if ( Languages::is_default( $lang ) ) {
			return $page_id;
		}

		$key = $page_id . '|' . $lang;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$group_id = TranslationGroups::get_group_id( $page_id );
		if ( null === $group_id ) {
			self::$cache[ $key ] = $page_id;
			return $page_id;
		}

		$siblings   = TranslationGroups::get_siblings( $group_id );
		$translated = (int) ( array_search( $lang, $siblings, true ) ?: 0 );

		if ( $translated <= 0 || 'publish' !== get_post_status( $translated ) ) {
			self::$cache[ $key ] = $page_id;
			return $page_id;
		}

		self::$cache[ $key ] = $translated;
		return $translated;
	}
}
