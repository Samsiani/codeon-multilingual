<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Compat\BuilderData;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Url\Router;
use Samsiani\CodeonMultilingual\Url\SubdirectoryStrategy;

final class BuilderDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->set_wordpress_stubs();
		$this->set_router_strategy( new SubdirectoryStrategy() );

		global $wpdb;
		$wpdb = $this->wpdb_stub();

		Languages::flush_cache();
		TranslationGroups::flush();
		CurrentLanguage::set( 'en' );
	}

	protected function tearDown(): void {
		CurrentLanguage::reset();
		TranslationGroups::flush();
		Languages::flush_cache();
		$this->set_router_strategy( null );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_gutenberg_block_attrs_localize_explicit_ids_and_urls_only(): void {
		$blocks = array(
			array(
				'blockName'   => 'core/block',
				'attrs'       => array(
					'ref' => 10,
					'id'  => 'client-side-id',
					'url' => 'https://example.test/about/',
				),
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/query',
						'attrs'       => array( 'termId' => 5 ),
						'innerBlocks' => array(),
					),
				),
			),
		);

		$result = BuilderData::localize_blocks( $blocks, 'en' );

		$this->assertSame( 20, $result[0]['attrs']['ref'] );
		$this->assertSame( 'client-side-id', $result[0]['attrs']['id'] );
		$this->assertSame( 'https://example.test/en/about/', $result[0]['attrs']['url'] );
		$this->assertSame( 15, $result[0]['innerBlocks'][0]['attrs']['termId'] );
	}

	public function test_elementor_json_localizes_known_settings_and_preserves_widget_id(): void {
		$json = wp_json_encode(
			array(
				array(
					'id'       => 'elementor-widget-id',
					'elType'   => 'widget',
					'settings' => array(
						'template_id'    => 10,
						'selected_posts' => array( 10 ),
						'termId'         => 5,
						'button_link'    => array(
							'url' => 'https://example.test/contact/',
						),
					),
				),
			)
		);

		$result = json_decode( BuilderData::localize_json_value( (string) $json, 'en' ), true );

		$this->assertSame( 'elementor-widget-id', $result[0]['id'] );
		$this->assertSame( 20, $result[0]['settings']['template_id'] );
		$this->assertSame( array( 20 ), $result[0]['settings']['selected_posts'] );
		$this->assertSame( 15, $result[0]['settings']['termId'] );
		$this->assertSame( 'https://example.test/en/contact/', $result[0]['settings']['button_link']['url'] );
	}

	public function test_invalid_builder_json_is_returned_unchanged(): void {
		$this->assertSame( '{bad', BuilderData::localize_json_value( '{bad', 'en' ) );
	}

	public function test_acf_values_localize_supported_field_types(): void {
		$this->assertSame(
			array( 20 ),
			BuilderData::localize_acf_value( array( 10 ), 20, array( 'type' => 'relationship' ) )
		);
		$this->assertSame(
			15,
			BuilderData::localize_acf_value( 5, 20, array( 'type' => 'taxonomy' ) )
		);
		$this->assertSame(
			'https://example.test/en/page/',
			BuilderData::localize_acf_value( 'https://example.test/page/', 20, array( 'type' => 'page_link' ) )
		);
		$this->assertSame(
			'https://external.test/page/',
			BuilderData::localize_acf_value( 'https://external.test/page/', 20, array( 'type' => 'page_link' ) )
		);
	}

	public function test_serialized_builder_meta_localizes_known_values(): void {
		$serialized = serialize(
			array(
				'postId' => 10,
				'link'   => array(
					'url' => 'https://example.test/meta/',
				),
			)
		);

		$result = unserialize( BuilderData::localize_meta_value( '_elementor_page_settings', $serialized, 'en' ) );

		$this->assertSame( 20, $result['postId'] );
		$this->assertSame( 'https://example.test/en/meta/', $result['link']['url'] );
	}

	public function test_serialized_builder_meta_localizes_object_payloads(): void {
		$payload = (object) array(
			'settings' => (object) array(
				'postId' => 10,
				'link'   => (object) array(
					'url' => 'https://example.test/beaver/',
				),
			),
		);

		$result = unserialize( BuilderData::localize_meta_value( '_fl_builder_data', serialize( $payload ), 'en' ) );

		$this->assertSame( 20, $result->settings->postId );
		$this->assertSame( 'https://example.test/en/beaver/', $result->settings->link->url );
	}

	public function test_elementor_conditions_localize_keyed_and_positional_ids(): void {
		$conditions = array(
			'include' => array(
				array(
					'name'     => 'singular',
					'sub_name' => 'post',
					'sub_id'   => 10,
				),
				array(
					'name'     => 'archive',
					'sub_name' => 'category',
					'sub_id'   => '5',
				),
				array( 'include', 'singular', 'post', 10 ),
				array( 'include', 'archive', 'category', '5' ),
			),
		);

		$result = unserialize( BuilderData::localize_meta_value( '_elementor_conditions', serialize( $conditions ), 'en' ) );

		$this->assertSame( 20, $result['include'][0]['sub_id'] );
		$this->assertSame( '15', $result['include'][1]['sub_id'] );
		$this->assertSame( 20, $result['include'][2][3] );
		$this->assertSame( '15', $result['include'][3][3] );
	}

	public function test_builder_meta_update_slashes_structured_strings_before_wordpress_unslashes(): void {
		$source_json = (string) wp_json_encode(
			array(
				array(
					'settings' => array(
						'template_id' => 10,
						'title'       => 'Quote "inside"',
					),
				),
			)
		);
		$expected    = (string) wp_json_encode(
			array(
				array(
					'settings' => array(
						'template_id' => 20,
						'title'       => 'Quote "inside"',
					),
				),
			)
		);

		Functions\expect( 'get_post_meta' )
			->times( 5 )
			->andReturnUsing(
				static function ( int $post_id, string $key, bool $single ) use ( $source_json ) {
					self::assertSame( 20, $post_id );
					self::assertTrue( $single );

					return '_elementor_data' === $key ? $source_json : '';
				}
			);

		Functions\expect( 'update_post_meta' )
			->once()
			->with( 20, '_elementor_data', addslashes( $expected ) )
			->andReturn( true );

		BuilderData::localize_builder_meta_for_translation( 20, 'en' );
	}

	private function set_wordpress_stubs(): void {
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '/' ): string {
				$base = 'en' === CurrentLanguage::code() ? 'https://example.test/en/' : 'https://example.test/';
				return $base . ltrim( (string) $path, '/' );
			}
		);
		Functions\when( 'get_option' )->alias( static fn( $key, $default = null ) => 'home' === $key ? 'https://example.test' : $default );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_slash' )->alias( static fn( $value ) => is_string( $value ) ? addslashes( $value ) : $value );
	}

	private function set_router_strategy( ?SubdirectoryStrategy $strategy ): void {
		$router = new \ReflectionProperty( Router::class, 'strategy' );
		$router->setValue( null, $strategy );

		$home_path = new \ReflectionProperty( SubdirectoryStrategy::class, 'home_path_cache' );
		$home_path->setValue( null, null );
	}

	private function wpdb_stub(): object {
		return new class {
			public string $prefix = 'wp_';

			/** @var array<int,mixed> */
			private array $last_args = array();

			/** @param mixed ...$args */
			public function prepare( string $sql, ...$args ): string {
				$this->last_args = $args;
				return $sql;
			}

			public function get_row( string $sql ): ?object {
				if ( str_contains( $sql, 'cml_post_language' ) && str_contains( $sql, 'post_id = %d' ) ) {
					$post_id = (int) ( $this->last_args[0] ?? 0 );
					return in_array( $post_id, array( 10, 20 ), true )
						? (object) array( 'group_id' => 100, 'language' => 10 === $post_id ? 'ka' : 'en' )
						: null;
				}
				if ( str_contains( $sql, 'cml_term_language' ) && str_contains( $sql, 'term_id = %d' ) ) {
					$term_id = (int) ( $this->last_args[0] ?? 0 );
					return in_array( $term_id, array( 5, 15 ), true )
						? (object) array( 'group_id' => 200, 'language' => 5 === $term_id ? 'ka' : 'en' )
						: null;
				}

				return null;
			}

			/** @return array<int,object> */
			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'cml_languages' ) ) {
					return array(
						(object) array( 'code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native' => 'Georgian', 'flag' => 'ge', 'rtl' => 0, 'active' => 1, 'is_default' => 1, 'position' => 0 ),
						(object) array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native' => 'English', 'flag' => 'us', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 1 ),
					);
				}
				if ( str_contains( $sql, 'cml_post_language' ) && str_contains( $sql, 'group_id = %d' ) ) {
					return array(
						(object) array( 'post_id' => 10, 'language' => 'ka' ),
						(object) array( 'post_id' => 20, 'language' => 'en' ),
					);
				}
				if ( str_contains( $sql, 'cml_term_language' ) && str_contains( $sql, 'group_id = %d' ) ) {
					return array(
						(object) array( 'term_id' => 5, 'language' => 'ka' ),
						(object) array( 'term_id' => 15, 'language' => 'en' ),
					);
				}

				return array();
			}
		};
	}
}
