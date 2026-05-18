<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * Persist and reuse the customer's checkout language for WooCommerce orders.
 *
 * Cart pages are request-language aware, but orders live longer than the
 * request that created them. Storing `_cml_language` gives customer emails,
 * order-received, account order views, and later integrations one stable
 * language source.
 */
final class OrderLanguage {

	public const META_LANGUAGE = '_cml_language';

	private static bool $registered = false;

	/** @var array<int, string> order_id => previous request language */
	private static array $language_stack = array();

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'woocommerce_checkout_create_order', array( self::class, 'store_checkout_language' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'store_checkout_language' ), 10, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'translate_order_item_name' ), 10, 4 );

		add_action( 'woocommerce_email_before_order_table', array( self::class, 'begin_order_language' ), 1, 1 );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'end_order_language' ), 999, 1 );
		add_action( 'woocommerce_order_details_before_order_table', array( self::class, 'begin_order_language' ), 1, 1 );
		add_action( 'woocommerce_order_details_after_order_table', array( self::class, 'end_order_language' ), 999, 1 );
	}

	/**
	 * @param mixed $order
	 */
	public static function store_checkout_language( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$lang = CurrentLanguage::code();
		if ( ! Languages::exists_and_active( $lang ) ) {
			$lang = Languages::default_code();
		}

		$order->update_meta_data( self::META_LANGUAGE, $lang );
	}

	/**
	 * @param mixed                $item
	 * @param string               $cart_item_key
	 * @param array<string, mixed> $values
	 * @param mixed                $order
	 */
	public static function translate_order_item_name( $item, string $cart_item_key, array $values, $order ): void {
		unset( $cart_item_key, $order );
		if ( ! is_object( $item ) || ! method_exists( $item, 'set_name' ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product_id = isset( $values['variation_id'] ) && (int) $values['variation_id'] > 0
			? (int) $values['variation_id']
			: (int) ( $values['product_id'] ?? 0 );
		if ( $product_id <= 0 ) {
			return;
		}

		$translated_id = CartTranslation::translated_product_id( $product_id );
		if ( $translated_id <= 0 ) {
			return;
		}

		$product = wc_get_product( $translated_id );
		if ( is_object( $product ) && method_exists( $product, 'get_name' ) ) {
			$item->set_name( (string) $product->get_name() );
		}
	}

	/**
	 * @param mixed $order
	 */
	public static function begin_order_language( $order ): void {
		$lang = self::order_language( $order );
		if ( null === $lang ) {
			return;
		}

		$order_id = self::order_id( $order );
		if ( $order_id <= 0 ) {
			return;
		}

		self::$language_stack[ $order_id ] = CurrentLanguage::code();
		CurrentLanguage::set( $lang );
	}

	/**
	 * @param mixed $order
	 */
	public static function end_order_language( $order ): void {
		$order_id = self::order_id( $order );
		if ( $order_id <= 0 || ! isset( self::$language_stack[ $order_id ] ) ) {
			return;
		}

		CurrentLanguage::set( self::$language_stack[ $order_id ] );
		unset( self::$language_stack[ $order_id ] );
	}

	/**
	 * @param mixed $order
	 */
	public static function order_language( $order ): ?string {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return null;
		}

		$lang = (string) $order->get_meta( self::META_LANGUAGE, true );
		return Languages::exists_and_active( $lang ) ? $lang : null;
	}

	/**
	 * @param mixed $order
	 */
	private static function order_id( $order ): int {
		return is_object( $order ) && method_exists( $order, 'get_id' )
			? (int) $order->get_id()
			: 0;
	}
}
