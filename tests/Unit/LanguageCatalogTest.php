<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\LanguageCatalog;

/**
 * Exercise the bundled catalog: load, lookup, search, locale fallback, flag emoji.
 *
 * The catalog is a static dataset (res/languages.json); these tests assert
 * the contract is stable for the rows the wizard depends on.
 */
final class LanguageCatalogTest extends TestCase {

	protected function tearDown(): void {
		LanguageCatalog::flush_cache();
		parent::tearDown();
	}

	public function test_catalog_is_non_empty_and_well_shaped(): void {
		$all = LanguageCatalog::all();
		$this->assertGreaterThan( 50, count( $all ), 'Catalog should ship with at least 50 languages.' );

		foreach ( $all as $entry ) {
			$this->assertArrayHasKey( 'code', $entry );
			$this->assertArrayHasKey( 'name', $entry );
			$this->assertArrayHasKey( 'native', $entry );
			$this->assertArrayHasKey( 'locale', $entry );
			$this->assertArrayHasKey( 'flag', $entry );
			$this->assertArrayHasKey( 'rtl', $entry );
			$this->assertArrayHasKey( 'major', $entry );
			$this->assertNotSame( '', $entry['code'] );
			$this->assertNotSame( '', $entry['name'] );
		}
	}

	public function test_get_returns_specific_languages(): void {
		$georgian = LanguageCatalog::get( 'ka' );
		$this->assertNotNull( $georgian );
		$this->assertSame( 'Georgian', $georgian['name'] );
		$this->assertSame( 'ქართული', $georgian['native'] );
		$this->assertSame( 'ka_GE', $georgian['locale'] );
		$this->assertSame( 'ge', $georgian['flag'] );

		$english = LanguageCatalog::get( 'en' );
		$this->assertNotNull( $english );
		$this->assertSame( 'en_US', $english['locale'] );
		$this->assertSame( 'us', $english['flag'] );

		$missing = LanguageCatalog::get( 'xx' );
		$this->assertNull( $missing );
	}

	public function test_search_matches_english_name(): void {
		$hits = LanguageCatalog::search( 'georg' );
		$codes = array_column( $hits, 'code' );
		$this->assertContains( 'ka', $codes );
	}

	public function test_search_matches_native_script(): void {
		$hits = LanguageCatalog::search( 'ქართ' );
		$codes = array_column( $hits, 'code' );
		$this->assertContains( 'ka', $codes );
	}

	public function test_search_is_case_insensitive(): void {
		$lower = LanguageCatalog::search( 'french' );
		$upper = LanguageCatalog::search( 'FRENCH' );
		$this->assertSame(
			array_column( $lower, 'code' ),
			array_column( $upper, 'code' )
		);
	}

	public function test_search_ranks_major_languages_first(): void {
		// Both Esperanto's "eo" and English's "en" contain "e"; major should win.
		$hits = LanguageCatalog::search( 'e' );
		$first_major_index = null;
		foreach ( $hits as $i => $entry ) {
			if ( 1 === $entry['major'] ) {
				$first_major_index = $i;
				break;
			}
		}
		$this->assertSame( 0, $first_major_index, 'First hit should be a major language.' );
	}

	public function test_empty_search_returns_full_catalog(): void {
		$this->assertSame( LanguageCatalog::all(), LanguageCatalog::search( '' ) );
	}

	public function test_major_returns_only_major_flagged(): void {
		$major = LanguageCatalog::major();
		$this->assertNotEmpty( $major );
		foreach ( $major as $entry ) {
			$this->assertSame( 1, $entry['major'] );
		}
	}

	public function test_by_locale_exact_match(): void {
		$ka = LanguageCatalog::by_locale( 'ka_GE' );
		$this->assertNotNull( $ka );
		$this->assertSame( 'ka', $ka['code'] );
	}

	public function test_by_locale_fallback_to_language_part(): void {
		// en_GB isn't in the catalog (we only store en_US); the prefix fallback
		// should still locate English.
		$en = LanguageCatalog::by_locale( 'en_GB' );
		$this->assertNotNull( $en );
		$this->assertSame( 'en', $en['code'] );
	}

	public function test_flag_emoji_renders_two_regional_indicator_codepoints(): void {
		// Georgia: 🇬🇪 = U+1F1EC U+1F1EA
		$flag = LanguageCatalog::flag_emoji( 'ge' );
		$this->assertSame( "\u{1F1EC}\u{1F1EA}", $flag );

		// Uppercase input is normalized.
		$this->assertSame( $flag, LanguageCatalog::flag_emoji( 'GE' ) );

		// Empty or invalid input returns ''.
		$this->assertSame( '', LanguageCatalog::flag_emoji( '' ) );
		$this->assertSame( '', LanguageCatalog::flag_emoji( 'g' ) );
		$this->assertSame( '', LanguageCatalog::flag_emoji( 'usa' ) );
		$this->assertSame( '', LanguageCatalog::flag_emoji( '12' ) );
	}

	public function test_rtl_languages_are_marked(): void {
		$ar = LanguageCatalog::get( 'ar' );
		$this->assertNotNull( $ar );
		$this->assertSame( 1, $ar['rtl'] );

		$he = LanguageCatalog::get( 'he' );
		$this->assertNotNull( $he );
		$this->assertSame( 1, $he['rtl'] );

		$en = LanguageCatalog::get( 'en' );
		$this->assertNotNull( $en );
		$this->assertSame( 0, $en['rtl'] );
	}

	public function test_flag_svg_url_returns_url_for_bundled_codes(): void {
		$url = LanguageCatalog::flag_svg_url( 'ge' );
		$this->assertNotNull( $url );
		$this->assertStringEndsWith( 'res/flags/ge.svg', (string) $url );
	}

	public function test_flag_svg_url_lowercases_input(): void {
		$lower = LanguageCatalog::flag_svg_url( 'ge' );
		$upper = LanguageCatalog::flag_svg_url( 'GE' );
		$this->assertSame( $lower, $upper );
	}

	public function test_flag_svg_url_returns_null_for_unbundled_or_invalid_codes(): void {
		// 'zz' isn't an ISO 3166 code; the others fail the length / alpha check.
		$this->assertNull( LanguageCatalog::flag_svg_url( 'zz' ) );
		$this->assertNull( LanguageCatalog::flag_svg_url( '' ) );
		$this->assertNull( LanguageCatalog::flag_svg_url( 'g' ) );
		$this->assertNull( LanguageCatalog::flag_svg_url( '12' ) );
		$this->assertNull( LanguageCatalog::flag_svg_url( 'usa' ) );
	}

	public function test_flag_svg_path_points_at_readable_svg_file(): void {
		$path = LanguageCatalog::flag_svg_path( 'us' );
		$this->assertNotNull( $path );
		$this->assertFileExists( (string) $path );
		$head = (string) file_get_contents( (string) $path, false, null, 0, 64 );
		$this->assertStringStartsWith( '<svg', $head );
	}

	public function test_flag_svg_exists_matches_path_resolution(): void {
		$this->assertTrue( LanguageCatalog::flag_svg_exists( 'ge' ) );
		$this->assertFalse( LanguageCatalog::flag_svg_exists( 'zz' ) );
	}

	public function test_every_catalog_entry_with_a_flag_code_ships_an_svg(): void {
		// Catalog and SVG bundle must travel together. If you add a language to
		// res/languages.json, drop the matching SVG into res/flags/ — otherwise
		// the wizard and switcher fall back to emoji unnecessarily.
		$missing = array();
		foreach ( LanguageCatalog::all() as $entry ) {
			if ( '' === $entry['flag'] ) {
				continue;
			}
			if ( ! LanguageCatalog::flag_svg_exists( $entry['flag'] ) ) {
				$missing[] = $entry['code'] . ' → ' . $entry['flag'];
			}
		}
		$this->assertSame( array(), $missing, 'Catalog entries missing SVG flags: ' . implode( ', ', $missing ) );
	}
}
