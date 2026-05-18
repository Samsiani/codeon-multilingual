<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Woo\VariationTranslator;

/**
 * @covers \Samsiani\CodeonMultilingual\Woo\VariationTranslator
 */
final class WooVariationTranslatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		TranslationGroups::flush();
		CurrentLanguage::set( 'ka' );

		global $wpdb;
		$wpdb = new class {
			public string $prefix = 'wp_';

			/** @param mixed ...$args */
			public function prepare( string $sql, ...$args ): string {
				foreach ( $args as $arg ) {
					$sql = preg_replace( '/%d/', (string) (int) $arg, $sql, 1 ) ?? $sql;
					$sql = preg_replace( '/%s/', "'" . (string) $arg . "'", $sql, 1 ) ?? $sql;
				}

				return $sql;
			}

			public function get_row( string $sql ): ?object {
				if ( str_contains( $sql, 'term_id = 11' ) ) {
					return (object) array( 'group_id' => 99, 'language' => 'en' );
				}

				return null;
			}

			/**
			 * @return array<int, object>
			 */
			public function get_results( string $sql ): array {
				if ( str_contains( $sql, 'group_id = 99' ) ) {
					return array(
						(object) array( 'term_id' => 11, 'language' => 'en' ),
						(object) array( 'term_id' => 12, 'language' => 'ka' ),
					);
				}

				return array();
			}
		};
	}

	protected function tearDown(): void {
		CurrentLanguage::reset();
		TranslationGroups::flush();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_translates_variation_option_term_name_to_current_language_sibling(): void {
		Functions\expect( 'get_term' )
			->once()
			->with( 12, 'pa_color' )
			->andReturn( (object) array( 'term_id' => 12, 'name' => 'ლურჯი' ) );

		$this->assertSame(
			'ლურჯი',
			VariationTranslator::translate_variation_option_name(
				'Blue',
				(object) array( 'term_id' => 11, 'name' => 'Blue' ),
				'pa_color',
				null
			)
		);
	}

	public function test_keeps_original_variation_option_name_without_term_group(): void {
		Functions\expect( 'get_term' )->never();

		$this->assertSame(
			'Large',
			VariationTranslator::translate_variation_option_name(
				'Large',
				(object) array( 'term_id' => 999, 'name' => 'Large' ),
				'pa_size',
				null
			)
		);
	}
}
