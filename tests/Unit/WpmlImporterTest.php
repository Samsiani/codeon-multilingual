<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Migration\WpmlImporter;

final class WpmlImporterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_option' )->alias(
			static function ( string $name ) {
				return 'icl_sitepress_settings' === $name ? array( 'default_language' => 'ka' ) : false;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_conflict_summary_counts_default_and_string_source_language_conflicts(): void {
		global $wpdb;
		$wpdb = new WpmlImporterWpdbStub();

		$conflicts = WpmlImporter::conflict_summary();
		$sql       = implode( "\n\n", $wpdb->queries );

		$this->assertSame( 2, $conflicts['language_settings'] );
		$this->assertSame( 8, $conflicts['string_translations'] );
		$this->assertStringContainsString( 'cl.is_default', $sql );
		$this->assertStringContainsString( 'CASE WHEN l.code = ', $sql );
		$this->assertStringContainsString( 'cs.source_language != COALESCE(src.language', $sql );
	}

	public function test_string_source_import_does_not_update_existing_source_language(): void {
		global $wpdb;
		$wpdb = new WpmlImporterWpdbStub();

		$this->assertSame( 6, WpmlImporter::import_string_sources() );

		$sql = implode( "\n\n", $wpdb->queries );
		$this->assertStringContainsString( 'INSERT IGNORE INTO wp_cml_strings', $sql );
		$this->assertStringNotContainsString( 'ON DUPLICATE KEY UPDATE', $sql );
	}
}

final class WpmlImporterWpdbStub {

	public string $prefix        = 'wp_';
	public string $posts         = 'wp_posts';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $last_error    = '';
	public int $rows_affected    = 6;

	/** @var array<int,string> */
	public array $queries = array();

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

	public function get_var( string $sql ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
			return 'wp_present_table';
		}

		if ( str_contains( $sql, 'FROM wp_icl_languages l' ) ) {
			return 2;
		}

		if ( str_contains( $sql, 'cml_post_language' ) || str_contains( $sql, 'cml_term_language' ) ) {
			return 0;
		}

		if ( str_contains( $sql, 'cs.source_language != COALESCE' ) ) {
			return 3;
		}

		if ( str_contains( $sql, 'st.translation != t.value' ) ) {
			return 5;
		}

		return 0;
	}

	public function query( string $sql ) {
		$this->queries[] = $sql;
		return 1;
	}
}
