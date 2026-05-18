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
}
