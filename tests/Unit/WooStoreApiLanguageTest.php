<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Woo\StoreApiLanguage;

/**
 * @covers \Samsiani\CodeonMultilingual\Woo\StoreApiLanguage
 */
final class WooStoreApiLanguageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->reset_registration();

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return 'home' === $name ? 'https://example.test' : $default;
			}
		);

		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';

			/** @return array<int, object> */
			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'cml_languages' ) ) {
					return array(
						(object) array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'flag' => 'us', 'rtl' => 0, 'active' => 1, 'is_default' => 1, 'position' => 0 ),
						(object) array( 'code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native' => 'ქართული', 'flag' => 'ge', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 1 ),
						(object) array( 'code' => 'ru', 'locale' => 'ru_RU', 'name' => 'Russian', 'native' => 'Русский', 'flag' => 'ru', 'rtl' => 0, 'active' => 0, 'is_default' => 0, 'position' => 2 ),
					);
				}
				return array();
			}
		};

		Languages::flush_cache();
		CurrentLanguage::set( 'en' );
	}

	protected function tearDown(): void {
		CurrentLanguage::reset();
		Languages::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registers_rest_pre_dispatch_filter(): void {
		Filters\expectAdded( 'rest_pre_dispatch' )
			->once()
			->with( array( StoreApiLanguage::class, 'detect_language' ), 10, 3 );

		StoreApiLanguage::register();

		$this->assertTrue( Filters\has( 'rest_pre_dispatch', array( StoreApiLanguage::class, 'detect_language' ), 10 ) );
	}

	public function test_explicit_lang_param_sets_store_api_language(): void {
		StoreApiLanguage::detect_language(
			null,
			null,
			$this->request( '/wc/store/v1/cart', array( 'lang' => 'ka' ) )
		);

		$this->assertSame( 'ka', CurrentLanguage::code() );
	}

	public function test_language_header_is_used_when_lang_param_is_absent(): void {
		StoreApiLanguage::detect_language(
			null,
			null,
			$this->request( '/wc/store/v1/cart', array(), array( 'X-CodeOn-Language' => 'ka' ) )
		);

		$this->assertSame( 'ka', CurrentLanguage::code() );
	}

	public function test_explicit_lang_param_takes_priority_over_header(): void {
		CurrentLanguage::set( 'ka' );

		StoreApiLanguage::detect_language(
			null,
			null,
			$this->request( '/wc/store/v1/cart', array( 'lang' => 'en' ), array( 'X-CodeOn-Language' => 'ka' ) )
		);

		$this->assertSame( 'en', CurrentLanguage::code() );
	}

	public function test_referer_language_prefix_is_used_as_a_fallback(): void {
		StoreApiLanguage::detect_language(
			null,
			null,
			$this->request( '/wc/store/v1/cart/items', array(), array( 'referer' => 'https://example.test/ka/cart/' ) )
		);

		$this->assertSame( 'ka', CurrentLanguage::code() );
	}

	public function test_ignores_non_store_api_routes(): void {
		StoreApiLanguage::detect_language(
			null,
			null,
			$this->request( '/wc/v3/products', array( 'lang' => 'ka' ), array( 'X-CodeOn-Language' => 'ka' ) )
		);

		$this->assertSame( 'en', CurrentLanguage::code() );
	}

	public function test_ignores_invalid_or_inactive_language_candidates(): void {
		StoreApiLanguage::detect_language(
			null,
			null,
			$this->request(
				'/wc/store/v1/cart',
				array( 'lang' => '../ka' ),
				array(
					'X-CodeOn-Language' => 'ru',
					'referer'           => 'https://example.test/not-a-language/cart/',
				)
			)
		);

		$this->assertSame( 'en', CurrentLanguage::code() );
	}

	/**
	 * @param array<string, mixed>  $params
	 * @param array<string, string> $headers
	 * @return object
	 */
	private function request( string $route, array $params = array(), array $headers = array() ): object {
		return new class( $route, $params, $headers ) {
			/**
			 * @param array<string, mixed>  $params
			 * @param array<string, string> $headers
			 */
			public function __construct(
				private string $route,
				private array $params,
				private array $headers
			) {}

			public function get_route(): string {
				return $this->route;
			}

			/**
			 * @return mixed
			 */
			public function get_param( string $name ) {
				return $this->params[ $name ] ?? null;
			}

			public function get_header( string $name ): string {
				foreach ( $this->headers as $header => $value ) {
					if ( strtolower( $header ) === strtolower( $name ) ) {
						return $value;
					}
				}

				return '';
			}
		};
	}

	private function reset_registration(): void {
		$registered = new \ReflectionProperty( StoreApiLanguage::class, 'registered' );
		$registered->setValue( null, false );
	}
}
