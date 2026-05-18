<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Url\Router;
use Samsiani\CodeonMultilingual\Url\SubdirectoryStrategy;

/**
 * @covers \Samsiani\CodeonMultilingual\Url\Router::filter_redirect_canonical
 */
final class RouterCanonicalTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = null ) => 'home' === $key ? 'https://example.test' : $default
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';

			/** @return array<int, object> */
			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'cml_languages' ) ) {
					return array(
						(object) array( 'code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native' => 'ქართული', 'flag' => 'ge', 'rtl' => 0, 'active' => 1, 'is_default' => 1, 'position' => 0 ),
						(object) array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'flag' => 'us', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 1 ),
					);
				}
				return array();
			}
		};

		Languages::flush_cache();
		CurrentLanguage::set( 'en' );
		$this->set_router_property( 'strategy', new SubdirectoryStrategy() );
		$this->set_router_property( 'stripped_request', true );

		$home_path = new \ReflectionProperty( SubdirectoryStrategy::class, 'home_path_cache' );
		$home_path->setValue( null, null );
	}

	protected function tearDown(): void {
		$this->set_router_property( 'strategy', null );
		$this->set_router_property( 'stripped_request', false );
		CurrentLanguage::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_blocks_only_language_prefix_canonical_loop(): void {
		$this->assertFalse(
			Router::filter_redirect_canonical(
				'https://example.test/en/shop/',
				'https://example.test/shop/'
			)
		);
	}

	public function test_keeps_unrelated_canonical_redirects(): void {
		$redirect = 'https://example.test/en/shop/';
		$this->assertSame(
			$redirect,
			Router::filter_redirect_canonical(
				$redirect,
				'https://example.test/shop'
			)
		);
	}

	public function test_preserves_language_prefix_for_canonical_redirects(): void {
		$this->assertSame(
			'https://example.test/en/shop/',
			Router::filter_redirect_canonical(
				'https://example.test/shop/',
				'https://example.test/shop'
			)
		);
	}

	public function test_keeps_redirects_when_request_was_not_stripped(): void {
		$this->set_router_property( 'stripped_request', false );

		$redirect = 'https://example.test/en/shop/';
		$this->assertSame(
			$redirect,
			Router::filter_redirect_canonical(
				$redirect,
				'https://example.test/shop/'
			)
		);
	}

	/**
	 * @param mixed $value
	 */
	private function set_router_property( string $name, $value ): void {
		$ref = new \ReflectionProperty( Router::class, $name );
		$ref->setValue( null, $value );
	}
}
