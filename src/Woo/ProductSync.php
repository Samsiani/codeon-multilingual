<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Core\TranslationGroups;

/**
 * Fans non-translatable product props across the translation group.
 *
 * One hook covers admin saves, programmatic price/stock updates, and order-time
 * stock decrement — they all flow through woocommerce_product_object_updated_props
 * when WC saves the product object. Variations inherit the hook from WC_Product.
 *
 * Recursion is guarded by an in-request lock keyed on group_id (variations and
 * products live in separate groups, so locking on the product never blocks its
 * variations and vice versa).
 *
 * Per-language props (title, slug, description, short description) are never
 * touched. Currency-scoped pricing is intentionally NOT supported here — all
 * translations of a product share one price (multi-currency is a separate v0.4
 * module that lives outside this sync).
 */
final class ProductSync {

	/** WC getter/setter names of props synced across translations. */
	private const SYNCED_PROPS = array(
		'price',
		'regular_price',
		'sale_price',
		'date_on_sale_from',
		'date_on_sale_to',
		'stock_quantity',
		'stock_status',
		'manage_stock',
		'backorders',
		'sold_individually',
		'low_stock_amount',
		'weight',
		'length',
		'width',
		'height',
		'tax_class',
		'tax_status',
		'downloadable',
		'virtual',
		'sku',
		'global_unique_id',
		'shipping_class_id',
	);

	/** @var array<int, true> group_id => lock present */
	private static array $active_locks = array();

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'woocommerce_product_object_updated_props', array( self::class, 'on_props_updated' ), 10, 2 );
	}

	/**
	 * @param mixed              $product
	 * @param array<int, string> $updated_props
	 */
	public static function on_props_updated( $product, $updated_props ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		if ( ! is_array( $updated_props ) || array() === $updated_props ) {
			return;
		}

		$synced_props = array_values( array_intersect( $updated_props, self::synced_props() ) );
		if ( array() === $synced_props ) {
			return;
		}

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return;
		}

		$group_id = TranslationGroups::get_group_id( $product_id );
		if ( null === $group_id ) {
			return;
		}

		if ( isset( self::$active_locks[ $group_id ] ) ) {
			return;
		}
		self::$active_locks[ $group_id ] = true;

		try {
			$siblings = TranslationGroups::get_siblings( $group_id );
			foreach ( $siblings as $sibling_id => $lang ) {
				if ( $sibling_id === $product_id ) {
					continue;
				}
				self::sync_to_sibling( $product, $sibling_id, $synced_props );
			}
		} finally {
			unset( self::$active_locks[ $group_id ] );
		}
	}

	/**
	 * @param mixed             $source
	 * @param array<int,string> $props
	 */
	private static function sync_to_sibling( $source, int $sibling_id, array $props ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$sibling = wc_get_product( $sibling_id );
		if ( ! is_object( $sibling ) ) {
			return;
		}

		$dirty = false;
		foreach ( $props as $prop ) {
			$getter = 'get_' . $prop;
			$setter = 'set_' . $prop;
			if ( ! method_exists( $source, $getter ) || ! method_exists( $sibling, $setter ) ) {
				continue;
			}

			$value = $source->{$getter}();
			$sibling->{$setter}( $value );
			$dirty = true;
		}

		if ( $dirty ) {
			$sibling->save();
		}
	}

	/**
	 * @return array<int, string>
	 */
	private static function synced_props(): array {
		/** @var array<int, string> */
		return (array) apply_filters( 'cml_woo_synced_props', self::SYNCED_PROPS );
	}
}
