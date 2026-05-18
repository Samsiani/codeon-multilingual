<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Integration;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Rest\LangParam;
use WP_REST_Request;

/**
 * @group integration
 */
final class RestLangParamTest extends IntegrationTestCase {

	public function test_lang_is_registered_as_a_rest_collection_parameter(): void {
		LangParam::register_collection_params();

		$params = apply_filters( 'rest_post_collection_params', array() );

		$this->assertArrayHasKey( 'lang', $params );
		$this->assertSame( 'string', $params['lang']['type'] );
		$this->assertFalse( $params['lang']['required'] );
	}

	public function test_rest_lang_query_parameter_sets_current_language(): void {
		$this->add_language( 'ka', 'ka_GE', 'Georgian', 'Georgian' );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'lang', 'ka' );

		LangParam::detect_language( null, rest_get_server(), $request );

		$this->assertSame( 'ka', CurrentLanguage::code() );
	}

	public function test_rest_lang_ignores_inactive_or_unknown_language(): void {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'lang', 'zz' );

		LangParam::detect_language( null, rest_get_server(), $request );

		$this->assertSame( 'en', CurrentLanguage::code() );
	}
}
