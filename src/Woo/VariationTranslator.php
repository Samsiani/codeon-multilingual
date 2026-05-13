<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Content\PostTranslator;

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
			PostTranslator::duplicate( (int) $vid, $target_lang );
		}
	}
}
