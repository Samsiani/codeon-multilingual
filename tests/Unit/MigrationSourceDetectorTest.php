<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Migration\MigrationSourceDetector;
use Samsiani\CodeonMultilingual\Migration\MigrationSourceRegistry;

final class MigrationSourceDetectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_translatepress_detection_reports_table_and_option_evidence(): void {
		global $wpdb;
		$wpdb = new MigrationSourceDetectorWpdbStub( array( 'wp_trp_dictionary_en_us_ka_ge' ) );

		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return 'trp_settings' === $name ? array( 'default-language' => 'en_US' ) : $default;
			}
		);

		$report = MigrationSourceDetector::translatepress();

		$this->assertSame( 'yes', $report['detected'] );
		$this->assertSame( array( 'table prefix: trp_', 'option: trp_settings' ), $report['evidence'] );
	}

	public function test_proxy_plugin_detectors_report_options_and_active_plugins(): void {
		global $wpdb;
		$wpdb = new MigrationSourceDetectorWpdbStub();

		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				$options = array(
					'weglot_settings' => array( 'api_key_private' => 'set' ),
					'GTranslate'      => array( 'default_language' => 'en' ),
					'active_plugins'  => array( 'weglot/weglot.php', 'gtranslate/gtranslate.php' ),
				);

				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'get_site_option' )->justReturn( array() );

		$weglot     = MigrationSourceDetector::weglot();
		$gtranslate = MigrationSourceDetector::gtranslate();

		$this->assertSame( 'yes', $weglot['detected'] );
		$this->assertContains( 'option: weglot_settings', $weglot['evidence'] );
		$this->assertContains( 'plugin: weglot/weglot.php', $weglot['evidence'] );
		$this->assertSame( 'yes', $gtranslate['detected'] );
		$this->assertContains( 'option: GTranslate', $gtranslate['evidence'] );
		$this->assertContains( 'plugin: gtranslate/gtranslate.php', $gtranslate['evidence'] );
	}

	public function test_multilingualpress_reports_multisite_and_sitewide_plugin_evidence(): void {
		global $wpdb;
		$wpdb = new MigrationSourceDetectorWpdbStub();

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return 'active_plugins' === $name ? array() : $default;
			}
		);
		Functions\when( 'get_site_option' )->alias(
			static function ( string $name, $default = false ) {
				return 'active_sitewide_plugins' === $name
					? array( 'multilingualpress/multilingualpress.php' => true )
					: $default;
			}
		);

		$report = MigrationSourceDetector::multilingualpress();

		$this->assertSame( 'yes', $report['detected'] );
		$this->assertContains( 'multisite enabled', $report['evidence'] );
		$this->assertContains( 'plugin: multilingualpress/multilingualpress.php', $report['evidence'] );
	}

	public function test_inline_field_sources_detect_qtranslate_and_wpglobus_signals(): void {
		global $wpdb;
		$wpdb = new MigrationSourceDetectorWpdbStub();

		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				$options = array(
					'qtranslate_enabled_languages' => array( 'en', 'ka' ),
					'wpglobus_option'              => array( 'enabled_languages' => array( 'en', 'ka' ) ),
					'active_plugins'               => array( 'qtranslate-xt/qtranslate.php', 'wpglobus/wpglobus.php' ),
				);

				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'get_site_option' )->justReturn( array() );

		$report = MigrationSourceDetector::inline_multilingual_fields();

		$this->assertSame( 'yes', $report['detected'] );
		$this->assertContains( 'option: qtranslate_enabled_languages', $report['evidence'] );
		$this->assertContains( 'option: wpglobus_option', $report['evidence'] );
		$this->assertContains( 'plugin: qtranslate-xt/qtranslate.php', $report['evidence'] );
		$this->assertContains( 'plugin: wpglobus/wpglobus.php', $report['evidence'] );
	}

	public function test_registry_returns_cli_rows_and_detailed_evidence(): void {
		global $wpdb;
		$wpdb = new MigrationSourceDetectorWpdbStub( array( 'wp_icl_translations', 'wp_trp_dictionary_en_us_ka_ge' ) );

		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return 'active_plugins' === $name ? array( 'weglot/weglot.php' ) : $default;
			}
		);
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'is_multisite' )->justReturn( false );

		$rows   = MigrationSourceRegistry::cli_rows();
		$report = MigrationSourceRegistry::report();

		$this->assertSame(
			array( 'source', 'detected', 'status', 'coverage', 'command', 'remaining' ),
			array_keys( $rows[0] )
		);
		$this->assertSame( 'WPML', $rows[0]['source'] );
		$this->assertSame( 'yes', $rows[0]['detected'] );
		$this->assertArrayHasKey( 'evidence', $report[2] );
		$this->assertContains( 'table prefix: trp_', $report[2]['evidence'] );
		$this->assertSame( 'Weglot', $rows[3]['source'] );
		$this->assertSame( 'yes', $rows[3]['detected'] );
	}
}

final class MigrationSourceDetectorWpdbStub {

	public string $prefix        = 'wp_';
	public string $term_taxonomy = 'wp_term_taxonomy';

	/** @var array<int,string> */
	private array $tables;

	/**
	 * @param array<int,string> $tables
	 */
	public function __construct( array $tables = array() ) {
		$this->tables = $tables;
	}

	public function prepare( string $sql, ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%s/', "'" . (string) $arg . "'", $sql, 1 ) ?? $sql;
		}

		return $sql;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_var( string $sql ) {
		if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
			$pattern = $this->extract_like_pattern( $sql );
			foreach ( $this->tables as $table ) {
				if ( fnmatch( str_replace( array( '\\_', '%' ), array( '_', '*' ), $pattern ), $table ) ) {
					return $table;
				}
			}

			return false;
		}

		return 0;
	}

	private function extract_like_pattern( string $sql ): string {
		if ( 1 === preg_match( "/LIKE '([^']+)'/", $sql, $matches ) ) {
			return $matches[1];
		}

		return '';
	}
}
