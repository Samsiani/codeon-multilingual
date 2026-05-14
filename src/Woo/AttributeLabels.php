<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * WooCommerce attribute labels live in `wp_woocommerce_attribute_taxonomies`,
 * never pass through `__()`, and are otherwise un-translatable. We register
 * each label as a source string in the strings catalog so the Multilingual →
 * Strings admin can translate it per language, then swap the label at runtime
 * via `woocommerce_attribute_label`.
 *
 * Hot-path cost: one `lookup_translation()` call per `wc_attribute_label()`
 * — a single hash lookup against the request-static compiled map, ~0.5μs.
 * Default-language requests short-circuit immediately (no lookup at all).
 *
 * Source sync points:
 *  - `cml_activate` / `cml_upgrade` — full sweep on plugin (re-)activation.
 *  - `woocommerce_attribute_added` / `..._updated` — per-attribute hook so
 *    new labels appear in the catalog as soon as a merchant saves them.
 *  - `init` lazy fallback (admin only) — covers the case where WC was
 *    already active before our plugin's activation hook ran.
 *  - Each filter call also opportunistically registers the source — covers
 *    labels added by code (third-party plugins, themes) that never run our
 *    `woocommerce_attribute_added` hook.
 *
 * Performance note: opportunistic registration is gated by a request-static
 * "already registered this label" set so 50 product list rows × 5 attribute
 * labels = 5 INSERT IGNORE attempts (not 250).
 */
final class AttributeLabels {

	private const DOMAIN = 'wc-attribute-label';

	private static bool $registered = false;

	/** @var array<string, true> */
	private static array $seen = array();

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'woocommerce_attribute_label', array( self::class, 'translate_label' ), 10, 3 );
		add_action( 'woocommerce_attribute_added',   array( self::class, 'on_attribute_changed' ) );
		add_action( 'woocommerce_attribute_updated', array( self::class, 'on_attribute_changed' ) );
		add_action( 'cml_activated',                 array( self::class, 'sync_all_labels' ) );
		add_action( 'cml_upgraded',                  array( self::class, 'sync_all_labels' ) );
		add_action( 'admin_init',                    array( self::class, 'sync_all_labels_once_per_session' ) );
	}

	/**
	 * @param mixed       $label
	 * @param string|null $name
	 * @param mixed       $product
	 */
	public static function translate_label( $label, $name = null, $product = null ): string {
		$label = (string) $label;
		if ( '' === $label ) {
			return $label;
		}
		if ( ! function_exists( 'taxonomy_is_product_attribute' ) ) {
			return $label;
		}
		// Only translate labels that originate from a registered WC attribute
		// taxonomy (i.e. `pa_*` slugs). Custom per-product attributes use
		// arbitrary strings as their "names" — those are user-entered HTML
		// text and translated via the post-translation flow, not this filter.
		$slug = (string) ( $name ?? '' );
		if ( '' !== $slug && ! self::is_global_attribute( $slug ) ) {
			return $label;
		}

		// Opportunistic source registration — covers labels added by code
		// without firing `woocommerce_attribute_added`. Once per request.
		if ( ! isset( self::$seen[ $label ] ) ) {
			self::$seen[ $label ] = true;
			StringTranslator::register_source( self::DOMAIN, '', $label );
		}

		if ( Languages::is_default( CurrentLanguage::code() ) ) {
			return $label;
		}

		$translation = StringTranslator::lookup_translation( self::DOMAIN, '', $label );
		return null !== $translation && '' !== $translation ? $translation : $label;
	}

	/**
	 * @param int|string $attribute_id  WC fires this with the new id; we ignore it
	 *                                  and re-fetch from the public helper to
	 *                                  avoid coupling to WC's row shape.
	 */
	public static function on_attribute_changed( $attribute_id = 0 ): void {
		unset( $attribute_id );
		self::sync_all_labels();
	}

	/**
	 * Idempotent — `register_source()` flushes caches only on actual inserts,
	 * so calling this repeatedly is cheap once the catalog is in sync.
	 */
	public static function sync_all_labels(): void {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}
		$attrs = wc_get_attribute_taxonomies();
		if ( empty( $attrs ) ) {
			return;
		}
		foreach ( $attrs as $attr ) {
			$label = isset( $attr->attribute_label ) ? (string) $attr->attribute_label : '';
			if ( '' === $label ) {
				continue;
			}
			StringTranslator::register_source( self::DOMAIN, '', $label );
		}
	}

	/**
	 * Lazy fallback for sites where the plugin was activated before WC, or where
	 * the activation hook ran without WC loaded. Runs once per admin session.
	 */
	public static function sync_all_labels_once_per_session(): void {
		if ( ! is_admin() ) {
			return;
		}
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		self::sync_all_labels();
	}

	private static function is_global_attribute( string $slug ): bool {
		// WC global attributes are stored as taxonomies prefixed with `pa_`.
		// `wc_attribute_taxonomy_name()` and `taxonomy_is_product_attribute()`
		// both ultimately check this prefix; we inline the cheap check to
		// avoid the taxonomy registration scan on every filter call.
		return 0 === strpos( $slug, 'pa_' );
	}
}
