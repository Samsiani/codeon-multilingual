<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;

/**
 * Map WooCommerce shop-page options to their translation in the current language.
 *
 * WC stores cart/checkout/my-account/shop/terms as `woocommerce_<slug>_page_id`
 * options pointing to a single post ID. `wc_get_page_id('cart')` and friends
 * return that ID regardless of the request's language, so `/en/cart/` resolves
 * to the English cart page in WP_Query but WC's `is_cart()` still compares
 * against the Georgian original — mismatch, the cart shortcode/block never
 * fires, the page renders as a generic page (404 if no content) instead of
 * the cart.
 *
 * Fix: filter every `option_woocommerce_<slug>_page_id` to return the
 * translation that matches the current language. Default-language requests
 * are pass-through, so other plugins / themes / WC core see the original ID
 * exactly as before.
 *
 * Cache: per-request memoisation keyed by (option, language) means each
 * filter pays at most one TranslationGroups lookup per request.
 */
final class PageMapping {

	/** WC option names that store a shop-page post ID. */
	private const PAGE_OPTIONS = array(
		'woocommerce_shop_page_id',
		'woocommerce_cart_page_id',
		'woocommerce_checkout_page_id',
		'woocommerce_myaccount_page_id',
		'woocommerce_terms_page_id',
		'woocommerce_pay_page_id',
		'woocommerce_view_order_page_id',
		// WC < 3.x had a thanks page; modern WC routes /order-received/ through
		// the checkout endpoint. Listed for completeness.
		'woocommerce_thanks_page_id',
	);

	/** @var array<string, int> cache key (option|lang) → translated id */
	private static array $cache = array();

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		foreach ( self::PAGE_OPTIONS as $option ) {
			add_filter( 'option_' . $option, array( self::class, 'translate_page_id' ), 10 );
			// Pre-WC fires `pre_option_*` too; honour that path so a cached
			// alloptions hit doesn't bypass us.
			add_filter( 'pre_option_' . $option, array( self::class, 'translate_page_id_pre' ), 10, 3 );
		}
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function translate_page_id( $value ) {
		$id = self::translate( (int) $value );
		return $id > 0 ? $id : $value;
	}

	/**
	 * `pre_option_*` returns the value when non-false; if WC has cached the
	 * page id in alloptions our `option_*` filter still runs, but we wire
	 * both for symmetry.
	 *
	 * @param mixed  $pre
	 * @param string $option
	 * @param mixed  $default_value
	 * @return mixed
	 */
	public static function translate_page_id_pre( $pre, $option, $default_value ) {
		// Don't short-circuit when WP wants the real value; pre_option must
		// return false to fall through to the real lookup.
		return $pre;
	}

	/**
	 * Resolve the translation of a WC page ID for the current request language.
	 * Returns 0 when the input is invalid or when no translation should apply.
	 */
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
		if ( $translated <= 0 ) {
			self::$cache[ $key ] = $page_id;
			return $page_id;
		}
		self::$cache[ $key ] = $translated;
		return $translated;
	}
}
