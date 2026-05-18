<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

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

	private const DOMAIN_EMAIL_SUBJECT            = 'wc-email-subject';
	private const DOMAIN_EMAIL_HEADING            = 'wc-email-heading';
	private const DOMAIN_EMAIL_ADDITIONAL_CONTENT = 'wc-email-additional-content';

	private static bool $registered = false;

	/** @var array<int, list<string>> order_id => previous request language stack */
	private static array $language_stack = array();

	/** @var array<string, true> */
	private static array $email_filters = array();

	/** @var array<string, true> */
	private static array $email_actions = array();

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

		add_filter( 'woocommerce_email_classes', array( self::class, 'register_email_string_filters' ), 20, 1 );
		add_action( 'woocommerce_email', array( self::class, 'register_email_string_filters_action' ), 20, 1 );
		add_filter( 'woocommerce_email_actions', array( self::class, 'register_transactional_email_actions' ), 20, 1 );
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

		self::$language_stack[ $order_id ][] = CurrentLanguage::code();
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

		$previous = array_pop( self::$language_stack[ $order_id ] );
		if ( array() === self::$language_stack[ $order_id ] ) {
			unset( self::$language_stack[ $order_id ] );
		}
		CurrentLanguage::set( (string) $previous );
	}

	/**
	 * @param mixed $emails WC_Emails instance or email-class array.
	 * @return mixed
	 */
	public static function register_email_string_filters( $emails ) {
		$email_objects = array();
		if ( is_array( $emails ) ) {
			$email_objects = $emails;
		} elseif ( is_object( $emails ) && method_exists( $emails, 'get_emails' ) ) {
			$email_objects = $emails->get_emails();
		}

		if ( is_array( $email_objects ) ) {
			foreach ( $email_objects as $email ) {
				$id = self::email_id( $email );
				if ( '' === $id || isset( self::$email_filters[ $id ] ) ) {
					continue;
				}

				self::$email_filters[ $id ] = true;
				add_filter( 'woocommerce_email_subject_' . $id, array( self::class, 'translate_email_subject' ), 10, 3 );
				add_filter( 'woocommerce_email_heading_' . $id, array( self::class, 'translate_email_heading' ), 10, 3 );
				add_filter( 'woocommerce_email_additional_content_' . $id, array( self::class, 'translate_email_additional_content' ), 10, 3 );
			}
		}

		return $emails;
	}

	/**
	 * @param mixed $emails WC_Emails instance or email-class array.
	 */
	public static function register_email_string_filters_action( $emails ): void {
		self::register_email_string_filters( $emails );
	}

	/**
	 * Woo's transactional mailer listens to parent order events and dispatches
	 * `{event}_notification` callbacks. Scope both layers: direct sends use the
	 * parent action; deferred sends replay only the notification action later.
	 *
	 * @param mixed $actions
	 * @return mixed
	 */
	public static function register_transactional_email_actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}

		foreach ( $actions as $action ) {
			if ( ! is_string( $action ) || '' === $action ) {
				continue;
			}
			foreach ( array( $action, $action . '_notification' ) as $hook ) {
				if ( isset( self::$email_actions[ $hook ] ) ) {
					continue;
				}
				self::$email_actions[ $hook ] = true;
				add_action( $hook, array( self::class, 'begin_transactional_email_language' ), 1, 10 );
				add_action( $hook, array( self::class, 'end_transactional_email_language' ), 999, 10 );
			}
		}

		return $actions;
	}

	/**
	 * @param mixed ...$args
	 */
	public static function begin_transactional_email_language( ...$args ): void {
		$order = self::order_from_args( $args );
		if ( null !== $order ) {
			self::begin_order_language( $order );
		}
	}

	/**
	 * @param mixed ...$args
	 */
	public static function end_transactional_email_language( ...$args ): void {
		$order = self::order_from_args( $args );
		if ( null !== $order ) {
			self::end_order_language( $order );
		}
	}

	/**
	 * @param mixed $subject
	 * @param mixed $object
	 * @param mixed $email
	 */
	public static function translate_email_subject( $subject, $object = null, $email = null ): string {
		return self::translate_email_string( self::DOMAIN_EMAIL_SUBJECT, (string) $subject, $object, $email );
	}

	/**
	 * @param mixed $heading
	 * @param mixed $object
	 * @param mixed $email
	 */
	public static function translate_email_heading( $heading, $object = null, $email = null ): string {
		return self::translate_email_string( self::DOMAIN_EMAIL_HEADING, (string) $heading, $object, $email );
	}

	/**
	 * @param mixed $content
	 * @param mixed $object
	 * @param mixed $email
	 */
	public static function translate_email_additional_content( $content, $object = null, $email = null ): string {
		return self::translate_email_string( self::DOMAIN_EMAIL_ADDITIONAL_CONTENT, (string) $content, $object, $email );
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

	/**
	 * @param array<int, mixed> $args
	 * @return mixed|null
	 */
	private static function order_from_args( array $args ) {
		foreach ( $args as $arg ) {
			if ( is_object( $arg ) && method_exists( $arg, 'get_meta' ) && method_exists( $arg, 'get_id' ) ) {
				return $arg;
			}
		}

		foreach ( $args as $arg ) {
			if ( is_numeric( $arg ) && (int) $arg > 0 && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( (int) $arg );
				if ( is_object( $order ) && method_exists( $order, 'get_meta' ) && method_exists( $order, 'get_id' ) ) {
					return $order;
				}
			}
		}

		return null;
	}

	/**
	 * @param mixed $email
	 */
	private static function email_id( $email ): string {
		if ( is_object( $email ) && isset( $email->id ) && is_scalar( $email->id ) ) {
			return (string) $email->id;
		}
		if ( is_object( $email ) && method_exists( $email, 'get_id' ) ) {
			$id = $email->get_id();
			return is_scalar( $id ) ? (string) $id : '';
		}
		return '';
	}

	/**
	 * @param mixed $object
	 * @param mixed $email
	 */
	private static function translate_email_string( string $domain, string $source, $object, $email ): string {
		if ( '' === $source ) {
			return $source;
		}

		$order = is_object( $object ) && method_exists( $object, 'get_meta' ) ? $object : null;
		if ( null === $order && is_object( $email ) && isset( $email->object ) && is_object( $email->object ) ) {
			$order = $email->object;
		}

		$lang = self::order_language( $order );
		if ( null === $lang ) {
			return $source;
		}

		$previous = CurrentLanguage::code();
		CurrentLanguage::set( $lang );
		try {
			$context = self::email_id( $email );
			StringTranslator::register_source( $domain, $context, $source );

			if ( Languages::is_default( $lang ) ) {
				return $source;
			}

			$translation = StringTranslator::lookup_translation( $domain, $context, $source );
			return null !== $translation && '' !== $translation ? $translation : $source;
		} finally {
			CurrentLanguage::set( $previous );
		}
	}
}
