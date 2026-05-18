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

	public function test_cleanup_removes_only_benchmark_fixtures(): void {
		global $wpdb;

		$this->add_language( 'ka', 'ka_GE', 'Georgian', 'Georgian' );

		$benchmark_post = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Benchmark cleanup fixture',
				'meta_input'  => array(
					'_cml_benchmark' => '1',
				),
			)
		);
		$regular_post = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Regular post',
			)
		);

		$this->tag_post_language( $benchmark_post, $benchmark_post, 'ka' );
		$this->tag_post_language( $regular_post, $regular_post, 'ka' );

		$wpdb->insert(
			$wpdb->prefix . 'cml_strings',
			array(
				'hash'            => md5( 'benchmark cleanup', true ),
				'domain'          => 'cml-benchmark',
				'context'         => '',
				'source'          => 'Benchmark cleanup',
				'source_language' => 'en',
				'created_at'      => time(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		$benchmark_string_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'cml_string_translations',
			array(
				'string_id'   => $benchmark_string_id,
				'language'    => 'ka',
				'translation' => 'Benchmark cleanup translated',
				'updated_at'  => time(),
			),
			array( '%d', '%s', '%s', '%d' )
		);

		$dry_run = BenchmarkCommand::cleanup_benchmark_fixtures( true );
		$this->assertSame( 1, $dry_run['counts']['strings'] );
		$this->assertSame( 1, $dry_run['counts']['string_translations'] );
		$this->assertSame( 1, $dry_run['counts']['posts'] );
		$this->assertSame( 1, $dry_run['counts']['post_language_rows'] );
		$this->assertSame( 0, $dry_run['deleted']['posts'] );

		$result = BenchmarkCommand::cleanup_benchmark_fixtures( false );

		$this->assertSame( 1, $result['deleted']['strings'] );
		$this->assertSame( 1, $result['deleted']['string_translations'] );
		$this->assertSame( 1, $result['deleted']['posts'] );
		$this->assertSame( 1, $result['deleted']['post_language_rows'] );
		$this->assertNull( get_post( $benchmark_post ) );
		$this->assertNotNull( get_post( $regular_post ) );
		$this->assertSame(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}cml_post_language WHERE post_id = %d",
					$regular_post
				)
			)
		);
		$this->assertSame( 0, BenchmarkCommand::benchmark_fixture_counts()['strings'] );
	}
}
