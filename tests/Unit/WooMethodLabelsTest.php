<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;
use Samsiani\CodeonMultilingual\Woo\MethodLabels;

/**
 * @covers \Samsiani\CodeonMultilingual\Woo\MethodLabels
 */
final class WooMethodLabelsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', $key ) ?? '' )
		);

		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';
			public int $rows_affected = 0;

			/** @param mixed ...$args */
			public function prepare( string $sql, ...$args ): string {
				unset( $args );
				return $sql;
			}

			public function query( string $sql ): int {
				unset( $sql );
				return 0;
			}

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
		$this->set_compiled_map( 'en', array() );
	}

	protected function tearDown(): void {
		$this->set_compiled_map( 'en', array() );
		CurrentLanguage::reset();
		Languages::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_translates_payment_gateway_title_from_strings_catalog(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-payment-title', 'cod', 'Cash on delivery' ) => 'Cash',
			)
		);

		$this->assertSame(
			'Cash',
			MethodLabels::translate_payment_title( 'Cash on delivery', 'cod' )
		);
	}

	public function test_translates_payment_gateway_title_from_gateway_object(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-payment-title', 'stripe', 'Credit card' ) => 'Card',
			)
		);

		$gateway = new class {
			public string $id = 'stripe';
		};

		$this->assertSame(
			'Card',
			MethodLabels::translate_payment_title( 'Credit card', $gateway )
		);
	}

	public function test_translates_stored_order_payment_title(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-payment-title', 'cod', 'Cash on delivery' ) => 'Cash',
			)
		);

		$order = new class {
			public function get_payment_method(): string {
				return 'cod';
			}
		};

		$this->assertSame(
			'Cash',
			MethodLabels::translate_order_payment_method_title( 'Cash on delivery', $order )
		);
	}

	public function test_translates_shipping_rate_label_by_method_and_instance(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-shipping-label', 'flat_rate:7', 'Flat rate' ) => 'Courier',
			)
		);

		$rate = new class {
			public function get_method_id(): string {
				return 'flat_rate';
			}

			public function get_instance_id(): int {
				return 7;
			}
		};

		$this->assertSame(
			'Courier',
			MethodLabels::translate_shipping_label( 'Flat rate', $rate )
		);
	}

	public function test_translates_shipping_rate_label_from_method_fallback(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-shipping-label', 'flat_rate', 'Flat rate' ) => 'Courier',
			)
		);

		$rate = new class {
			public function get_method_id(): string {
				return 'flat_rate';
			}

			public function get_instance_id(): int {
				return 7;
			}
		};

		$this->assertSame(
			'Courier',
			MethodLabels::translate_shipping_label( 'Flat rate', $rate )
		);
	}

	public function test_translates_stored_order_shipping_method_title(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-shipping-label', 'local_pickup:3', 'Local pickup' ) => 'Pickup',
			)
		);

		$item = new class {
			public function get_type(): string {
				return 'shipping';
			}

			public function get_method_id(): string {
				return 'local_pickup';
			}

			public function get_instance_id(): int {
				return 3;
			}
		};

		$this->assertSame(
			'Pickup',
			MethodLabels::translate_order_shipping_method_title( 'Local pickup', $item )
		);
	}

	public function test_translates_checkout_and_cart_notice_message(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-notice', 'error', 'Billing first name is required.' ) => 'First name is required.',
			)
		);

		$this->assertSame(
			'First name is required.',
			MethodLabels::translate_error_notice( 'Billing first name is required.' )
		);
	}

	public function test_translates_store_api_error_response_messages(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-notice', 'error', 'Billing first name is required.' ) => 'First name is required.',
				StringTranslator::hash( 'wc-notice', 'error', 'Invalid coupon.' )                  => 'Coupon is invalid.',
			)
		);

		$response = $this->response(
			array(
				'message'           => 'Billing first name is required.',
				'additional_errors' => array(
					array( 'message' => 'Invalid coupon.' ),
				),
			)
		);

		MethodLabels::translate_store_api_response_messages( $response, null, $this->request( '/wc/store/v1/checkout' ) );

		$data = $response->get_data();
		$this->assertSame( 'First name is required.', $data['message'] );
		$this->assertSame( 'Coupon is invalid.', $data['additional_errors'][0]['message'] );
	}

	public function test_translates_store_api_notice_arrays_by_type(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-notice', 'success', 'Coupon applied.' ) => 'Coupon accepted.',
				StringTranslator::hash( 'wc-notice', 'error', 'Stock is low.' )     => 'Low stock.',
			)
		);

		$response = $this->response(
			array(
				'notices' => array(
					'success' => array(
						array( 'notice' => 'Coupon applied.' ),
					),
					'error'   => array(
						array( 'message' => 'Stock is low.' ),
					),
				),
			)
		);

		MethodLabels::translate_store_api_response_messages( $response, null, $this->request( '/wc/store/v1/cart' ) );

		$data = $response->get_data();
		$this->assertSame( 'Coupon accepted.', $data['notices']['success'][0]['notice'] );
		$this->assertSame( 'Low stock.', $data['notices']['error'][0]['message'] );
	}

	public function test_leaves_non_store_api_response_messages_unchanged(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-notice', 'error', 'Billing first name is required.' ) => 'First name is required.',
			)
		);

		$response = $this->response( array( 'message' => 'Billing first name is required.' ) );

		MethodLabels::translate_store_api_response_messages( $response, null, $this->request( '/wc/v3/orders' ) );

		$this->assertSame( 'Billing first name is required.', $response->get_data()['message'] );
	}

	public function test_translates_coupon_label_and_description(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-coupon-label', 'code:SUMMER', 'Coupon: SUMMER' )        => 'Promo: SUMMER',
				StringTranslator::hash( 'wc-coupon-description', 'code:SUMMER', 'Summer discount' ) => 'Seasonal discount',
			)
		);

		$coupon = new class {
			public function get_code(): string {
				return 'SUMMER';
			}
		};

		$this->assertSame(
			'Promo: SUMMER',
			MethodLabels::translate_coupon_label( 'Coupon: SUMMER', $coupon )
		);
		$this->assertSame(
			'Seasonal discount',
			MethodLabels::translate_coupon_description( 'Summer discount', $coupon )
		);
	}

	public function test_translates_order_status_labels(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-order-status', 'wc-awaiting-shipment', 'Awaiting shipment' ) => 'Waiting to ship',
			)
		);

		$this->assertSame(
			array( 'wc-awaiting-shipment' => 'Waiting to ship' ),
			MethodLabels::translate_order_statuses( array( 'wc-awaiting-shipment' => 'Awaiting shipment' ) )
		);
	}

	public function test_translates_product_download_names_without_mutating_original_object(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-download-name', '99:manual', 'Manual PDF' ) => 'Guide PDF',
			)
		);

		$product = new class {
			public function get_id(): int {
				return 99;
			}
		};
		$download = new class {
			private string $name = 'Manual PDF';

			public function get_id(): string {
				return 'manual';
			}

			public function get_name(): string {
				return $this->name;
			}

			public function set_name( string $name ): void {
				$this->name = $name;
			}
		};

		$translated = MethodLabels::translate_product_downloads( array( 'manual' => $download ), $product );

		$this->assertSame( 'Manual PDF', $download->get_name() );
		$this->assertSame( 'Guide PDF', $translated['manual']->get_name() );
	}

	public function test_translates_order_downloadable_item_names_and_file_names(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-download-name', '99:manual', 'Manual PDF' ) => 'Guide PDF',
			)
		);

		$downloads = MethodLabels::translate_order_downloadable_items(
			array(
				array(
					'download_id'   => 'manual',
					'download_name' => 'Manual PDF',
					'product_id'    => 99,
					'file'          => array( 'name' => 'Manual PDF', 'file' => '/tmp/manual.pdf' ),
				),
			)
		);

		$this->assertSame( 'Guide PDF', $downloads[0]['download_name'] );
		$this->assertSame( 'Guide PDF', $downloads[0]['file']['name'] );
	}

	public function test_translates_download_response_filename(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-download-filename', '99', 'manual.pdf' ) => 'guide.pdf',
			)
		);

		$this->assertSame(
			'guide.pdf',
			MethodLabels::translate_download_filename( 'manual.pdf', 99 )
		);
	}

	public function test_default_language_returns_source_label(): void {
		CurrentLanguage::set( 'ka' );
		$this->set_compiled_map(
			'ka',
			array(
				StringTranslator::hash( 'wc-payment-title', 'cod', 'Cash on delivery' ) => 'Cash',
			)
		);

		$this->assertSame(
			'Cash on delivery',
			MethodLabels::translate_payment_title( 'Cash on delivery', 'cod' )
		);
	}

	/**
	 * @param array<string, string> $map
	 */
	private function set_compiled_map( string $language, array $map ): void {
		$ref      = new \ReflectionProperty( StringTranslator::class, 'compiled');
		$compiled = $ref->getValue();
		if ( ! is_array( $compiled ) ) {
			$compiled = array();
		}
		$compiled[ $language ] = $map;
		$ref->setValue( null, $compiled );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function response( array $data ): object {
		return new class( $data ) {
			/** @param array<string,mixed> $data */
			public function __construct( private array $data ) {}

			/** @return array<string,mixed> */
			public function get_data(): array {
				return $this->data;
			}

			/** @param array<string,mixed> $data */
			public function set_data( array $data ): void {
				$this->data = $data;
			}
		};
	}

	private function request( string $route ): object {
		return new class( $route ) {
			public function __construct( private string $route ) {}

			public function get_route(): string {
				return $this->route;
			}
		};
	}
}
