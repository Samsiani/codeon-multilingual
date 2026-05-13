<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Url\SubdirectoryStrategy;

/**
 * @covers \Samsiani\CodeonMultilingual\Url\SubdirectoryStrategy
 *
 * Languages is a real static class; we stub the functions it consults via
 * Brain Monkey at the WP function layer (get_option, etc.) plus a small set of
 * pre-seeded internal state.
 */
final class SubdirectoryStrategyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );

		Functions\when( 'get_option' )->alias( static function ( $key, $default = null ) {
			if ( 'home' === $key ) {
				return 'https://example.test';
			}
			return $default;
		} );

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		// Stub Languages::all() via a tiny fake table. The class is final so we
		// can't mock — we monkey-patch the wpdb that all() reads from.
		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';

			/** @return array<int, object> */
			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'cml_languages' ) ) {
					return [
						(object) [ 'code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native' => 'ქართული', 'flag' => 'ka', 'rtl' => 0, 'active' => 1, 'is_default' => 1, 'position' => 0 ],
						(object) [ 'code' => 'en', 'locale' => 'en_US', 'name' => 'English',  'native' => 'English',  'flag' => 'en', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 1 ],
					];
				}
				return [];
			}
		};

		// Force Languages cache reset between test runs.
		\Samsiani\CodeonMultilingual\Core\Languages::flush_cache();
		// Strategy memoizes home_path statically; reset via reflection.
		$ref = new \ReflectionProperty( SubdirectoryStrategy::class, 'home_path_cache' );
		$ref->setValue( null, null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_detect_matches_active_non_default_language(): void {
		$_SERVER['REQUEST_URI'] = '/en/cars/';
		$strategy = new SubdirectoryStrategy();
		$this->assertSame( 'en', $strategy->detect() );
	}

	public function test_detect_returns_null_for_default_language_prefix(): void {
		// ka is default — should not be detected as a prefix.
		$_SERVER['REQUEST_URI'] = '/ka/cars/';
		$strategy = new SubdirectoryStrategy();
		$this->assertNull( $strategy->detect() );
	}

	public function test_detect_returns_null_for_unrelated_slug(): void {
		$_SERVER['REQUEST_URI'] = '/karate-class/';
		$strategy = new SubdirectoryStrategy();
		$this->assertNull( $strategy->detect() );
	}

	public function test_detect_returns_null_for_root(): void {
		$_SERVER['REQUEST_URI'] = '/';
		$strategy = new SubdirectoryStrategy();
		$this->assertNull( $strategy->detect() );
	}

	public function test_detect_returns_null_for_unknown_language(): void {
		$_SERVER['REQUEST_URI'] = '/fr/cars/';
		$strategy = new SubdirectoryStrategy();
		$this->assertNull( $strategy->detect() );
	}

	public function test_build_url_inserts_prefix_for_non_default(): void {
		$strategy = new SubdirectoryStrategy();
		$this->assertSame(
			'https://example.test/en/cars/',
			$strategy->build_url( 'https://example.test/cars/', 'en' )
		);
	}

	public function test_build_url_is_idempotent(): void {
		$strategy = new SubdirectoryStrategy();
		$already  = 'https://example.test/en/cars/';
		$this->assertSame( $already, $strategy->build_url( $already, 'en' ) );
	}

	public function test_build_url_skips_default_language(): void {
		$strategy = new SubdirectoryStrategy();
		$this->assertSame(
			'https://example.test/cars/',
			$strategy->build_url( 'https://example.test/cars/', 'ka' )
		);
	}

	public function test_strip_lang_prefix_removes_active_lang(): void {
		$strategy = new SubdirectoryStrategy();
		$this->assertSame(
			'https://example.test/cars/',
			$strategy->strip_lang_prefix( 'https://example.test/en/cars/' )
		);
	}

	public function test_strip_lang_prefix_keeps_non_lang_segment(): void {
		$strategy = new SubdirectoryStrategy();
		$this->assertSame(
			'https://example.test/karate/',
			$strategy->strip_lang_prefix( 'https://example.test/karate/' )
		);
	}

	public function test_strip_from_request_rewrites_request_uri(): void {
		$_SERVER['REQUEST_URI'] = '/en/cars/?orderby=date';
		$strategy = new SubdirectoryStrategy();
		$strategy->strip_from_request();
		$this->assertSame( '/cars/?orderby=date', $_SERVER['REQUEST_URI'] );
	}

	public function test_strip_from_request_handles_no_trailing_slash(): void {
		$_SERVER['REQUEST_URI'] = '/en';
		$strategy = new SubdirectoryStrategy();
		$strategy->strip_from_request();
		$this->assertSame( '/', $_SERVER['REQUEST_URI'] );
	}
}
