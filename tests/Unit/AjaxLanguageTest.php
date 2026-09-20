<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Url\AjaxLanguage;

/**
 * @covers \Samsiani\CodeonMultilingual\Url\AjaxLanguage
 * @covers \Samsiani\CodeonMultilingual\Core\CurrentLanguage
 */
final class AjaxLanguageTest extends TestCase {

	/** @var array<string, mixed> */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->server_backup = $_SERVER;
		$_GET                = array();
		$_POST               = array();

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return 'home' === $name ? 'https://example.test' : $default;
			}
		);
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'did_action' )->justReturn( 1 );

		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';

			/** @return array<int, object> */
			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'cml_languages' ) ) {
					return array(
						(object) array( 'code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native' => 'ქართული', 'flag' => 'ge', 'rtl' => 0, 'active' => 1, 'is_default' => 1, 'position' => 0 ),
						(object) array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'flag' => 'us', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 1 ),
						(object) array( 'code' => 'ru', 'locale' => 'ru_RU', 'name' => 'Russian', 'native' => 'Русский', 'flag' => 'ru', 'rtl' => 0, 'active' => 0, 'is_default' => 0, 'position' => 2 ),
					);
				}
				return array();
			}
		};

		Languages::flush_cache();
		CurrentLanguage::reset();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		$_GET    = array();
		$_POST   = array();
		CurrentLanguage::reset();
		Languages::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function admin_ajax_request( string $referer = '' ): void {
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_X_CODEON_LANGUAGE'] );
		if ( '' !== $referer ) {
			$_SERVER['HTTP_REFERER'] = $referer;
		}
	}

	public function test_referer_prefix_gives_the_calling_page_language(): void {
		$this->admin_ajax_request( 'https://example.test/en/my-account/' );

		$this->assertSame( 'en', AjaxLanguage::detect() );
		$this->assertSame( 'en', CurrentLanguage::code() );
	}

	public function test_default_language_referer_has_no_prefix_and_stays_default(): void {
		$this->admin_ajax_request( 'https://example.test/my-account/' );

		$this->assertNull( AjaxLanguage::detect() );
		$this->assertSame( 'ka', CurrentLanguage::code() );
	}

	public function test_wp_admin_referer_keeps_the_previous_behaviour(): void {
		$this->admin_ajax_request( 'https://example.test/wp-admin/edit.php' );

		$this->assertNull( AjaxLanguage::detect() );
		$this->assertSame( 'ka', CurrentLanguage::code() );
	}

	public function test_foreign_host_referer_is_ignored(): void {
		$this->admin_ajax_request( 'https://evil.example/en/anything/' );

		$this->assertNull( AjaxLanguage::detect() );
	}

	public function test_inactive_language_in_referer_is_ignored(): void {
		$this->admin_ajax_request( 'https://example.test/ru/my-account/' );

		$this->assertNull( AjaxLanguage::detect() );
	}

	public function test_explicit_param_wins_over_referer(): void {
		$this->admin_ajax_request( 'https://example.test/en/my-account/' );
		$_POST['cml_lang'] = 'ka';

		$this->assertSame( 'ka', AjaxLanguage::detect() );
	}

	public function test_get_param_is_read_directly_without_request_superglobal(): void {
		$this->admin_ajax_request();
		$_GET['lang'] = 'en';
		$_REQUEST     = array();

		$this->assertSame( 'en', AjaxLanguage::detect() );
	}

	public function test_header_is_honoured(): void {
		$this->admin_ajax_request();
		$_SERVER['HTTP_X_CODEON_LANGUAGE'] = 'EN';

		$this->assertSame( 'en', AjaxLanguage::detect() );
	}

	public function test_wc_ajax_reads_the_language_from_its_own_url(): void {
		$_SERVER['REQUEST_URI'] = '/en/?wc-ajax=get_refreshed_fragments';
		unset( $_SERVER['HTTP_REFERER'] );

		$this->assertSame( 'en', AjaxLanguage::detect() );
	}

	public function test_no_detection_outside_ajax(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		$this->admin_ajax_request( 'https://example.test/en/my-account/' );

		$this->assertNull( AjaxLanguage::detect() );
	}

	public function test_explicit_set_still_wins(): void {
		$this->admin_ajax_request( 'https://example.test/en/my-account/' );
		CurrentLanguage::set( 'ru' );

		$this->assertSame( 'ru', CurrentLanguage::code() );
	}
}
