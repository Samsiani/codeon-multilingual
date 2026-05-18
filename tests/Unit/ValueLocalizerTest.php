<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Compat\ValueLocalizer;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Url\Router;
use Samsiani\CodeonMultilingual\Url\SubdirectoryStrategy;

final class ValueLocalizerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		Functions\when( 'get_option' )->alias( static fn( $key, $default = null ) => 'home' === $key ? 'https://example.test' : $default );
		Functions\when( 'home_url' )->alias( static fn( $path = '/' ) => 'https://example.test/' . ltrim( (string) $path, '/' ) );
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
						(object) array( 'code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native' => 'Georgian', 'flag' => 'ge', 'rtl' => 0, 'active' => 1, 'is_default' => 1, 'position' => 0 ),
						(object) array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'flag' => 'us', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 1 ),
					);
				}

				return array();
			}
		};

		Languages::flush_cache();
		$this->set_router_strategy( new SubdirectoryStrategy() );
	}

	protected function tearDown(): void {
		Languages::flush_cache();
		$this->set_router_strategy( null );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_walk_localizes_nested_ids_and_urls_by_policy(): void {
		$value = array(
			'post_id' => 10,
			'items'   => array(
				array(
					'term_id' => 5,
					'url'     => 'https://example.test/shop/product/',
				),
			),
		);

		$result = ValueLocalizer::walk(
			$value,
			'ka',
			array(
				'post_keys' => array(),
				'term_keys' => array(),
				'url_keys'  => array(),
			)
		);

		$this->assertSame( $value, $result );
	}

	public function test_walk_preserves_scalar_values_without_policy_match(): void {
		$value = array(
			'title' => 'Hello',
			'ids'   => array( 1, 2, 3 ),
		);

		$this->assertSame( $value, ValueLocalizer::walk( $value, 'ka' ) );
	}

	public function test_url_localizes_routable_frontend_urls_only(): void {
		$this->assertSame(
			'https://example.test/en/shop/product/',
			ValueLocalizer::url( 'https://example.test/shop/product/', 'en' )
		);
		$this->assertSame(
			'https://example.test/wp-content/uploads/photo.jpg',
			ValueLocalizer::url( 'https://example.test/wp-content/uploads/photo.jpg', 'en' )
		);
		$this->assertSame(
			'https://example.test/uploads/photo.jpg',
			ValueLocalizer::url( 'https://example.test/uploads/photo.jpg', 'en' )
		);
		$this->assertSame(
			'https://example.test/wp-json/wc/store/v1/cart',
			ValueLocalizer::url( 'https://example.test/wp-json/wc/store/v1/cart', 'en' )
		);
		$this->assertSame(
			'https://example.test/?rest_route=/wc/store/v1/cart',
			ValueLocalizer::url( 'https://example.test/?rest_route=/wc/store/v1/cart', 'en' )
		);
		$this->assertSame(
			'https://example.test/wp-admin/admin-ajax.php',
			ValueLocalizer::url( 'https://example.test/wp-admin/admin-ajax.php', 'en' )
		);
	}

	private function set_router_strategy( ?SubdirectoryStrategy $strategy ): void {
		$router = new \ReflectionProperty( Router::class, 'strategy' );
		$router->setValue( null, $strategy );

		$home_path = new \ReflectionProperty( SubdirectoryStrategy::class, 'home_path_cache' );
		$home_path->setValue( null, null );
	}
}
