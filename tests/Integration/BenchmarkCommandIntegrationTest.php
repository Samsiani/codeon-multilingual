<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Integration;

use Samsiani\CodeonMultilingual\Cli\BenchmarkCommand;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Query\PostsClauses;

/**
 * @group integration
 */
final class BenchmarkCommandIntegrationTest extends IntegrationTestCase {

	public function test_post_type_workload_uses_wp_query_language_filter(): void {
		$this->skip_without_woocommerce();
		$this->add_language( 'ka', 'ka_GE', 'Georgian', 'Georgian' );

		$en = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Benchmark EN',
			)
		);
		$ka = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Benchmark KA',
			)
		);

		$this->tag_post_language( $en, $en, 'en' );
		$this->tag_post_language( $ka, $en, 'ka' );
		CurrentLanguage::set( 'ka' );
		PostsClauses::reset_cache();
		PostsClauses::register();

		$this->assertSame( 1, BenchmarkCommand::benchmark_post_type_for_test( 'product', 10 ) );
	}
}
