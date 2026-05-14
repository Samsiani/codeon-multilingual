<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Samsiani\CodeonMultilingual\Compat\WpmlFunctions;

/**
 * Exercises WpmlFunctions::is_element_type via reflection — the private method
 * is the single most-likely future regression source because WPML's
 * element_type strings come in 4 different conventions.
 */
final class WpmlElementTypeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'taxonomy_exists' )->alias( static fn( $t ) => in_array( $t, array( 'category', 'post_tag', 'product_cat', 'brand' ), true ) );
		Functions\when( 'post_type_exists' )->alias( static fn( $t ) => in_array( $t, array( 'post', 'page', 'product', 'custom_cpt' ), true ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call_is_term( string $type ): bool {
		$ref = new ReflectionClass( WpmlFunctions::class );
		$m   = $ref->getMethod( 'is_term_element_type' );
		$m->setAccessible( true );
		return (bool) $m->invoke( null, $type );
	}

	public function test_core_post_types_are_post(): void {
		foreach ( array( 'post', 'page', 'attachment', 'nav_menu_item', 'product', 'product_variation' ) as $t ) {
			$this->assertFalse( $this->call_is_term( $t ), "core post type '$t' should resolve to post" );
		}
	}

	public function test_core_taxonomies_are_term(): void {
		foreach ( array( 'category', 'post_tag', 'nav_menu', 'product_cat', 'product_tag' ) as $t ) {
			$this->assertTrue( $this->call_is_term( $t ), "core taxonomy '$t' should resolve to term" );
		}
	}

	public function test_wpml_post_prefix_is_post(): void {
		$this->assertFalse( $this->call_is_term( 'post_custom' ) );
		$this->assertFalse( $this->call_is_term( 'post_product' ) );
	}

	public function test_wpml_tax_prefix_is_term(): void {
		$this->assertTrue( $this->call_is_term( 'tax_brand' ) );
		$this->assertTrue( $this->call_is_term( 'tax_category' ) );
	}

	public function test_registered_taxonomy_slug_is_term(): void {
		$this->assertTrue( $this->call_is_term( 'brand' ) );
	}

	public function test_registered_post_type_slug_is_post(): void {
		$this->assertFalse( $this->call_is_term( 'custom_cpt' ) );
	}

	public function test_empty_string_defaults_to_post(): void {
		$this->assertFalse( $this->call_is_term( '' ) );
	}

	public function test_unknown_type_defaults_to_post(): void {
		$this->assertFalse( $this->call_is_term( 'whatever' ) );
	}
}
