<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Context fallback for `_x()` lookups.
 *
 * TranslatePress (and PO catalogs round-tripped through a translation memory)
 * never record the gettext context, so an imported catalog only ever contains
 * context-less entries. Without a fallback, a theme calling
 * `_x( 'Make', 'Car information field', 'woocommerce' )` would miss the
 * imported `(woocommerce, '', 'Make')` translation and render English.
 *
 * @covers \Samsiani\CodeonMultilingual\Strings\StringTranslator::on_gettext_with_context
 */
final class StringsContextFallbackTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// Settings fall back to their defaults, so the context fallback is on
		// and string auto-discovery is off — the shipped configuration.
		Functions\when( 'get_option' )->justReturn( array() );

		global $wpdb;
		$wpdb = new class {
			public string $prefix         = 'wp_';
			public int    $rows_affected  = 0;

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
						(object) array(
							'code'       => 'ka',
							'locale'     => 'ka_GE',
							'name'       => 'Georgian',
							'native'     => 'ქართული',
							'flag'       => 'ge',
							'rtl'        => 0,
							'active'     => 1,
							'is_default' => 1,
							'position'   => 0,
						),
						(object) array(
							'code'       => 'en',
							'locale'     => 'en_US',
							'name'       => 'English',
							'native'     => 'English',
							'flag'       => 'us',
							'rtl'        => 0,
							'active'     => 1,
							'is_default' => 0,
							'position'   => 1,
						),
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

	public function test_context_call_falls_back_to_context_less_translation(): void {
		// Exactly what importing TranslatePress produces: no context recorded.
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'woocommerce', '', 'Make' ) => 'Marque',
			)
		);

		$this->assertSame(
			'Marque',
			StringTranslator::on_gettext_with_context( 'Make', 'Make', 'Car information field', 'woocommerce' )
		);
	}

	public function test_context_specific_translation_wins_over_context_less(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'woocommerce', '', 'Make' )                      => 'Marque',
				StringTranslator::hash( 'woocommerce', 'Car information field', 'Make' ) => 'Manufacturer',
			)
		);

		$this->assertSame(
			'Manufacturer',
			StringTranslator::on_gettext_with_context( 'Make', 'Make', 'Car information field', 'woocommerce' )
		);
	}

	public function test_fallback_does_not_cross_text_domains(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'some-plugin', '', 'Make' ) => 'Marque',
			)
		);

		$this->assertSame(
			'Make',
			StringTranslator::on_gettext_with_context( 'Make', 'Make', 'Car information field', 'woocommerce' )
		);
	}

	public function test_plain_gettext_is_unaffected_by_the_fallback(): void {
		$this->set_compiled_map(
			'en',
			array(
				StringTranslator::hash( 'woocommerce', '', 'Car Information' ) => 'Vehicle details',
			)
		);

		$this->assertSame(
			'Vehicle details',
			StringTranslator::on_gettext( 'Car Information', 'Car Information', 'woocommerce' )
		);
	}

	public function test_missing_translation_returns_the_original(): void {
		$this->set_compiled_map( 'en', array() );

		$this->assertSame(
			'Shipline Name',
			StringTranslator::on_gettext_with_context( 'Shipline Name', 'Shipline Name', 'Car information field', 'woocommerce' )
		);
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
