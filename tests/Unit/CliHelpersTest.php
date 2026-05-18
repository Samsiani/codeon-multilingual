<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Cli\BenchmarkCommand;
use Samsiani\CodeonMultilingual\Cli\HealthCommand;
use Samsiani\CodeonMultilingual\Cli\LanguageCommand;
use Samsiani\CodeonMultilingual\Cli\StringsCommand;

/**
 * Pure-helper tests for CLI command classes.
 *
 * The command runners themselves require WP_CLI runtime, but the shaping
 * helpers (row formatting, format auto-detection) are pure and tested here.
 */
final class CliHelpersTest extends TestCase {

	public function test_language_row_for_display_normalizes_booleans_to_yes_no(): void {
		$lang = (object) array(
			'code'       => 'ka',
			'locale'     => 'ka_GE',
			'name'       => 'Georgian',
			'native'     => 'ქართული',
			'flag'       => 'ge',
			'rtl'        => 0,
			'active'     => 1,
			'is_default' => 1,
			'position'   => 0,
		);

		$row = LanguageCommand::row_for_display( $lang );

		$this->assertSame( 'ka', $row['code'] );
		$this->assertSame( 'yes', $row['active'] );
		$this->assertSame( 'yes', $row['is_default'] );
		$this->assertSame( 'no', $row['rtl'] );
		$this->assertSame( '0', $row['position'] );
	}

	public function test_language_row_includes_every_expected_column(): void {
		$lang = (object) array(
			'code'       => 'en',
			'locale'     => 'en_US',
			'name'       => 'English',
			'native'     => 'English',
			'flag'       => 'us',
			'rtl'        => 0,
			'active'     => 1,
			'is_default' => 0,
			'position'   => 10,
		);

		$row = LanguageCommand::row_for_display( $lang );

		$expected = array( 'code', 'locale', 'name', 'native', 'active', 'is_default', 'rtl', 'position' );
		$this->assertSame( $expected, array_keys( $row ) );
	}

	public function test_strings_detect_format_uses_extension_first(): void {
		$this->assertSame( 'json', StringsCommand::detect_format( 'cat.json', '{"x":1}' ) );
		$this->assertSame( 'po', StringsCommand::detect_format( 'cat.po', 'msgid ""' ) );
	}

	public function test_strings_detect_format_falls_back_to_content_for_stdin(): void {
		$this->assertSame( 'json', StringsCommand::detect_format( '-', '   {"language":"ka"}' ) );
		$this->assertSame( 'po', StringsCommand::detect_format( '-', "msgid \"\"\nmsgstr \"\"\n" ) );
	}

	public function test_strings_detect_format_handles_unknown_extension(): void {
		$this->assertSame( 'json', StringsCommand::detect_format( 'cat.txt', '{"language":"en"}' ) );
		$this->assertSame( 'po', StringsCommand::detect_format( 'cat.txt', 'msgid ""' ) );
	}

	public function test_benchmark_row_for_display_formats_bytes_as_megabytes(): void {
		$row = BenchmarkCommand::row_for_display(
			array(
				'workload'     => 'strings',
				'rows'         => 250,
				'queries'      => 3,
				'memory_bytes' => 1048576,
				'peak_bytes'   => 2097152,
				'time_ms'      => 17,
			)
		);

		$this->assertSame( 'strings', $row['workload'] );
		$this->assertSame( '250', $row['rows'] );
		$this->assertSame( '3', $row['queries'] );
		$this->assertSame( '1.00', $row['memory_mb'] );
		$this->assertSame( '2.00', $row['peak_mb'] );
		$this->assertSame( '17', $row['time_ms'] );
	}

	public function test_benchmark_bounded_int_clamps_to_range(): void {
		$this->assertSame( 25, BenchmarkCommand::bounded_int( null, 25, 1, 100 ) );
		$this->assertSame( 1, BenchmarkCommand::bounded_int( -5, 25, 1, 100 ) );
		$this->assertSame( 100, BenchmarkCommand::bounded_int( 999, 25, 1, 100 ) );
		$this->assertSame( 42, BenchmarkCommand::bounded_int( '42', 25, 1, 100 ) );
	}

	public function test_health_summary_row_formats_counts_for_cli(): void {
		$row = HealthCommand::summary_row_for_display(
			array(
				'generated_at' => 1760000000,
				'status'       => 'warning',
				'summary'      => array(
					'critical' => 0,
					'warning'  => 2,
					'info'     => 1,
					'ok'       => 20,
				),
				'sections'     => array(),
			)
		);

		$this->assertSame( 'warning', $row['status'] );
		$this->assertSame( '0', $row['critical'] );
		$this->assertSame( '2', $row['warning'] );
		$this->assertSame( '1', $row['info'] );
		$this->assertSame( '20', $row['ok'] );
		$this->assertSame( '2025-10-09T08:53:20+00:00', $row['generated_at'] );
	}

	public function test_health_rows_for_display_can_filter_passing_checks(): void {
		$report = array(
			'sections' => array(
				'languages' => array(
					'title'  => 'Languages',
					'status' => 'warning',
					'meta'   => array(),
					'checks' => array(
						array(
							'key'     => 'language_rows_present',
							'title'   => 'Language rows present',
							'status'  => 'ok',
							'count'   => 0,
							'message' => 'At least one language row exists.',
							'samples' => array(),
						),
						array(
							'key'     => 'duplicate_language_locales',
							'title'   => 'Duplicate locales',
							'status'  => 'warning',
							'count'   => 1,
							'message' => 'Multiple languages share the same WordPress locale.',
							'samples' => array( 'en_US' => 2 ),
						),
					),
				),
			),
		);

		$this->assertCount( 2, HealthCommand::rows_for_display( $report ) );

		$failed = HealthCommand::rows_for_display( $report, true );
		$this->assertCount( 1, $failed );
		$this->assertSame( 'Languages', $failed[0]['section'] );
		$this->assertSame( 'warning', $failed[0]['status'] );
		$this->assertSame( 'duplicate_language_locales', $failed[0]['key'] );
		$this->assertSame( '1', $failed[0]['count'] );
	}

	public function test_health_repair_rows_for_display_formats_action_rows(): void {
		$rows = HealthCommand::repair_rows_for_display(
			array(
				'actions' => array(
					'orphaned-post-rows' => array(
						'label'   => 'Delete orphaned post language rows',
						'count'   => 7,
						'applied' => true,
					),
					'missing-term-rows'   => array(
						'label'   => 'Backfill missing public term language rows',
						'count'   => 3,
						'applied' => false,
					),
				),
			)
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( 'Delete orphaned post language rows', $rows[0]['action'] );
		$this->assertSame( '7', $rows[0]['count'] );
		$this->assertSame( 'yes', $rows[0]['applied'] );
		$this->assertSame( 'no', $rows[1]['applied'] );
	}
}
