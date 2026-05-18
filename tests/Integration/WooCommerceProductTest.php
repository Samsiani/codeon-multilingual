<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Integration;

use Samsiani\CodeonMultilingual\Content\PostTranslator;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;

/**
 * @group integration
 * @group woocommerce
 */
final class WooCommerceProductTest extends IntegrationTestCase {

	public function test_variable_product_translation_duplicates_variations_when_woocommerce_is_loaded(): void {
		$this->skip_without_woocommerce();
		$this->add_language( 'ka', 'ka_GE', 'Georgian', 'Georgian' );

		$product = new \WC_Product_Variable();
		$product->set_name( 'Source Variable Product' );
		$product->set_status( 'publish' );
		$product_id = $product->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_regular_price( '12.50' );
		$variation->set_status( 'publish' );
		$variation_id = $variation->save();

		$this->tag_post_language( $product_id, $product_id, 'en' );
		$this->tag_post_language( $variation_id, $variation_id, 'en' );

		$translated_product_id = PostTranslator::duplicate( $product_id, 'ka' );

		$this->assertGreaterThan( 0, $translated_product_id );
		$this->assertSame( 'product', get_post_type( $translated_product_id ) );
		$this->assertSame( 'ka', TranslationGroups::get_language( $translated_product_id ) );

		$translated_variation_ids = get_posts(
			array(
				'post_parent'     => $translated_product_id,
				'post_type'       => 'product_variation',
				'post_status'     => array( 'publish', 'private', 'draft' ),
				'fields'          => 'ids',
				'numberposts'     => -1,
				'cml_skip_filter' => true,
			)
		);

		$this->assertCount( 1, $translated_variation_ids );

		$translated_variation_id = (int) $translated_variation_ids[0];
		$translated_variation    = wc_get_product( $translated_variation_id );

		$this->assertInstanceOf( \WC_Product_Variation::class, $translated_variation );
		$this->assertSame( $translated_product_id, $translated_variation->get_parent_id() );
		$this->assertSame( 'ka', TranslationGroups::get_language( $translated_variation_id ) );
		$this->assertEqualsWithDelta( 12.50, (float) $translated_variation->get_regular_price(), 0.001 );
	}
}
