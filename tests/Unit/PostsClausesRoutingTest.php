<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Query\PostsClauses;

/**
 * @covers \Samsiani\CodeonMultilingual\Query\PostsClauses::route_pagename_to_translation
 */
final class PostsClausesRoutingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// Settings fall back to defaults, so the untranslated-content fallback
		// is on — the shipped configuration.
		Functions\when( 'get_option' )->justReturn( array() );

		CurrentLanguage::reset();
		Languages::flush_cache();
	}

	protected function tearDown(): void {
		CurrentLanguage::reset();
		Languages::flush_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_nested_pagename_rewrites_using_full_parent_path(): void {
		global $wpdb;
		$wpdb = $this->wpdb_with_pages(
			array(
				'en|company/team' => 234,
				'en|careers/team' => 999,
			)
		);

		CurrentLanguage::set( 'en' );

		$result = PostsClauses::route_pagename_to_translation(
			array(
				'pagename' => 'company/team',
				'name'     => 'team',
			)
		);

		$this->assertSame( array( 'page_id' => 234 ), $result );
		$this->assertSame( array( 'team', 'en', 'company' ), $wpdb->prepared_args[0] );
		$this->assertStringContainsString( 'p0.post_parent = p1.ID', $wpdb->prepared_sql[0] );
		$this->assertStringContainsString( 'p1.post_parent = 0', $wpdb->prepared_sql[0] );
	}

	public function test_nested_pagename_does_not_match_unrelated_leaf_slug(): void {
		global $wpdb;
		$wpdb = $this->wpdb_with_pages(
			array(
				'en|careers/team' => 999,
			)
		);

		CurrentLanguage::set( 'en' );
		$query_vars = array(
			'pagename' => 'company/team',
			'name'     => 'team',
		);

		$this->assertSame( $query_vars, PostsClauses::route_pagename_to_translation( $query_vars ) );
		$this->assertSame( array( 'team', 'en', 'company' ), $wpdb->prepared_args[0] );
		$this->assertSame( array( 'team', 'ka', 'company' ), $wpdb->prepared_args[1] );
	}

	public function test_nested_pagename_falls_back_to_default_language_full_path(): void {
		global $wpdb;
		$wpdb = $this->wpdb_with_pages(
			array(
				'ka|company/team' => 101,
			)
		);

		CurrentLanguage::set( 'en' );

		$result = PostsClauses::route_pagename_to_translation(
			array(
				'pagename' => 'company/team',
				'name'     => 'team',
			)
		);

		$this->assertSame( array( 'page_id' => 101 ), $result );
		$this->assertSame( array( 'team', 'en', 'company' ), $wpdb->prepared_args[0] );
		$this->assertSame( array( 'team', 'ka', 'company' ), $wpdb->prepared_args[1] );
	}

	public function test_post_clause_join_is_safe_when_later_filters_append_joins(): void {
		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';
			public string $posts  = 'wp_posts';

			/** @param mixed ...$args */
			public function prepare( string $sql, ...$args ): string {
				unset( $args );
				return $sql;
			}

			/** @return array<int, object> */
			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'cml_languages' ) ) {
					return array(
						(object) array( 'code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native' => 'ქართული', 'flag' => 'ka', 'rtl' => 0, 'active' => 1, 'is_default' => 1, 'position' => 0 ),
						(object) array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English',  'native' => 'English',  'flag' => 'en', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 1 ),
					);
				}
				return array();
			}
		};
		CurrentLanguage::set( 'en' );
		$this->reset_posts_clauses_cache();

		$method = new \ReflectionMethod( PostsClauses::class, 'sql_fragments' );
		$method->setAccessible( true );
		$fragments = $method->invoke( null );

		$join = $fragments[0];
		$this->assertStringEndsWith( ' ', $join );
		$this->assertStringNotContainsString( 'IDLEFT', $join . 'LEFT JOIN wp_comments ON wp_posts.ID = wp_comments.comment_post_ID' );
	}

	public function test_non_default_language_falls_back_to_untranslated_content(): void {
		global $wpdb;
		$wpdb = $this->wpdb_with_languages();
		CurrentLanguage::set( 'en' );
		$this->reset_posts_clauses_cache();

		$method = new \ReflectionMethod( PostsClauses::class, 'sql_fragments' );
		$method->setAccessible( true );
		[ $join, $where ] = $method->invoke( null );

		// A second LEFT JOIN finds a sibling in the requested language...
		$this->assertStringContainsString( 'cml_pl_tr', $join );
		// ...and the row survives when no such sibling exists.
		$this->assertStringContainsString( 'cml_pl_tr.post_id IS NULL', $where );
	}

	public function test_default_language_needs_no_fallback_join(): void {
		global $wpdb;
		$wpdb = $this->wpdb_with_languages();
		CurrentLanguage::set( 'ka' );
		$this->reset_posts_clauses_cache();

		$method = new \ReflectionMethod( PostsClauses::class, 'sql_fragments' );
		$method->setAccessible( true );
		[ $join, $where ] = $method->invoke( null );

		$this->assertStringNotContainsString( 'cml_pl_tr', $join );
		$this->assertStringContainsString( 'IS NULL', $where );
	}

	private function wpdb_with_languages(): object {
		return new class {
			public string $prefix = 'wp_';
			public string $posts  = 'wp_posts';

			/** @param mixed ...$args */
			public function prepare( string $sql, ...$args ): string {
				unset( $args );
				return $sql;
			}

			/** @return array<int, object> */
			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'cml_languages' ) ) {
					return array(
						(object) array( 'code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native' => 'ქართული', 'flag' => 'ka', 'rtl' => 0, 'active' => 1, 'is_default' => 1, 'position' => 0 ),
						(object) array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English',  'native' => 'English',  'flag' => 'en', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 1 ),
					);
				}
				return array();
			}
		};
	}

	/**
	 * @param array<string, int> $pages
	 */
	private function wpdb_with_pages( array $pages ): object {
		return new class( $pages ) {
			public string $prefix = 'wp_';
			public string $posts  = 'wp_posts';

			/** @var array<int, array<int, string>> */
			public array $prepared_args = array();

			/** @var array<int, string> */
			public array $prepared_sql = array();

			/** @param array<string, int> $pages */
			public function __construct( private array $pages ) {}

			public function prepare( string $sql, string ...$args ): string {
				$this->prepared_sql[]  = $sql;
				$this->prepared_args[] = $args;
				return $sql;
			}

			public function get_var( string $sql ): ?int {
				unset( $sql );
				$args = end( $this->prepared_args );
				if ( false === $args || count( $args ) < 2 ) {
					return null;
				}

				$leaf     = array_shift( $args );
				$language = array_shift( $args );
				$path     = implode( '/', array_merge( array_reverse( $args ), array( $leaf ) ) );

				return $this->pages[ $language . '|' . $path ] ?? null;
			}

			/** @return array<int, object> */
			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'cml_languages' ) ) {
					return array(
						(object) array( 'code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native' => 'ქართული', 'flag' => 'ka', 'rtl' => 0, 'active' => 1, 'is_default' => 1, 'position' => 0 ),
						(object) array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English',  'native' => 'English',  'flag' => 'en', 'rtl' => 0, 'active' => 1, 'is_default' => 0, 'position' => 1 ),
					);
				}
				return array();
			}
		};
	}

	private function reset_posts_clauses_cache(): void {
		foreach ( array( 'join_sql', 'where_sql', 'cached_code' ) as $property ) {
			$ref = new \ReflectionProperty( PostsClauses::class, $property );
			$ref->setValue( null, null );
		}
	}
}
