<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Integration;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Url\Router;

/**
 * @group integration
 */
final class FrontendRoutingTest extends IntegrationTestCase {

	public function test_en_frontend_prefix_sets_language_and_strips_request_uri(): void {
		$this->add_language( 'ka', 'ka_GE', 'Georgian', 'Georgian', true, 0 );
		$this->set_default_language( 'ka' );

		$previous_request_uri   = $_SERVER['REQUEST_URI'] ?? null;
		$_SERVER['REQUEST_URI'] = '/en/sample-page/?preview=1';

		try {
			Router::on_request();

			$this->assertSame( 'en', CurrentLanguage::code() );
			$this->assertSame( '/sample-page/?preview=1', $_SERVER['REQUEST_URI'] );
		} finally {
			if ( null === $previous_request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $previous_request_uri;
			}
		}
	}

	public function test_non_default_language_urls_receive_subdirectory_prefix(): void {
		$this->add_language( 'ka', 'ka_GE', 'Georgian', 'Georgian', true, 0 );
		$this->set_default_language( 'ka' );

		$this->assertSame(
			'http://example.org/en/shop/',
			Router::with_lang( 'http://example.org/shop/', 'en' )
		);
	}
}
