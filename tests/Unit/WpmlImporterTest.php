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
				static function ( string $name, $default = false ) {
					return 'icl_sitepress_settings' === $name ? array( 'default_language' => 'ka' ) : false;
				}
			);
			Functions\when( 'wp_cache_get' )->justReturn( array() );
			Functions\when( 'wp_cache_set' )->justReturn( true );
			Functions\when( 'wp_cache_delete' )->justReturn( true );
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
		$this->assertStringContainsString( 'cml.group_id = cml.post_id', $sql );
		$this->assertStringContainsString( 'cml.group_id = cml.term_id', $sql );
	}

	public function test_string_source_import_does_not_update_existing_source_language(): void {
		global $wpdb;
		$wpdb = new WpmlImporterWpdbStub();

		$this->assertSame( 6, WpmlImporter::import_string_sources() );

		$sql = implode( "\n\n", $wpdb->queries );
		$this->assertStringContainsString( 'INSERT IGNORE INTO wp_cml_strings', $sql );
		$this->assertStringNotContainsString( 'ON DUPLICATE KEY UPDATE', $sql );
	}

	public function test_post_and_term_imports_replace_default_identity_placeholders_before_insert(): void {
		global $wpdb;
		$wpdb = new WpmlImporterWpdbStub();

		$this->assertSame( 12, WpmlImporter::import_post_translations() );
		$this->assertSame( 12, WpmlImporter::import_term_translations() );

		$sql = implode( "\n\n", $wpdb->queries );
		$this->assertStringContainsString( 'UPDATE wp_cml_post_language cml', $sql );
		$this->assertStringContainsString( 'cml.group_id = cml.post_id', $sql );
		$this->assertStringContainsString( 'INSERT IGNORE INTO wp_cml_post_language', $sql );
		$this->assertStringContainsString( 'UPDATE wp_cml_term_language cml', $sql );
		$this->assertStringContainsString( 'cml.group_id = cml.term_id', $sql );
		$this->assertStringContainsString( 'INSERT IGNORE INTO wp_cml_term_language', $sql );
	}

	public function test_allow_conflicts_does_not_rewrite_language_defaults(): void {
		global $wpdb;
		$wpdb = new WpmlImporterWpdbStub();

		$result = WpmlImporter::import_all( true );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 2, $result['conflicts']['language_settings'] );
		$this->assertFalse( $result['default_set'] );
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
