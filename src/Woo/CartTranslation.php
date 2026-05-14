<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;

/**
 * Render cart items in the current request language.
 *
 * Cart contents persist across language switches: the visitor adds Product A
 * (Georgian, id 10) to the cart, switches to /ru/, and we want the cart to
 * show Product A's Russian translation (id 11) — title, link, image, all in
 * Russian. When no sibling exists for the current language, the item stays
 * in its original language (per the user's spec).
 *
 * The data layer (price, stock, etc.) is identical across the group thanks
 * to `Woo/ProductSync`, so swapping the product object only affects display
 * fields. `woocommerce_cart_item_product` is the canonical filter — every
 * cart template renders `$product = $cart_item['data']` and then uses that
 * for title/link/image, so a single swap covers shop cart, mini-cart,
 * checkout review, order-received, and emails.
 */
final class CartTranslation {

	/** @var array<string, int> cache key (product_id|lang) → translated id */
	private static array $cache = array();

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Swap the WC_Product object handed to templates so title / image /
		// permalink / attributes all reflect the translation.
		add_filter( 'woocommerce_cart_item_product', array( self::class, 'translate_product' ), 10, 3 );
		// Some themes / extensions render the link via this dedicated filter
		// instead of inferring from the product object.
		add_filter( 'woocommerce_cart_item_permalink', array( self::class, 'translate_permalink' ), 10, 3 );
	}

	/**
	 * @param mixed              $product_obj
	 * @param array<string,mixed> $cart_item
	 * @param string             $cart_item_key
	 * @return mixed
	 */
	public static function translate_product( $product_obj, $cart_item, $cart_item_key ) {
		if ( ! is_object( $product_obj ) || ! method_exists( $product_obj, 'get_id' ) ) {
			return $product_obj;
		}
		$translated_id = self::translated_product_id( (int) $product_obj->get_id() );
		if ( $translated_id <= 0 || $translated_id === (int) $product_obj->get_id() ) {
			return $product_obj;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $product_obj;
		}
		$translated = wc_get_product( $translated_id );
		return $translated ? $translated : $product_obj;
	}

	/**
	 * @param string             $permalink
	 * @param array<string,mixed> $cart_item
	 * @param string             $cart_item_key
	 */
	public static function translate_permalink( $permalink, $cart_item, $cart_item_key ): string {
		$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		if ( $product_id <= 0 ) {
			return (string) $permalink;
		}
		$translated_id = self::translated_product_id( $product_id );
		if ( $translated_id <= 0 || $translated_id === $product_id ) {
			return (string) $permalink;
		}
		$link = get_permalink( $translated_id );
		return $link ? $link : (string) $permalink;
	}

	/**
	 * Find a sibling of $product_id in the current language. Returns 0 when
	 * no translation exists — caller falls back to the original product.
	 */
	public static function translated_product_id( int $product_id ): int {
		if ( $product_id <= 0 ) {
			return 0;
		}
		$lang = CurrentLanguage::code();
		if ( Languages::is_default( $lang ) ) {
			// In default-language requests the product's own language might
			// already differ (e.g. Georgian default but cart has English
			// product), so we still attempt a sibling lookup.
			$current_lang = TranslationGroups::get_language( $product_id );
			if ( $current_lang === $lang ) {
				return $product_id;
			}
		}
		$key = $product_id . '|' . $lang;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$group_id = TranslationGroups::get_group_id( $product_id );
		if ( null === $group_id ) {
			self::$cache[ $key ] = $product_id;
			return $product_id;
		}
		$siblings = TranslationGroups::get_siblings( $group_id );
		$sibling  = (int) ( array_search( $lang, $siblings, true ) ?: 0 );
		if ( $sibling <= 0 ) {
			// No translation in current language → leave item as-is.
			self::$cache[ $key ] = $product_id;
			return $product_id;
		}
		self::$cache[ $key ] = $sibling;
		return $sibling;
	}
}
