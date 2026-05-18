<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Migration\PolylangImporter;

final class PolylangImporterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

			$GLOBALS['cml_polylang_test_option'] = array( 'default_lang' => 'en' );
			Functions\when( 'get_option' )->alias(
				static function ( string $name, $default = false ) {
					return 'polylang' === $name ? $GLOBALS['cml_polylang_test_option'] : false;
				}
			);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cml_polylang_test_option'] );
		Languages::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_summary_counts_polylang_relationship_sources(): void {
		global $wpdb;
		$wpdb = new PolylangImporterWpdbStub();

		$summary = PolylangImporter::summary();

		$this->assertSame( 2, $summary['languages'] );
		$this->assertSame( 3, $summary['posts'] );
		$this->assertSame( 4, $summary['terms'] );
		$this->assertSame( 2, $summary['strings'] );
		$this->assertSame( 'en', $summary['default_language'] );
		$this->assertSame( 0, array_sum( $summary['conflicts'] ) );
		$this->assertNotEmpty( $summary['warnings'] );
	}

	public function test_import_languages_parses_polylang_language_description(): void {
		global $wpdb;
		$wpdb = new PolylangImporterWpdbStub();

		$this->assertSame( 2, PolylangImporter::import_languages() );

		$this->assertCount( 2, $wpdb->inserts );
		$this->assertSame( 'en', $wpdb->inserts[0]['data']['code'] );
		$this->assertSame( 'en_US', $wpdb->inserts[0]['data']['locale'] );
		$this->assertSame( 'us', $wpdb->inserts[0]['data']['flag'] );
		$this->assertSame( 1, $wpdb->inserts[0]['data']['is_default'] );
		$this->assertSame( 'ka', $wpdb->inserts[1]['data']['code'] );
		$this->assertSame( 0, $wpdb->inserts[1]['data']['is_default'] );
	}

	public function test_import_post_and_term_queries_use_polylang_taxonomies(): void {
		global $wpdb;
		$wpdb = new PolylangImporterWpdbStub();

		$this->assertSame( 14, PolylangImporter::import_post_translations() );
		$this->assertSame( 14, PolylangImporter::import_term_translations() );

		$sql = implode( "\n\n", $wpdb->queries );

		$this->assertStringContainsString( 'UPDATE wp_cml_post_language cml', $sql );
		$this->assertStringContainsString( 'INSERT IGNORE INTO wp_cml_post_language', $sql );
		$this->assertStringContainsString( 'cml.group_id = cml.post_id', $sql );
		$this->assertStringContainsString( "tt_lang.taxonomy = 'language'", $sql );
		$this->assertStringContainsString( "tt_group.taxonomy = 'post_translations'", $sql );
		$this->assertStringContainsString( 'UPDATE wp_cml_term_language cml', $sql );
		$this->assertStringContainsString( 'INSERT IGNORE INTO wp_cml_term_language', $sql );
		$this->assertStringContainsString( 'cml.group_id = cml.term_id', $sql );
		$this->assertStringContainsString( "tt_lang.taxonomy = 'term_language'", $sql );
		$this->assertStringContainsString( "tt_group.taxonomy = 'term_translations'", $sql );
	}

	public function test_import_all_flushes_language_cache_before_setting_imported_default(): void {
		global $wpdb;
		$wpdb = new PolylangImporterWpdbStub();
		$GLOBALS['cml_polylang_test_option'] = array( 'default_lang' => 'ka' );

		$wpdb->languages = array(
			'en' => (object) array(
				'code'       => 'en',
				'locale'     => 'en_US',
				'name'       => 'English',
				'native'     => 'English',
				'flag'       => 'us',
				'rtl'        => 0,
				'active'     => 1,
				'is_default' => 1,
				'position'   => 0,
			),
		);

		// Warm Languages::all() before the importer inserts the new Polylang
		// language rows. The importer must clear this cache before calling
		// set_default_language(), otherwise the new default code is invisible.
		$this->assertArrayHasKey( 'en', Languages::all() );
		$this->assertArrayNotHasKey( 'ka', Languages::all() );

		$result = PolylangImporter::import_all();

		$this->assertSame( array(), $result['errors'] );
		$this->assertTrue( $result['default_set'] );
		$this->assertSame( 1, (int) $wpdb->languages['ka']->is_default );
		$this->assertSame( 0, (int) $wpdb->languages['en']->is_default );
	}

	public function test_import_all_requires_taxonomy_backed_polylang_rows(): void {
		global $wpdb;
		$wpdb = new PolylangImporterWpdbStub();
		$wpdb->taxonomy_counts['language']      = 0;
		$wpdb->taxonomy_counts['term_language'] = 0;

		Functions\when( 'pll_languages_list' )->justReturn( array( 'en', 'ka' ) );

		$this->assertFalse( PolylangImporter::is_available() );
		$this->assertTrue( PolylangImporter::is_detected() );

		$result = PolylangImporter::import_all();

		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 0, $result['languages'] );
		$this->assertSame( array(), $wpdb->inserts );
	}

	public function test_allow_conflicts_does_not_rewrite_language_defaults(): void {
		global $wpdb;
		$wpdb                        = new PolylangImporterWpdbStub();
		$wpdb->has_language_conflict = true;
		$wpdb->languages['en']       = (object) array(
			'code'       => 'en',
			'locale'     => 'en_GB',
			'name'       => 'English',
			'native'     => 'English',
			'flag'       => 'us',
			'rtl'        => 0,
			'active'     => 1,
			'is_default' => 1,
			'position'   => 0,
		);

		$result = PolylangImporter::import_all( true );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 1, $result['conflicts']['language_settings'] );
		$this->assertFalse( $result['default_set'] );
		$this->assertSame( 0, $wpdb->language_updates );
	}
}

final class PolylangImporterWpdbStub {

	public string $prefix             = 'wp_';
	public string $posts              = 'wp_posts';
	public string $terms              = 'wp_terms';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $last_error         = '';
	public int $rows_affected         = 7;

	/** @var array<int,string> */
	public array $queries = array();

	/** @var array<int,array{table:string,data:array<string,mixed>}> */
	public array $inserts = array();

	/** @var array<string,object> */
	public array $languages = array();

	/** @var array<string,int> */
	public array $taxonomy_counts = array(
		'language'      => 2,
		'term_language' => 2,
	);

	public bool $has_language_conflict = false;
	public int $language_updates       = 0;

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
		if ( str_contains( $sql, "taxonomy = 'language'" ) && str_contains( $sql, 'COUNT(*) FROM wp_term_taxonomy' ) ) {
			return $this->taxonomy_counts['language'];
		}
		if ( str_contains( $sql, "taxonomy = 'term_language'" ) && str_contains( $sql, 'COUNT(*) FROM wp_term_taxonomy' ) ) {
			return $this->taxonomy_counts['term_language'];
		}
		if ( str_contains( $sql, 'cml_post_language' ) || str_contains( $sql, 'cml_term_language' ) ) {
			return 0;
		}
		if ( str_contains( $sql, 'cml_languages WHERE code' ) ) {
			foreach ( array_keys( $this->languages ) as $code ) {
				if ( str_contains( $sql, "'" . $code . "'" ) ) {
					return 1;
				}
			}
			return 0;
		}
		if ( str_contains( $sql, "post_type = 'polylang_mo'" ) ) {
			return 2;
		}
		if ( str_contains( $sql, 'p.post_status NOT IN' ) ) {
			return 3;
		}
		if ( str_contains( $sql, 'real_term.term_id AS term_id' ) ) {
			return 4;
		}

		return 0;
	}

	public function get_row( string $sql ) {
		if ( $this->has_language_conflict && str_contains( $sql, 'FROM wp_cml_languages' ) && str_contains( $sql, "code = 'en'" ) ) {
			return (object) array(
				'locale'     => 'en_GB',
				'name'       => 'English',
				'native'     => 'English',
				'active'     => 1,
				'is_default' => 1,
			);
		}

		return null;
	}

	public function get_results( string $sql ): array {
		if ( str_contains( $sql, 'SELECT * FROM wp_cml_languages' ) ) {
			return array_values( $this->languages );
		}

		if ( str_contains( $sql, "tt.taxonomy = 'language'" ) ) {
			return array(
				(object) array(
					'slug'        => 'en',
					'name'        => 'English',
					'description' => serialize(
						array(
							'locale'    => 'en_US',
							'flag_code' => 'us',
							'rtl'       => 0,
						)
					),
				),
				(object) array(
					'slug'        => 'ka',
					'name'        => 'ქართული',
					'description' => serialize(
						array(
							'locale'    => 'ka_GE',
							'flag_code' => 'ge',
							'rtl'       => 0,
						)
					),
				),
			);
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<int,string>  $format
	 */
	public function insert( string $table, array $data, array $format ) {
		$this->inserts[] = array(
			'table' => $table,
			'data'  => $data,
		);

		if ( 'wp_cml_languages' === $table ) {
			$this->languages[ (string) $data['code'] ] = (object) $data;
		}

		return 1;
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $where
	 * @param array<int,string>  $format
	 * @param array<int,string>  $where_format
	 */
	public function update( string $table, array $data, array $where, array $format, array $where_format ) {
		if ( 'wp_cml_languages' === $table ) {
			++$this->language_updates;
			foreach ( $this->languages as $code => $language ) {
				$matches = true;
				foreach ( $where as $where_key => $where_value ) {
					if ( (string) ( $language->{$where_key} ?? '' ) !== (string) $where_value ) {
						$matches = false;
						break;
					}
				}
				if ( ! $matches ) {
					continue;
				}
				foreach ( $data as $key => $value ) {
					$this->languages[ $code ]->{$key} = $value;
				}
			}
		}

		return 1;
	}

	public function query( string $sql ) {
		$this->queries[] = $sql;
		return 1;
	}
}
