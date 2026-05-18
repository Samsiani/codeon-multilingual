<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Woo\ProductSync;

/**
 * @covers \Samsiani\CodeonMultilingual\Woo\ProductSync
 */
final class WooProductSyncTest extends TestCase {

	/** @var array<string, array<int, int>> */
	private array $updated_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		TranslationGroups::flush();
		$this->updated_meta = array();

		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';

			/** @param mixed ...$args */
			public function prepare( string $sql, ...$args ): string {
				foreach ( $args as $arg ) {
					$sql = preg_replace( '/%d/', (string) (int) $arg, $sql, 1 );
					$sql = preg_replace( '/%s/', "'" . (string) $arg . "'", $sql, 1 );
				}

				return $sql;
			}

			public function get_row( string $sql ): ?object {
				if ( str_contains( $sql, 'post_id = 201' ) ) {
					return (object) array( 'group_id' => 201, 'language' => 'en' );
				}
				if ( str_contains( $sql, 'post_id = 301' ) ) {
					return (object) array( 'group_id' => 301, 'language' => 'en' );
				}
				if ( str_contains( $sql, 'post_id = 401' ) ) {
					return (object) array( 'group_id' => 401, 'language' => 'en' );
				}

				return null;
			}

			/** @return array<int, object> */
			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'group_id = 201' ) ) {
					return array(
						(object) array( 'post_id' => 201, 'language' => 'en' ),
						(object) array( 'post_id' => 202, 'language' => 'ka' ),
					);
				}
				if ( str_contains( $sql, 'group_id = 301' ) ) {
					return array(
						(object) array( 'post_id' => 301, 'language' => 'en' ),
						(object) array( 'post_id' => 302, 'language' => 'ka' ),
					);
				}
				if ( str_contains( $sql, 'group_id = 401' ) ) {
					return array(
						(object) array( 'post_id' => 401, 'language' => 'en' ),
					);
				}

				return array();
			}
		};
	}

	protected function tearDown(): void {
		TranslationGroups::flush();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_translation_created_remaps_product_relationship_meta_with_source_fallback(): void {
		Functions\expect( 'get_post_type' )
			->once()
			->with( 101 )
			->andReturn( 'product' );

		Functions\expect( 'get_post_meta' )
			->times( 3 )
			->andReturnUsing(
				static function ( int $post_id, string $key, bool $single ): array {
					self::assertSame( 101, $post_id );
					self::assertTrue( $single );

					return match ( $key ) {
						'_upsell_ids'    => array( 201, 401 ),
						'_crosssell_ids' => array( '301' ),
						'_children'      => array( 301, 999 ),
						default          => array(),
					};
				}
			);

		Functions\expect( 'update_post_meta' )
			->times( 3 )
			->andReturnUsing(
				function ( int $post_id, string $key, array $value ): bool {
					$this->assertSame( 102, $post_id );
					$this->updated_meta[ $key ] = $value;
					return true;
				}
			);

		ProductSync::on_translation_created( 102, 101, 'ka', 101 );

		$this->assertSame(
			array(
				'_upsell_ids'    => array( 202, 401 ),
				'_crosssell_ids' => array( 302 ),
				'_children'      => array( 302, 999 ),
			),
			$this->updated_meta
		);
	}

	public function test_translation_created_ignores_non_product_sources(): void {
		Functions\expect( 'get_post_type' )
			->once()
			->with( 101 )
			->andReturn( 'post' );

		Functions\expect( 'get_post_meta' )->never();
		Functions\expect( 'update_post_meta' )->never();

		ProductSync::on_translation_created( 102, 101, 'ka', 101 );

		$this->assertSame( array(), $this->updated_meta );
	}
}
