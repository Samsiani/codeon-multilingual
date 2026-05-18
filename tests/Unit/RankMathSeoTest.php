<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Compat\RankMathSeo;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Url\Router;
use Samsiani\CodeonMultilingual\Url\SubdirectoryStrategy;

/**
 * @covers \Samsiani\CodeonMultilingual\Compat\RankMathSeo
 */
final class RankMathSeoTest extends TestCase {

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

	public function test_localizes_rank_math_breadcrumb_url_tuples_and_keyed_ids(): void {
		$breadcrumbs = array(
			array( 'Home', 'https://example.test/' ),
			array(
				'label'   => 'Shop',
				'url'     => 'https://example.test/shop/',
				'post_id' => 10,
			),
			array(
				'label'   => 'Shoes',
				'term_id' => 5,
			),
		);

		$this->assertSame(
			array(
				array( 'Home', 'https://example.test/en/' ),
				array(
					'label'   => 'Shop',
					'url'     => 'https://example.test/en/shop/',
					'post_id' => 20,
				),
				array(
					'label'   => 'Shoes',
					'term_id' => 15,
				),
			),
			RankMathSeo::localize_breadcrumbs( $breadcrumbs, 'en' )
		);
	}

	public function test_localizes_rank_math_json_ld_nested_arrays(): void {
		$json_ld = array(
			'Article' => array(
				'@id'   => 'https://example.test/story/#richSnippet',
				'url'   => 'https://example.test/story/',
				'image' => array(
					'@id' => 'https://example.test/story/#primaryimage',
					'url' => 'https://example.test/uploads/story.jpg',
				),
				'about' => array(
					'term_id' => 5,
				),
			),
		);

		$result = RankMathSeo::localize_json_ld( $json_ld, 'en' );

		$this->assertSame( 'https://example.test/en/story/#richSnippet', $result['Article']['@id'] );
		$this->assertSame( 'https://example.test/en/story/', $result['Article']['url'] );
		$this->assertSame( 'https://example.test/en/story/#primaryimage', $result['Article']['image']['@id'] );
		$this->assertSame( 'https://example.test/uploads/story.jpg', $result['Article']['image']['url'] );
		$this->assertSame( 15, $result['Article']['about']['term_id'] );
	}

	public function test_localizes_rank_math_canonical_and_sitemap_urls(): void {
		$this->assertSame(
			'https://example.test/en/product/',
			RankMathSeo::localize_url( 'https://example.test/product/', 'en' )
		);
		$this->assertSame(
			'https://example.test/wp-content/uploads/product.jpg',
			RankMathSeo::localize_url( 'https://example.test/wp-content/uploads/product.jpg', 'en' )
		);
		$this->assertSame(
			array( 'loc' => 'https://example.test/en/product/' ),
			RankMathSeo::localize_sitemap_entry( array( 'loc' => 'https://example.test/product/' ), 'post', null, 'en' )
		);
	}

	private function set_wordpress_stubs(): void {
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		Functions\when( 'home_url' )->alias( static fn( $path = '/' ) => 'https://example.test/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'get_option' )->alias( static fn( $key, $default = null ) => 'home' === $key ? 'https://example.test' : $default );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
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

			/** @var array<int, mixed> */
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

			/**
			 * @return array<int, object>
			 */
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
