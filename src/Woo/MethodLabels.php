<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Translate WooCommerce configured payment/shipping method labels.
 *
 * Many checkout labels are merchant-configured option values, so they do not
 * pass through gettext. We register those finite strings into the CodeOn string
 * catalog when they are encountered, then translate them at runtime using the
 * same request-static compiled map as normal strings.
 */
final class MethodLabels {

	private const DOMAIN_PAYMENT_TITLE       = 'wc-payment-title';
	private const DOMAIN_PAYMENT_DESCRIPTION = 'wc-payment-description';
	private const DOMAIN_SHIPPING_LABEL      = 'wc-shipping-label';

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
	}

	/**
	 * @param mixed $title
	 * @param mixed $gateway_id
	 */
	public static function translate_payment_title( $title, $gateway_id = '' ): string {
		return self::translate_configured_string(
			self::DOMAIN_PAYMENT_TITLE,
			(string) $gateway_id,
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
			(string) $gateway_id,
			(string) $description
		);
	}

	/**
	 * @param mixed $label
	 * @param mixed $rate
	 */
	public static function translate_shipping_label( $label, $rate = null ): string {
		$context = '';
		if ( is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ) {
			$context = (string) $rate->get_method_id();
			if ( method_exists( $rate, 'get_instance_id' ) ) {
				$context .= ':' . (string) $rate->get_instance_id();
			}
		}

		return self::translate_configured_string(
			self::DOMAIN_SHIPPING_LABEL,
			$context,
			(string) $label
		);
	}

	private static function translate_configured_string( string $domain, string $context, string $source ): string {
		if ( '' === $source ) {
			return $source;
		}

		$key = $domain . "\n" . $context . "\n" . $source;
		if ( ! isset( self::$seen[ $key ] ) ) {
			self::$seen[ $key ] = true;
			StringTranslator::register_source( $domain, $context, $source );
		}

		if ( Languages::is_default( CurrentLanguage::code() ) ) {
			return $source;
		}

		$translation = StringTranslator::lookup_translation( $domain, $context, $source );
		return null !== $translation && '' !== $translation ? $translation : $source;
	}
}
