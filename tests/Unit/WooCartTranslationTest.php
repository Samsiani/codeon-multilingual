<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Woo\CartTranslation;
use Samsiani\CodeonMultilingual\Woo\StoreApiLanguage;

/**
 * @covers \Samsiani\CodeonMultilingual\Woo\CartTranslation
 */
final class WooCartTranslationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->reset_cart_translation();
		$this->reset_store_api_language();

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

			/** @param mixed ...$args */
			public function prepare( string $sql, ...$args ): string {
				foreach ( $args as $arg ) {
					if ( is_int( $arg ) ) {
						$sql = preg_replace( '/%d/', (string) $arg, $sql, 1 ) ?? $sql;
					} else {
						$sql = preg_replace( '/%s/', "'" . (string) $arg . "'", $sql, 1 ) ?? $sql;
					}
				}

				return $sql;
			}

			public function get_row( string $sql ): ?object {
				if ( str_contains( $sql, 'post_id = 101' ) ) {
					return (object) array( 'group_id' => 501, 'language' => 'en' );
				}
				if ( str_contains( $sql, 'post_id = 102' ) ) {
					return (object) array( 'group_id' => 501, 'language' => 'ka' );
				}

				return null;
			}

			/** @return array<int, object> */
			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'cml_languages' ) ) {
					return array(
						(object) array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'flag' => 'us', 'rtl' => 0, 'active' => 1, 'is_default' => 1, 'position' => 0 ),
						(object) array( 'code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native' => 'ქართული', 'flag' => 'ge', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 1 ),
					);
				}
				if ( str_contains( $sql, 'group_id = 501' ) ) {
					return array(
						(object) array( 'post_id' => 101, 'language' => 'en' ),
						(object) array( 'post_id' => 102, 'language' => 'ka' ),
					);
				}

				return array();
			}
		};

		Languages::flush_cache();
		TranslationGroups::flush();
		CurrentLanguage::set( 'en' );
	}

	protected function tearDown(): void {
		CurrentLanguage::reset();
		Languages::flush_cache();
		TranslationGroups::flush();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registers_classic_and_store_api_cart_filters(): void {
		Filters\expectAdded( 'woocommerce_cart_item_product' )
			->once()
			->with( array( CartTranslation::class, 'translate_product' ), 10, 3 );
		Filters\expectAdded( 'woocommerce_get_cart_item_from_session' )
			->once()
			->with( array( CartTranslation::class, 'translate_store_api_cart_item_data' ), 20, 3 );
		Filters\expectAdded( 'woocommerce_add_cart_item' )
			->once()
			->with( array( CartTranslation::class, 'translate_store_api_cart_item_data' ), 20, 2 );
		Filters\expectAdded( 'woocommerce_cart_item_permalink' )
			->once()
			->with( array( CartTranslation::class, 'translate_permalink' ), 10, 3 );

		CartTranslation::register();

		$this->assertTrue( Filters\has( 'woocommerce_get_cart_item_from_session', array( CartTranslation::class, 'translate_store_api_cart_item_data' ), 20 ) );
	}

	public function test_store_api_session_cart_item_swaps_product_data_to_current_language_sibling(): void {
		$this->mark_store_api_request( 'ka' );

		$source     = $this->product( 101 );
		$translated = $this->product( 102 );

		Functions\expect( 'wc_get_product' )
			->once()
			->with( 102 )
			->andReturn( $translated );

		$cart_item = CartTranslation::translate_store_api_cart_item_data(
			array(
				'data'       => $source,
				'product_id' => 101,
			),
			array(),
			'abc123'
		);

		$this->assertSame( $translated, $cart_item['data'] );
		$this->assertSame( 101, $cart_item['product_id'] );
	}

	public function test_same_request_store_api_add_cart_item_uses_translated_product_data(): void {
		$this->mark_store_api_request( 'ka' );

		$source     = $this->product( 101 );
		$translated = $this->product( 102 );

		Functions\expect( 'wc_get_product' )
			->once()
			->with( 102 )
			->andReturn( $translated );

		$cart_item = CartTranslation::translate_store_api_cart_item_data(
			array(
				'data'         => $source,
				'product_id'   => 101,
				'variation_id' => 101,
			)
		);

		$this->assertSame( $translated, $cart_item['data'] );
		$this->assertSame( 101, $cart_item['variation_id'] );
	}

	public function test_non_store_api_request_preserves_cart_item_data(): void {
		$source = $this->product( 101 );

		Functions\expect( 'wc_get_product' )->never();

		$cart_item = CartTranslation::translate_store_api_cart_item_data(
			array(
				'data'       => $source,
				'product_id' => 101,
			)
		);

		$this->assertSame( $source, $cart_item['data'] );
	}

	public function test_classic_woocommerce_add_cart_item_signature_does_not_throw(): void {
		$source = $this->product( 101 );

		Functions\expect( 'wc_get_product' )->never();

		$cart_item = CartTranslation::translate_store_api_cart_item_data(
			array(
				'data'       => $source,
				'product_id' => 101,
			),
			'cart-item-key'
		);

		$this->assertSame( $source, $cart_item['data'] );
	}

	private function mark_store_api_request( string $language ): void {
		StoreApiLanguage::detect_language(
			null,
			null,
			$this->request( '/wc/store/v1/cart', array( 'lang' => $language ) )
		);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function request( string $route, array $params = array() ): object {
		return new class( $route, $params ) {
			/** @param array<string, mixed> $params */
			public function __construct(
				private string $route,
				private array $params
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
				unset( $name );
				return '';
			}
		};
	}

	private function product( int $id ): object {
		return new class( $id ) {
			public function __construct( private int $id ) {}

			public function get_id(): int {
				return $this->id;
			}
		};
	}

	private function reset_cart_translation(): void {
		$registered = new \ReflectionProperty( CartTranslation::class, 'registered' );
		$registered->setValue( null, false );

		$cache = new \ReflectionProperty( CartTranslation::class, 'cache' );
		$cache->setValue( null, array() );
	}

	private function reset_store_api_language(): void {
		$registered = new \ReflectionProperty( StoreApiLanguage::class, 'registered' );
		$registered->setValue( null, false );

		$current = new \ReflectionProperty( StoreApiLanguage::class, 'store_api_request' );
		$current->setValue( null, false );
	}
}
