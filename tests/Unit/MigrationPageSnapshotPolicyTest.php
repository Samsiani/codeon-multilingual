<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Admin\Pages\MigrationPage;

final class MigrationPageSnapshotPolicyTest extends TestCase {

	private string $tmp_dir;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->tmp_dir = sys_get_temp_dir() . '/cml-snapshot-test-' . uniqid( '', true );
		mkdir( $this->tmp_dir, 0777, true );

		Functions\when( 'get_site_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $key ): string => strtolower( (string) preg_replace( '/[^a-z0-9_-]/', '', $key ) )
		);
		Functions\when( 'trailingslashit' )->alias(
			static fn( string $path ): string => rtrim( $path, '/\\' ) . '/'
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( '11111111-2222-3333-4444-555555555555' );
		Functions\when( 'wp_upload_dir' )->alias(
			fn(): array => array(
				'basedir' => $this->tmp_dir,
				'error'   => false,
			)
		);
		Functions\when( 'wp_mkdir_p' )->alias(
			static fn( string $path ): bool => is_dir( $path ) || mkdir( $path, 0777, true )
		);

		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';

			public function prepare( string $sql, ...$args ): string {
				return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), $args );
			}

			/**
			 * @return string|int|null
			 */
			public function get_var( string $sql ) {
				if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
					foreach ( array( 'cml_languages', 'cml_post_language', 'cml_term_language', 'cml_strings', 'cml_string_translations' ) as $suffix ) {
						if ( str_contains( $sql, $suffix ) ) {
							return 'wp_' . $suffix;
						}
					}
					return null;
				}

				return 0;
			}

			/** @return array<int, object> */
			public function get_results( string $sql ): array {
				unset( $sql );
				return array();
			}
		};
	}

	protected function tearDown(): void {
		$this->delete_tree( $this->tmp_dir );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_admin_import_snapshot_writes_source_tagged_snapshot_before_import(): void {
		$result = MigrationPage::create_admin_import_snapshot( 'polylang' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'polylang', $result['source'] );
		$this->assertSame( 0, $result['rows'] );
		$this->assertFileExists( $result['path'] );
		$this->assertStringContainsString( 'codeon-before-polylang-', $result['file'] );

		$json = json_decode( (string) file_get_contents( $result['path'] ), true );
		$this->assertIsArray( $json );
		$this->assertSame( 'polylang', $json['migration_source'] );
		$this->assertFileExists( dirname( $result['path'] ) . '/.htaccess' );
		$this->assertFileExists( dirname( $result['path'] ) . '/index.html' );
	}

	private function delete_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = scandir( $path );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$child = $path . '/' . $item;
			if ( is_dir( $child ) ) {
				$this->delete_tree( $child );
			} else {
				unlink( $child );
			}
		}

		rmdir( $path );
	}
}
