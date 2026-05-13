<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Frontend\HtmlLangAttribute;

/**
 * @covers \Samsiani\CodeonMultilingual\Frontend\HtmlLangAttribute::filter
 */
final class HtmlLangAttributeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';

			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'cml_languages' ) ) {
					return array(
						(object) array( 'code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native' => 'ქართული', 'flag' => 'ka', 'rtl' => 0, 'active' => 1, 'is_default' => 1, 'position' => 0 ),
						(object) array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English',  'native' => 'English',  'flag' => 'en', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 1 ),
					);
				}
				return array();
			}
		};

		\Samsiani\CodeonMultilingual\Core\Languages::flush_cache();
		\Samsiani\CodeonMultilingual\Core\CurrentLanguage::reset();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		\Samsiani\CodeonMultilingual\Core\CurrentLanguage::reset();
		parent::tearDown();
	}

	public function test_replaces_lang_with_no_leading_whitespace(): void {
		\Samsiani\CodeonMultilingual\Core\CurrentLanguage::set( 'en' );
		$out = HtmlLangAttribute::filter( 'lang="ka-GE"' );
		$this->assertSame( 'lang="en-US"', $out );
	}

	public function test_replaces_lang_when_after_other_attribute(): void {
		\Samsiani\CodeonMultilingual\Core\CurrentLanguage::set( 'en' );
		$out = HtmlLangAttribute::filter( 'dir="ltr" lang="ka-GE"' );
		$this->assertSame( 'dir="ltr" lang="en-US"', $out );
	}

	public function test_preserves_default_when_no_current_language_set(): void {
		// Defaults to site default (ka). Locale → ka-GE.
		$out = HtmlLangAttribute::filter( 'lang="en-US"' );
		$this->assertSame( 'lang="ka-GE"', $out );
	}

	public function test_appends_lang_when_attribute_absent(): void {
		\Samsiani\CodeonMultilingual\Core\CurrentLanguage::set( 'en' );
		$out = HtmlLangAttribute::filter( '' );
		$this->assertSame( 'lang="en-US"', $out );
	}

	public function test_passes_through_when_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		\Samsiani\CodeonMultilingual\Core\CurrentLanguage::set( 'en' );
		$out = HtmlLangAttribute::filter( 'lang="ka-GE"' );
		$this->assertSame( 'lang="ka-GE"', $out );
	}
}
