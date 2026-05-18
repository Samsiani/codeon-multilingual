<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Content\PostTranslator;

final class PostTranslatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_translatable_post_types_include_existing_builder_template_types(): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page' ) );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, array $types ): array => $types );
		Functions\when( 'post_type_exists' )->alias(
			static fn( string $type ): bool => in_array( $type, array( 'wp_block', 'wp_navigation', 'elementor_library', 'et_pb_layout', 'fusion_template' ), true )
		);

		$types = PostTranslator::translatable_post_types();

		$this->assertContains( 'post', $types );
		$this->assertContains( 'attachment', $types );
		$this->assertContains( 'wp_block', $types );
		$this->assertContains( 'wp_navigation', $types );
		$this->assertContains( 'elementor_library', $types );
		$this->assertContains( 'et_pb_layout', $types );
		$this->assertContains( 'fusion_template', $types );
		$this->assertNotContains( 'bricks_template', $types );
		$this->assertSame( $types, array_values( array_unique( $types ) ) );
	}
}
