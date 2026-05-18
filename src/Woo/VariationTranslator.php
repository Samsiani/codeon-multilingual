<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Content\PostTranslator;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;

/**
 * Auto-duplicates a variable product's variations when the parent product is
 * translated. Triggered by the cml_post_translation_created action that
 * PostTranslator fires post-insert.
 *
 * Each variation goes through the same PostTranslator::duplicate() path. The
 * resolve_translated_parent logic there finds the newly-created translated
 * product (its group cache was invalidated before the action fires) and pins
 * the variation under it.
 *
 * Attribute meta (attribute_pa_color etc.) is cloned verbatim. Sites that
 * translate attribute term slugs differently per language need to map those
 * separately — same constraint WPML imposes.
 */
final class VariationTranslator {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'cml_post_translation_created', array( self::class, 'on_translation_created' ), 10, 4 );
		add_filter( 'woocommerce_variation_option_name', array( self::class, 'translate_variation_option_name' ), 10, 4 );
	}

	public static function on_translation_created( int $new_id, int $source_id, string $target_lang, int $group_id ): void {
		if ( 'product' !== get_post_type( $source_id ) ) {
			return;
		}

		$variation_ids = get_posts(
			array(
				'post_parent'     => $source_id,
				'post_type'       => 'product_variation',
				'post_status'     => array( 'publish', 'private', 'draft' ),
				'numberposts'     => -1,
				'fields'          => 'ids',
				'cml_skip_filter' => true,
			)
		);

		if ( empty( $variation_ids ) ) {
			return;
		}

		foreach ( $variation_ids as $vid ) {
			$new_variation_id = PostTranslator::duplicate( (int) $vid, $target_lang );
			if ( $new_variation_id > 0 ) {
				self::remap_variation_attributes( $new_variation_id, $target_lang );
			}
		}
	}

	/**
	 * Woo calls this for variation attribute values in classic templates and
	 * Store API cart/order schemas. When a cart item still carries the source
	 * attribute slug after a language switch, render the translated sibling's
	 * term name for the current request language.
	 *
	 * @param mixed $name
	 * @param mixed $term
	 * @param mixed $taxonomy
	 * @param mixed $product
	 */
	public static function translate_variation_option_name( $name, $term = null, $taxonomy = '', $product = null ): string {
		unset( $product );

		$term_id = self::term_id_from_object( $term );
		if ( $term_id <= 0 ) {
			return (string) $name;
		}

		$group_id = TranslationGroups::get_term_group_id( $term_id );
		if ( null === $group_id ) {
			return (string) $name;
		}

		$target_lang = CurrentLanguage::code();
		$siblings    = TranslationGroups::get_term_siblings( $group_id );
		$target_id   = (int) ( array_search( $target_lang, $siblings, true ) ?: 0 );
		if ( $target_id <= 0 || $target_id === $term_id || ! function_exists( 'get_term' ) ) {
			return (string) $name;
		}

		$target = get_term( $target_id, is_scalar( $taxonomy ) ? (string) $taxonomy : '' );
		return is_object( $target ) && isset( $target->name ) && is_scalar( $target->name )
			? (string) $target->name
			: (string) $name;
	}

	private static function remap_variation_attributes( int $variation_id, string $target_lang ): void {
		$meta = get_post_meta( $variation_id );
		if ( ! is_array( $meta ) ) {
			return;
		}

		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( ! str_starts_with( $key, 'attribute_pa_' ) ) {
				continue;
			}
			$source_slug = isset( $values[0] ) ? (string) $values[0] : '';
			if ( '' === $source_slug ) {
				continue;
			}

			$taxonomy = substr( $key, strlen( 'attribute_' ) );
			$target   = self::translated_attribute_slug( $taxonomy, $source_slug, $target_lang );
			if ( null !== $target && $target !== $source_slug ) {
				update_post_meta( $variation_id, $key, $target );
			}
		}
	}

	private static function translated_attribute_slug( string $taxonomy, string $source_slug, string $target_lang ): ?string {
		$term = get_term_by( 'slug', $source_slug, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$group_id = TranslationGroups::get_term_group_id( (int) $term->term_id );
		if ( null === $group_id ) {
			return null;
		}

		$siblings        = TranslationGroups::get_term_siblings( $group_id );
		$translated_term = (int) ( array_search( $target_lang, $siblings, true ) ?: 0 );
		if ( $translated_term <= 0 || $translated_term === (int) $term->term_id ) {
			return null;
		}

		$target = get_term( $translated_term, $taxonomy );
		return $target instanceof \WP_Term ? (string) $target->slug : null;
	}

	/**
	 * @param mixed $term
	 */
	private static function term_id_from_object( $term ): int {
		if ( is_object( $term ) && isset( $term->term_id ) && is_scalar( $term->term_id ) ) {
			return (int) $term->term_id;
		}

		return 0;
	}
}
