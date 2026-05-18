<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Migration\WpmlMigrationSnapshot;

final class WpmlMigrationSnapshotTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_site_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_cache_get' )->justReturn( array() );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_export_snapshot_serializes_counts_rows_and_binary_hashes(): void {
		global $wpdb;
		$wpdb = $this->export_wpdb();

		$json     = WpmlMigrationSnapshot::to_json( 'polylang' );
		$snapshot = json_decode( $json, true );

		$this->assertIsArray( $snapshot );
		$this->assertSame( 'codeon-multilingual.wpml-migration-snapshot', $snapshot['format'] );
		$this->assertSame( 1, $snapshot['format_version'] );
		$this->assertSame( 'polylang', $snapshot['migration_source'] );
		$this->assertSame( 'https://example.test', $snapshot['site_url'] );
		$this->assertSame( 'wp_', $snapshot['table_prefix'] );
		$this->assertSame( 5, $snapshot['report']['total_rows'] );
		$this->assertSame( 1, $snapshot['tables']['languages']['count'] );
		$this->assertSame( 1, $snapshot['tables']['strings']['count'] );
		$this->assertSame( '0123456789abcdef0123456789abcdef', $snapshot['tables']['strings']['rows'][0]['hash'] );
		$this->assertSame( 123, $snapshot['tables']['strings']['rows'][0]['id'] );

		$validation = WpmlMigrationSnapshot::validate_json( $json );
		$this->assertTrue( $validation['valid'] );
		$this->assertSame( 5, $validation['report']['total_rows'] );
	}

	public function test_restore_requires_destructive_confirmation_before_any_write(): void {
		global $wpdb;
		$wpdb = $this->restore_wpdb();

		$result = WpmlMigrationSnapshot::restore_from_json( $this->snapshot_json() );

		$this->assertFalse( $result['restored'] );
		$this->assertStringContainsString( 'confirm_destructive is required', implode( "\n", $result['errors'] ) );
		$this->assertSame( array(), $wpdb->queries );
	}

	public function test_restore_blocks_site_mismatch_before_any_write(): void {
		global $wpdb;
		$wpdb = $this->restore_wpdb();

		$result = WpmlMigrationSnapshot::restore_from_json(
			$this->snapshot_json( array( 'site_url' => 'https://source.test' ) ),
			array( 'confirm_destructive' => true )
		);

		$this->assertFalse( $result['restored'] );
		$this->assertStringContainsString( 'does not match current site', implode( "\n", $result['errors'] ) );
		$this->assertSame( array(), $wpdb->queries );
	}

	public function test_restore_dry_run_validates_without_confirmation_or_writes(): void {
		global $wpdb;
		$wpdb = $this->restore_wpdb();

		$result = WpmlMigrationSnapshot::restore_from_json(
			$this->snapshot_json(),
			array( 'dry_run' => true )
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertFalse( $result['restored'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $wpdb->queries );
	}

	public function test_restore_replaces_tables_when_confirmed(): void {
		global $wpdb;
		$wpdb = $this->restore_wpdb();

		$result = WpmlMigrationSnapshot::restore_from_json(
			$this->snapshot_json(),
			array( 'confirm_destructive' => true )
		);

		$this->assertTrue( $result['restored'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertContains( 'START TRANSACTION', $wpdb->queries );
		$this->assertContains( 'DELETE FROM wp_cml_string_translations', $wpdb->queries );
		$this->assertContains( 'DELETE FROM wp_cml_languages', $wpdb->queries );
		$this->assertContains( 'COMMIT', $wpdb->queries );
		$this->assertNotContains( 'ROLLBACK', $wpdb->queries );
		$this->assertStringContainsString( 'UNHEX(%s)', $wpdb->prepared[2][0] );
		$this->assertSame( '0123456789abcdef0123456789abcdef', $wpdb->prepared[2][1][1] );
	}

	public function test_tampered_snapshot_count_is_rejected_before_writes(): void {
		global $wpdb;
		$wpdb = $this->restore_wpdb();

		$snapshot = json_decode( $this->snapshot_json(), true );
		$snapshot['tables']['languages']['count'] = 99;

		$result = WpmlMigrationSnapshot::restore_from_json(
			(string) json_encode( $snapshot ),
			array( 'confirm_destructive' => true )
		);

		$this->assertFalse( $result['restored'] );
		$this->assertStringContainsString( 'count does not match row data', implode( "\n", $result['errors'] ) );
		$this->assertSame( array(), $wpdb->queries );
	}

	private function snapshot_json( array $overrides = array() ): string {
		$snapshot = array_merge(
			array(
				'format'         => 'codeon-multilingual.wpml-migration-snapshot',
				'format_version' => 1,
				'plugin_version' => 'test',
				'generated_at'   => '2026-05-18T00:00:00+00:00',
				'site_url'       => 'https://example.test',
				'table_prefix'   => 'wp_',
				'report'         => array(
					'tables'     => array(),
					'total_rows' => 0,
				),
				'preflight'      => array(
					'wpml_available' => false,
					'conflicts'      => array(
						'language_settings'   => 0,
						'post_mappings'       => 0,
						'term_mappings'       => 0,
						'string_translations' => 0,
					),
				),
				'tables'         => array(
					'languages'           => array(
						'table'       => 'wp_cml_languages',
						'suffix'      => 'cml_languages',
						'columns'     => array( 'code', 'locale', 'name', 'native', 'flag', 'rtl', 'active', 'is_default', 'position' ),
						'hex_columns' => array(),
						'count'       => 1,
						'rows'        => array(
							array(
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
						),
					),
					'post_language'       => array(
						'table'       => 'wp_cml_post_language',
						'suffix'      => 'cml_post_language',
						'columns'     => array( 'post_id', 'group_id', 'language' ),
						'hex_columns' => array(),
						'count'       => 1,
						'rows'        => array(
							array(
								'post_id'  => 10,
								'group_id' => 20,
								'language' => 'en',
							),
						),
					),
					'term_language'       => array(
						'table'       => 'wp_cml_term_language',
						'suffix'      => 'cml_term_language',
						'columns'     => array( 'term_id', 'group_id', 'language' ),
						'hex_columns' => array(),
						'count'       => 0,
						'rows'        => array(),
					),
					'strings'             => array(
						'table'       => 'wp_cml_strings',
						'suffix'      => 'cml_strings',
						'columns'     => array( 'id', 'hash', 'domain', 'context', 'source', 'source_language', 'created_at' ),
						'hex_columns' => array( 'hash' ),
						'count'       => 1,
						'rows'        => array(
							array(
								'id'              => 123,
								'hash'            => '0123456789abcdef0123456789abcdef',
								'domain'          => 'default',
								'context'         => '',
								'source'          => 'Hello',
								'source_language' => 'en',
								'created_at'      => 456,
							),
						),
					),
					'string_translations' => array(
						'table'       => 'wp_cml_string_translations',
						'suffix'      => 'cml_string_translations',
						'columns'     => array( 'string_id', 'language', 'translation', 'updated_at' ),
						'hex_columns' => array(),
						'count'       => 1,
						'rows'        => array(
							array(
								'string_id'   => 123,
								'language'    => 'ka',
								'translation' => 'Gamarjoba',
								'updated_at'  => 789,
							),
						),
					),
				),
			),
			$overrides
		);

		return (string) json_encode( $snapshot );
	}

	private function export_wpdb(): object {
		return new class {
			public string $prefix = 'wp_';
			public string $posts = 'wp_posts';
			public string $term_taxonomy = 'wp_term_taxonomy';
			public string $last_error = '';

			/** @var array<string,array<int,object>> */
			private array $tables;

			public function __construct() {
				$this->tables = array(
					'wp_cml_languages'           => array(
						(object) array(
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
					),
					'wp_cml_post_language'       => array(
						(object) array(
							'post_id'  => 10,
							'group_id' => 20,
							'language' => 'en',
						),
					),
					'wp_cml_term_language'       => array(
						(object) array(
							'term_id'  => 30,
							'group_id' => 40,
							'language' => 'ka',
						),
					),
					'wp_cml_strings'             => array(
						(object) array(
							'id'              => 123,
							'hash'            => '0123456789abcdef0123456789abcdef',
							'domain'          => 'default',
							'context'         => '',
							'source'          => 'Hello',
							'source_language' => 'en',
							'created_at'      => 456,
						),
					),
					'wp_cml_string_translations' => array(
						(object) array(
							'string_id'   => 123,
							'language'    => 'ka',
							'translation' => 'Gamarjoba',
							'updated_at'  => 789,
						),
					),
				);
			}

			public function prepare( string $sql, ...$args ): string {
				if ( str_starts_with( $sql, 'SHOW TABLES LIKE' ) ) {
					return 'SHOW TABLES LIKE ' . (string) $args[0];
				}
				return $sql;
			}

			public function get_var( string $sql ) {
				if ( str_starts_with( $sql, 'SHOW TABLES LIKE ' ) ) {
					$table = substr( $sql, strlen( 'SHOW TABLES LIKE ' ) );
					return isset( $this->tables[ $table ] ) ? $table : null;
				}
				if ( preg_match( '/FROM (wp_cml_[a-z_]+)/', $sql, $matches ) ) {
					return count( $this->tables[ $matches[1] ] ?? array() );
				}
				return null;
			}

			public function get_results( string $sql ): array {
				foreach ( array_keys( $this->tables ) as $table ) {
					if ( str_contains( $sql, 'FROM ' . $table ) ) {
						return $this->tables[ $table ];
					}
				}
				return array();
			}
		};
	}

	private function restore_wpdb(): object {
		return new class {
			public string $prefix = 'wp_';
			public string $last_error = '';
			/** @var array<int,string> */
			public array $queries = array();
			/** @var array<int,array{0:string,1:array<int,mixed>}> */
			public array $prepared = array();

			public function prepare( string $sql, ...$args ): string {
				$this->prepared[] = array( $sql, $args );
				return $sql;
			}

			public function query( string $sql ) {
				$this->queries[] = $sql;
				return 1;
			}
		};
	}
}
