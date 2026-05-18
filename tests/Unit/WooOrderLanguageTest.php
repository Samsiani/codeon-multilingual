<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;
use Samsiani\CodeonMultilingual\Woo\OrderLanguage;

/**
 * @covers \Samsiani\CodeonMultilingual\Woo\OrderLanguage
 */
final class WooOrderLanguageTest extends TestCase {

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
						(object) array( 'code' => 'ru', 'locale' => 'ru_RU', 'name' => 'Russian', 'native' => 'Русский', 'flag' => 'ru', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 2 ),
					);
				}
				return array();
			}
		};

		Languages::flush_cache();
		$this->set_compiled_map( 'en', array() );
	}

	protected function tearDown(): void {
		$this->set_compiled_map( 'en', array() );
		CurrentLanguage::reset();
		Languages::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_store_checkout_language_writes_current_language_to_order_meta(): void {
		CurrentLanguage::set( 'en' );
		$order = new class {
			/** @var array<string, string> */
			public array $meta = array();

			public function update_meta_data( string $key, string $value ): void {
				$this->meta[ $key ] = $value;
			}
		};

		OrderLanguage::store_checkout_language( $order );

		$this->assertSame( 'en', $order->meta[ OrderLanguage::META_LANGUAGE ] );
	}

	public function test_order_language_scope_restores_previous_request_language(): void {
		CurrentLanguage::set( 'ru' );
		$order = new class {
			public function get_id(): int {
				return 123;
			}

			public function get_meta( string $key, bool $single = false ): string {
				unset( $single );
				return OrderLanguage::META_LANGUAGE === $key ? 'en' : '';
			}
		};

		OrderLanguage::begin_order_language( $order );
		$this->assertSame( 'en', CurrentLanguage::code() );

		OrderLanguage::end_order_language( $order );
		$this->assertSame( 'ru', CurrentLanguage::code() );
	}

	public function test_nested_order_language_scope_restores_previous_request_language(): void {
		CurrentLanguage::set( 'ru' );
		$order = new class {
			public function get_id(): int {
				return 456;
			}

			public function get_meta( string $key, bool $single = false ): string {
				unset( $single );
				return OrderLanguage::META_LANGUAGE === $key ? 'en' : '';
			}
		};

		OrderLanguage::begin_order_language( $order );
		OrderLanguage::begin_order_language( $order );
		$this->assertSame( 'en', CurrentLanguage::code() );

		OrderLanguage::end_order_language( $order );
		$this->assertSame( 'en', CurrentLanguage::code() );

		OrderLanguage::end_order_language( $order );
		$this->assertSame( 'ru', CurrentLanguage::code() );
	}

	public function test_transactional_email_language_can_resolve_order_from_id(): void {
		CurrentLanguage::set( 'ru' );
		$order = new class {
			public function get_id(): int {
				return 789;
			}

			public function get_meta( string $key, bool $single = false ): string {
				unset( $single );
				return OrderLanguage::META_LANGUAGE === $key ? 'en' : '';
			}
		};
		Functions\when( 'wc_get_order' )->justReturn( $order );

		OrderLanguage::begin_transactional_email_language( 789 );
		$this->assertSame( 'en', CurrentLanguage::code() );

		OrderLanguage::end_transactional_email_language( 789 );
		$this->assertSame( 'ru', CurrentLanguage::code() );
	}

	public function test_email_subject_translation_uses_order_language_and_restores_request_language(): void {
		CurrentLanguage::set( 'ru' );
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'wc-email-subject', 'customer_processing_order', 'Your order is ready' ) => 'Your order is confirmed',
			)
		);

		$order = new class {
			public function get_meta( string $key, bool $single = false ): string {
				unset( $single );
				return OrderLanguage::META_LANGUAGE === $key ? 'en' : '';
			}
		};
		$email = new class {
			public string $id = 'customer_processing_order';
		};

		$this->assertSame(
			'Your order is confirmed',
			OrderLanguage::translate_email_subject( 'Your order is ready', $order, $email )
		);
		$this->assertSame( 'ru', CurrentLanguage::code() );
	}

	/**
	 * @param array<string, string> $map
	 */
	private function set_compiled_map( string $language, array $map ): void {
		$ref      = new \ReflectionProperty( StringTranslator::class, 'compiled' );
		$compiled = $ref->getValue();
		if ( ! is_array( $compiled ) ) {
			$compiled = array();
		}
		$compiled[ $language ] = $map;
		$ref->setValue( null, $compiled );
	}
}
