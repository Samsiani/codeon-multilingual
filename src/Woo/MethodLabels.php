<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Translate WooCommerce configured labels and runtime customer strings.
 *
 * Many checkout/order labels are merchant-configured option values, stored
 * order snapshots, or final notice strings, so they do not reliably pass
 * through gettext. We register those finite strings into the CodeOn string
 * catalog when they are encountered, then translate them at runtime using the
 * same request-static compiled map as normal strings.
 */
final class MethodLabels {

	private const DOMAIN_PAYMENT_TITLE       = 'wc-payment-title';
	private const DOMAIN_PAYMENT_DESCRIPTION = 'wc-payment-description';
	private const DOMAIN_SHIPPING_LABEL      = 'wc-shipping-label';
	private const DOMAIN_NOTICE              = 'wc-notice';
	private const DOMAIN_COUPON_LABEL        = 'wc-coupon-label';
	private const DOMAIN_COUPON_DESCRIPTION  = 'wc-coupon-description';
	private const DOMAIN_ORDER_STATUS        = 'wc-order-status';
	private const DOMAIN_DOWNLOAD_NAME       = 'wc-download-name';
	private const DOMAIN_DOWNLOAD_FILENAME   = 'wc-download-filename';

	private static bool $registered = false;

	/** @var array<string, true> */
	private static array $seen = array();

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'woocommerce_gateway_title', array( self::class, 'translate_payment_title' ), 10, 2 );
		add_filter( 'woocommerce_gateway_description', array( self::class, 'translate_payment_description' ), 10, 2 );
		add_filter( 'woocommerce_shipping_rate_label', array( self::class, 'translate_shipping_label' ), 10, 2 );
		add_filter( 'woocommerce_order_get_payment_method_title', array( self::class, 'translate_order_payment_method_title' ), 10, 2 );
		add_filter( 'woocommerce_order_item_get_method_title', array( self::class, 'translate_order_shipping_method_title' ), 10, 2 );

		add_filter( 'woocommerce_add_message', array( self::class, 'translate_success_notice' ), 10, 1 );
		add_filter( 'woocommerce_add_error', array( self::class, 'translate_error_notice' ), 10, 1 );
		add_filter( 'woocommerce_add_notice', array( self::class, 'translate_info_notice' ), 10, 1 );

		add_filter( 'woocommerce_cart_totals_coupon_label', array( self::class, 'translate_coupon_label' ), 10, 2 );
		add_filter( 'woocommerce_coupon_get_description', array( self::class, 'translate_coupon_description' ), 10, 2 );
		add_filter( 'wc_order_statuses', array( self::class, 'translate_order_statuses' ), 10, 1 );

		add_filter( 'woocommerce_product_get_downloads', array( self::class, 'translate_product_downloads' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_downloads', array( self::class, 'translate_product_downloads' ), 10, 2 );
		add_filter( 'woocommerce_order_get_downloadable_items', array( self::class, 'translate_order_downloadable_items' ), 10, 2 );
		add_filter( 'woocommerce_file_download_filename', array( self::class, 'translate_download_filename' ), 10, 2 );
	}

	/**
	 * @param mixed $title
	 * @param mixed $gateway_id
	 */
	public static function translate_payment_title( $title, $gateway_id = '' ): string {
		return self::translate_configured_string(
			self::DOMAIN_PAYMENT_TITLE,
			self::payment_context( $gateway_id ),
			(string) $title
		);
	}

	/**
	 * @param mixed $description
	 * @param mixed $gateway_id
	 */
	public static function translate_payment_description( $description, $gateway_id = '' ): string {
		return self::translate_configured_string(
			self::DOMAIN_PAYMENT_DESCRIPTION,
			self::payment_context( $gateway_id ),
			(string) $description
		);
	}

	/**
	 * @param mixed $label
	 * @param mixed $rate
	 */
	public static function translate_shipping_label( $label, $rate = null ): string {
		return self::translate_configured_string_with_contexts(
			self::DOMAIN_SHIPPING_LABEL,
			self::shipping_contexts( $rate ),
			(string) $label
		);
	}

	/**
	 * @param mixed $title
	 * @param mixed $order
	 */
	public static function translate_order_payment_method_title( $title, $order = null ): string {
		return self::translate_configured_string(
			self::DOMAIN_PAYMENT_TITLE,
			self::order_payment_context( $order ),
			(string) $title
		);
	}

	/**
	 * @param mixed $title
	 * @param mixed $item
	 */
	public static function translate_order_shipping_method_title( $title, $item = null ): string {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_type' ) || 'shipping' !== (string) $item->get_type() ) {
			return (string) $title;
		}

		return self::translate_configured_string_with_contexts(
			self::DOMAIN_SHIPPING_LABEL,
			self::shipping_contexts( $item ),
			(string) $title
		);
	}

	/**
	 * @param mixed $message
	 */
	public static function translate_success_notice( $message ): string {
		return self::translate_notice( $message, 'success' );
	}

	/**
	 * @param mixed $message
	 */
	public static function translate_error_notice( $message ): string {
		return self::translate_notice( $message, 'error' );
	}

	/**
	 * @param mixed $message
	 */
	public static function translate_info_notice( $message ): string {
		return self::translate_notice( $message, 'notice' );
	}

	/**
	 * @param mixed $message
	 */
	public static function translate_notice( $message, string $type = 'notice' ): string {
		return self::translate_configured_string(
			self::DOMAIN_NOTICE,
			$type,
			(string) $message
		);
	}

	/**
	 * @param mixed $label
	 * @param mixed $coupon
	 */
	public static function translate_coupon_label( $label, $coupon = null ): string {
		return self::translate_configured_string(
			self::DOMAIN_COUPON_LABEL,
			self::coupon_context( $coupon ),
			(string) $label
		);
	}

	/**
	 * @param mixed $description
	 * @param mixed $coupon
	 */
	public static function translate_coupon_description( $description, $coupon = null ): string {
		return self::translate_configured_string(
			self::DOMAIN_COUPON_DESCRIPTION,
			self::coupon_context( $coupon ),
			(string) $description
		);
	}

	/**
	 * @param mixed $statuses
	 * @return mixed
	 */
	public static function translate_order_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}

		foreach ( $statuses as $status => $label ) {
			if ( ! is_scalar( $label ) ) {
				continue;
			}
			$statuses[ $status ] = self::translate_configured_string(
				self::DOMAIN_ORDER_STATUS,
				(string) $status,
				(string) $label
			);
		}

		return $statuses;
	}

	/**
	 * @param mixed $downloads
	 * @param mixed $product
	 * @return mixed
	 */
	public static function translate_product_downloads( $downloads, $product = null ) {
		if ( ! is_array( $downloads ) ) {
			return $downloads;
		}

		$product_id = self::object_id( $product );
		foreach ( $downloads as $key => $download ) {
			$name = self::download_name( $download );
			if ( '' === $name ) {
				continue;
			}

			$context    = self::download_context( $product_id, self::download_id( $download, $key ) );
			$translated = self::translate_configured_string( self::DOMAIN_DOWNLOAD_NAME, $context, $name );
			if ( $translated === $name ) {
				continue;
			}

			$downloads[ $key ] = self::with_download_name( $download, $translated );
		}

		return $downloads;
	}

	/**
	 * @param mixed $downloads
	 * @param mixed $order
	 * @return mixed
	 */
	public static function translate_order_downloadable_items( $downloads, $order = null ) {
		unset( $order );
		if ( ! is_array( $downloads ) ) {
			return $downloads;
		}

		foreach ( $downloads as $key => $download ) {
			if ( ! is_array( $download ) ) {
				continue;
			}
			$name = isset( $download['download_name'] ) && is_scalar( $download['download_name'] )
				? (string) $download['download_name']
				: '';
			if ( '' === $name ) {
				continue;
			}

			$product_id  = isset( $download['product_id'] ) ? (int) $download['product_id'] : 0;
			$download_id = isset( $download['download_id'] ) && is_scalar( $download['download_id'] )
				? (string) $download['download_id']
				: (string) $key;
			$translated = self::translate_configured_string(
				self::DOMAIN_DOWNLOAD_NAME,
				self::download_context( $product_id, $download_id ),
				$name
			);

			$downloads[ $key ]['download_name'] = $translated;
			if ( isset( $downloads[ $key ]['file'] ) && is_array( $downloads[ $key ]['file'] ) ) {
				$downloads[ $key ]['file']['name'] = $translated;
			}
		}

		return $downloads;
	}

	/**
	 * @param mixed $filename
	 * @param mixed $product_id
	 */
	public static function translate_download_filename( $filename, $product_id = 0 ): string {
		return self::translate_configured_string(
			self::DOMAIN_DOWNLOAD_FILENAME,
			(string) (int) $product_id,
			(string) $filename
		);
	}

	private static function translate_configured_string( string $domain, string $context, string $source ): string {
		return self::translate_configured_string_with_contexts( $domain, array( $context ), $source );
	}

	/**
	 * @param array<int, string> $contexts
	 */
	private static function translate_configured_string_with_contexts( string $domain, array $contexts, string $source ): string {
		if ( '' === $source ) {
			return $source;
		}

		$contexts = self::unique_contexts( $contexts );
		foreach ( $contexts as $context ) {
			$key = $domain . "\n" . $context . "\n" . $source;
			if ( ! isset( self::$seen[ $key ] ) ) {
				self::$seen[ $key ] = true;
				StringTranslator::register_source( $domain, $context, $source );
			}
		}

		if ( Languages::is_default( CurrentLanguage::code() ) ) {
			return $source;
		}

		foreach ( $contexts as $context ) {
			$translation = StringTranslator::lookup_translation( $domain, $context, $source );
			if ( null !== $translation && '' !== $translation ) {
				return $translation;
			}
		}

		return $source;
	}

	/**
	 * @param array<int, string> $contexts
	 * @return array<int, string>
	 */
	private static function unique_contexts( array $contexts ): array {
		$unique = array();
		foreach ( $contexts as $context ) {
			if ( ! in_array( $context, $unique, true ) ) {
				$unique[] = $context;
			}
		}
		return array() === $unique ? array( '' ) : $unique;
	}

	/**
	 * @param mixed $gateway
	 */
	private static function payment_context( $gateway ): string {
		if ( is_object( $gateway ) ) {
			if ( isset( $gateway->id ) && is_scalar( $gateway->id ) ) {
				return (string) $gateway->id;
			}
			if ( method_exists( $gateway, 'get_id' ) ) {
				$id = $gateway->get_id();
				return is_scalar( $id ) ? (string) $id : '';
			}
			return '';
		}

		return is_scalar( $gateway ) ? (string) $gateway : '';
	}

	/**
	 * @param mixed $order
	 */
	private static function order_payment_context( $order ): string {
		if ( is_object( $order ) && method_exists( $order, 'get_payment_method' ) ) {
			$method = $order->get_payment_method();
			return is_scalar( $method ) ? (string) $method : '';
		}
		return '';
	}

	/**
	 * @param mixed $rate
	 * @return array<int, string>
	 */
	private static function shipping_contexts( $rate ): array {
		if ( ! is_object( $rate ) ) {
			return array( '' );
		}

		$method_id   = self::object_scalar_method( $rate, 'get_method_id' );
		$instance_id = self::object_scalar_method( $rate, 'get_instance_id' );
		$rate_id     = self::object_scalar_method( $rate, 'get_id' );
		$contexts    = array();

		if ( '' !== $method_id && '' !== $instance_id && '0' !== $instance_id ) {
			$contexts[] = $method_id . ':' . $instance_id;
		}
		if ( '' !== $rate_id ) {
			$contexts[] = $rate_id;
		}
		if ( '' !== $method_id ) {
			$contexts[] = $method_id;
		}
		$contexts[] = '';

		return self::unique_contexts( $contexts );
	}

	/**
	 * @param mixed $coupon
	 */
	private static function coupon_context( $coupon ): string {
		if ( is_object( $coupon ) && method_exists( $coupon, 'get_code' ) ) {
			$code = $coupon->get_code();
			if ( is_scalar( $code ) && '' !== (string) $code ) {
				return 'code:' . (string) $code;
			}
		}
		if ( is_object( $coupon ) && method_exists( $coupon, 'get_id' ) ) {
			$id = $coupon->get_id();
			if ( is_scalar( $id ) && (int) $id > 0 ) {
				return 'id:' . (string) (int) $id;
			}
		}
		if ( is_scalar( $coupon ) && '' !== (string) $coupon ) {
			return 'code:' . (string) $coupon;
		}
		return '';
	}

	/**
	 * @param mixed $object
	 */
	private static function object_id( $object ): int {
		if ( is_object( $object ) && method_exists( $object, 'get_id' ) ) {
			$id = $object->get_id();
			return is_scalar( $id ) ? (int) $id : 0;
		}
		return 0;
	}

	/**
	 * @param mixed $object
	 */
	private static function object_scalar_method( $object, string $method ): string {
		if ( is_object( $object ) && method_exists( $object, $method ) ) {
			$value = $object->{$method}();
			return is_scalar( $value ) ? (string) $value : '';
		}
		return '';
	}

	/**
	 * @param mixed $download
	 */
	private static function download_name( $download ): string {
		if ( is_object( $download ) && method_exists( $download, 'get_name' ) ) {
			$name = $download->get_name();
			return is_scalar( $name ) ? (string) $name : '';
		}
		if ( is_array( $download ) && isset( $download['name'] ) && is_scalar( $download['name'] ) ) {
			return (string) $download['name'];
		}
		if ( $download instanceof \ArrayAccess && isset( $download['name'] ) && is_scalar( $download['name'] ) ) {
			return (string) $download['name'];
		}
		return '';
	}

	/**
	 * @param mixed $download
	 * @param mixed $fallback
	 */
	private static function download_id( $download, $fallback ): string {
		if ( is_object( $download ) && method_exists( $download, 'get_id' ) ) {
			$id = $download->get_id();
			return is_scalar( $id ) ? (string) $id : (string) $fallback;
		}
		if ( is_array( $download ) && isset( $download['id'] ) && is_scalar( $download['id'] ) ) {
			return (string) $download['id'];
		}
		if ( $download instanceof \ArrayAccess && isset( $download['id'] ) && is_scalar( $download['id'] ) ) {
			return (string) $download['id'];
		}
		return is_scalar( $fallback ) ? (string) $fallback : '';
	}

	private static function download_context( int $product_id, string $download_id ): string {
		if ( $product_id > 0 && '' !== $download_id ) {
			return $product_id . ':' . $download_id;
		}
		if ( $product_id > 0 ) {
			return (string) $product_id;
		}
		return $download_id;
	}

	/**
	 * @param mixed $download
	 * @return mixed
	 */
	private static function with_download_name( $download, string $name ) {
		if ( is_object( $download ) && method_exists( $download, 'set_name' ) ) {
			$copy = clone $download;
			$copy->set_name( $name );
			return $copy;
		}
		if ( is_array( $download ) ) {
			$download['name'] = $name;
			return $download;
		}
		if ( $download instanceof \ArrayAccess ) {
			$copy         = clone $download;
			$copy['name'] = $name;
			return $copy;
		}

		return $download;
	}
}
