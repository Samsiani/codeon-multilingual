<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\Settings;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Custom (per-product) attributes as translatable strings.
 *
 * `Woo\AttributeLabels` covers global attribute taxonomies, whose labels live
 * in `wp_woocommerce_attribute_taxonomies`. Custom attributes are different:
 * they are stored per product in the `_product_attributes` postmeta blob, and
 * their names and values are plain strings that never pass through `__()`.
 *
 * On a catalogue where every product carries the *same* attribute names
 * ("Make", "Year", "Container Number", …), those names are effectively shared
 * UI labels — translating them once should apply to the whole catalogue,
 * without duplicating a single product. That is what this module does.
 *
 * ## Names vs values
 *
 * Names are a small, finite set, so they are registered opportunistically as
 * they are rendered (bounded by a per-request "seen" set, exactly as
 * `AttributeLabels` does) and swapped via `woocommerce_attribute_label`.
 *
 * Values are NOT registered opportunistically. A catalogue's value space is
 * unbounded — lot numbers, prices, VINs and dates would flood the strings
 * catalog with thousands of rows nobody will ever translate. Values are only
 * registered by an explicit `scan()`, and only for attribute names the site
 * owner has opted in (`custom_attribute_value_keys`), with a cardinality
 * ceiling as a second safety net.
 *
 * ## Hot-path cost
 *
 * One hash lookup against the request-static compiled map per rendered
 * attribute label/value. Default-language requests short-circuit before any
 * lookup happens.
 */
final class CustomAttributes {

	public const DOMAIN_LABEL = 'wc-custom-attribute';
	public const DOMAIN_VALUE = 'wc-custom-attribute-value';

	/**
	 * Attribute names whose values are translatable, when unset.
	 *
	 * Empty by default: on a vehicle catalogue the values are makes, models,
	 * VINs, dates and prices — data, not UI text. Sites that do want a value
	 * set translated opt in explicitly via the `custom_attribute_value_keys`
	 * setting or the `cml_custom_attribute_value_keys` filter.
	 */
	private const DEFAULT_VALUE_KEYS = array();

	/**
	 * Refuse to register an attribute's values if it has more distinct values
	 * than this. Guards against opting in something like "Lot Number".
	 */
	private const MAX_DISTINCT_VALUES = 250;

	private static bool $registered = false;

	/** @var array<string, true> Labels already registered this request. */
	private static array $seen_labels = array();

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Priority 12: after Woo\AttributeLabels (10), which returns custom
		// attribute labels untouched.
		add_filter( 'woocommerce_attribute_label', array( self::class, 'translate_label' ), 12, 3 );
		add_filter( 'woocommerce_attribute', array( self::class, 'translate_value' ), 10, 3 );
	}

	// ---- Names -------------------------------------------------------------

	/**
	 * @param mixed       $label
	 * @param string|null $name
	 * @param mixed       $product
	 */
	public static function translate_label( $label, $name = null, $product = null ): string {
		unset( $product );

		$label = (string) $label;
		if ( '' === $label ) {
			return $label;
		}

		// Global attributes belong to Woo\AttributeLabels.
		if ( self::is_global_attribute( (string) ( $name ?? '' ) ) ) {
			return $label;
		}
		if ( strlen( $label ) > 190 ) {
			return $label;
		}

		if ( ! isset( self::$seen_labels[ $label ] ) ) {
			self::$seen_labels[ $label ] = true;
			StringTranslator::register_source( self::DOMAIN_LABEL, '', $label );
		}

		if ( Languages::is_default( CurrentLanguage::code() ) ) {
			return $label;
		}

		$translation = StringTranslator::lookup_translation( self::DOMAIN_LABEL, '', $label );
		return ( null !== $translation && '' !== $translation ) ? $translation : $label;
	}

	// ---- Values ------------------------------------------------------------

	/**
	 * WooCommerce hands us the already-formatted HTML plus the raw values, so
	 * we retranslate from `$values` and rebuild with WooCommerce's own
	 * formatting rather than trying to rewrite the finished markup.
	 *
	 * @param mixed $value_html Formatted value HTML.
	 * @param mixed $attribute  WC_Product_Attribute (or legacy array/string).
	 * @param mixed $values     Raw value strings.
	 * @return mixed The original value, or rebuilt HTML when a translation applied.
	 */
	public static function translate_value( $value_html, $attribute = null, $values = null ) {
		if ( Languages::is_default( CurrentLanguage::code() ) ) {
			return $value_html;
		}
		if ( ! is_array( $values ) || empty( $values ) ) {
			return $value_html;
		}

		$name = self::attribute_name( $attribute );
		if ( '' === $name || ! self::values_translatable( $name ) ) {
			return $value_html;
		}

		$changed    = false;
		$translated = array();

		foreach ( $values as $raw ) {
			if ( ! is_scalar( $raw ) ) {
				return $value_html;
			}
			$raw          = (string) $raw;
			$hit          = self::lookup_value( $name, $raw );
			$translated[] = ( null !== $hit ) ? $hit : $raw;
			if ( null !== $hit ) {
				$changed = true;
			}
		}

		if ( ! $changed ) {
			return $value_html;
		}

		return wpautop( wptexturize( implode( ', ', $translated ) ) );
	}

	/**
	 * Values are stored per attribute name (context), so "Key: 1" and
	 * "Doors: 1" can be translated differently.
	 */
	private static function lookup_value( string $name, string $raw ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}

		$hit = StringTranslator::lookup_translation( self::DOMAIN_VALUE, $name, $raw );
		if ( null !== $hit && '' !== $hit ) {
			return $hit;
		}

		// Catalogue data is rarely case-consistent ("WHITE" vs "White"), so
		// fall back to a canonical-case lookup before giving up.
		$canonical = self::canonical( $raw );
		if ( $canonical !== $raw ) {
			$hit = StringTranslator::lookup_translation( self::DOMAIN_VALUE, $name, $canonical );
			if ( null !== $hit && '' !== $hit ) {
				return $hit;
			}
		}

		return null;
	}

	/**
	 * Title-case form used as the canonical source string, so a single
	 * translation covers WHITE / White / white.
	 */
	public static function canonical( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		if ( '' === $value ) {
			return '';
		}
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( mb_strtolower( $value, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( strtolower( $value ) );
	}

	/**
	 * @return string[] Attribute names whose values may be translated.
	 */
	public static function value_keys(): array {
		$configured = Settings::get( 'custom_attribute_value_keys', null );
		if ( ! is_array( $configured ) ) {
			$configured = self::DEFAULT_VALUE_KEYS;
		}
		$configured = array_filter( array_map( 'strval', $configured ) );

		/**
		 * Filter the attribute names whose values are translatable.
		 *
		 * @param string[] $keys
		 */
		return (array) apply_filters( 'cml_custom_attribute_value_keys', array_values( $configured ) );
	}

	private static function values_translatable( string $name ): bool {
		static $lookup = null;
		if ( null === $lookup ) {
			$lookup = array();
			foreach ( self::value_keys() as $key ) {
				$lookup[ self::normalise_key( $key ) ] = true;
			}
		}
		return isset( $lookup[ self::normalise_key( $name ) ] );
	}

	private static function normalise_key( string $name ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $name ), 'UTF-8' ) : strtolower( trim( $name ) );
	}

	/**
	 * @param mixed $attribute
	 */
	private static function attribute_name( $attribute ): string {
		if ( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) ) {
			return (string) $attribute->get_name();
		}
		if ( is_array( $attribute ) && isset( $attribute['name'] ) ) {
			return (string) $attribute['name'];
		}
		if ( is_string( $attribute ) ) {
			return $attribute;
		}
		return '';
	}

	private static function is_global_attribute( string $slug ): bool {
		return 0 === strpos( $slug, 'pa_' );
	}

	// ---- Scan --------------------------------------------------------------

	/**
	 * Walk `_product_attributes` and register every custom attribute name,
	 * plus the values of the opted-in attributes.
	 *
	 * Batched so it can run on a large catalogue without exhausting memory:
	 * meta rows are read in chunks and only the deduplicated string sets are
	 * held in memory.
	 *
	 * @param int $batch Meta rows per query.
	 * @return array{names:int,values:int,scanned:int,skipped:array<string,int>}
	 */
	public static function scan( int $batch = 500 ): array {
		global $wpdb;

		$names   = array();
		$values  = array();
		$scanned = 0;
		$offset  = 0;
		$fetched = 0;

		do {
			// Direct query: `_product_attributes` is a serialized blob with no
			// WP_Query surface, and this runs only from an explicit scan, never
			// on a page request — caching a one-shot migration read would only
			// risk serving a stale catalogue.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_attributes' ORDER BY meta_id LIMIT %d OFFSET %d",
					$batch,
					$offset
				)
			);

			foreach ( (array) $rows as $row ) {
				$attrs = maybe_unserialize( $row );
				if ( ! is_array( $attrs ) ) {
					continue;
				}
				++$scanned;

				foreach ( $attrs as $key => $def ) {
					if ( ! is_array( $def ) ) {
						continue;
					}
					// Skip taxonomy-backed attributes — AttributeLabels owns them.
					if ( ! empty( $def['is_taxonomy'] ) ) {
						continue;
					}

					$name = isset( $def['name'] ) ? trim( (string) $def['name'] ) : trim( (string) $key );
					if ( '' === $name || strlen( $name ) > 190 ) {
						continue;
					}
					$names[ $name ] = true;

					if ( ! self::values_translatable( $name ) ) {
						continue;
					}

					$raw = isset( $def['value'] ) ? (string) $def['value'] : '';
					if ( '' === $raw ) {
						continue;
					}
					// WooCommerce stores multiple values pipe-separated.
					foreach ( explode( '|', $raw ) as $single ) {
						$single = self::canonical( $single );
						if ( '' !== $single && strlen( $single ) <= 190 ) {
							$values[ $name ][ $single ] = true;
						}
					}
				}
			}

			$fetched = is_array( $rows ) ? count( $rows ) : 0;
			$offset += $batch;
		} while ( $fetched === $batch );

		foreach ( array_keys( $names ) as $name ) {
			StringTranslator::register_source( self::DOMAIN_LABEL, '', (string) $name );
		}

		$value_count = 0;
		$skipped     = array();

		foreach ( $values as $name => $set ) {
			// Cardinality ceiling: an opted-in attribute that turns out to be
			// high-cardinality is reported, not silently truncated.
			if ( count( $set ) > self::MAX_DISTINCT_VALUES ) {
				$skipped[ (string) $name ] = count( $set );
				continue;
			}
			foreach ( array_keys( $set ) as $value ) {
				StringTranslator::register_source( self::DOMAIN_VALUE, (string) $name, (string) $value );
				++$value_count;
			}
		}

		return array(
			'names'   => count( $names ),
			'values'  => $value_count,
			'scanned' => $scanned,
			'skipped' => $skipped,
		);
	}
}
