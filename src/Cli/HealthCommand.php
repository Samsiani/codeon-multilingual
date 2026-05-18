<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Cli;

use Samsiani\CodeonMultilingual\Core\HealthReport;
use Samsiani\CodeonMultilingual\Core\HealthRepair;
use WP_CLI;
use WP_CLI\Utils;
use WP_CLI_Command;

/**
 * Operational health checks for production installs.
 *
 * Examples
 *
 *     wp cml health summary
 *     wp cml health report --failed-only
 *     wp cml health report --format=json
 *     wp cml health repair --dry-run
 *     wp cml health repair --apply --scope=orphaned-post-rows
 */
final class HealthCommand extends WP_CLI_Command {

	/**
	 * Shows the overall health status and check counts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function summary( array $args, array $assoc_args ): void {
		$report = HealthReport::generate();
		$rows   = array( self::summary_row_for_display( $report ) );

		Utils\format_items(
			self::format_arg( $assoc_args ),
			$rows,
			array( 'status', 'critical', 'warning', 'info', 'ok', 'generated_at' )
		);
	}

	/**
	 * Shows every health check row.
	 *
	 * ## OPTIONS
	 *
	 * [--failed-only]
	 * : Show only warning and critical checks.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function report( array $args, array $assoc_args ): void {
		$rows = self::rows_for_display( HealthReport::generate(), isset( $assoc_args['failed-only'] ) );

		Utils\format_items(
			self::format_arg( $assoc_args ),
			$rows,
			array( 'section', 'status', 'key', 'count', 'message' )
		);
	}

	/**
	 * Repairs safe data issues reported by the Health page.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scope>]
	 * : Repair scope.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - orphaned-post-rows
	 *   - orphaned-term-rows
	 *   - orphaned-string-rows
	 *   - missing-post-rows
	 *   - missing-term-rows
	 *   - unknown-source-languages
	 * ---
	 *
	 * [--dry-run]
	 * : Report rows that would be repaired without writing.
	 *
	 * [--apply]
	 * : Apply repairs. Required for writes.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function repair( array $args, array $assoc_args ): void {
		$apply   = isset( $assoc_args['apply'] );
		$dry_run = isset( $assoc_args['dry-run'] ) || ! $apply;
		$scope   = HealthRepair::normalize_scope( (string) ( $assoc_args['scope'] ?? 'all' ) );
		$result  = HealthRepair::run( $scope, $dry_run );

		$rows = self::repair_rows_for_display( $result );
		Utils\format_items(
			self::format_arg( $assoc_args ),
			$rows,
			array( 'action', 'count', 'applied' )
		);

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				WP_CLI::warning( $error );
			}
			WP_CLI::error( 'Health repair finished with errors.' );
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run: nothing written. Re-run with --apply to repair.' );
			return;
		}

		WP_CLI::success( sprintf( 'Health repair complete. Rows affected: %d.', (int) $result['total'] ) );
	}

	/**
	 * @param array<string,mixed> $report
	 * @phpstan-param array{
	 *   generated_at:int,
	 *   status:string,
	 *   summary:array{critical:int,warning:int,info:int,ok:int},
	 *   sections:array<string, array{title:string,status:string,checks:array<int,array<string,mixed>>,meta:array<string,mixed>}>
	 * } $report
	 * @return array{status:string,critical:string,warning:string,info:string,ok:string,generated_at:string}
	 */
	public static function summary_row_for_display( array $report ): array {
		return array(
			'status'       => (string) $report['status'],
			'critical'     => (string) (int) $report['summary']['critical'],
			'warning'      => (string) (int) $report['summary']['warning'],
			'info'         => (string) (int) $report['summary']['info'],
			'ok'           => (string) (int) $report['summary']['ok'],
			'generated_at' => gmdate( 'c', (int) $report['generated_at'] ),
		);
	}

	/**
	 * @param array<string,mixed> $report
	 * @phpstan-param array{
	 *   sections:array<string, array{title:string,status:string,checks:array<int,array<string,mixed>>,meta:array<string,mixed>}>
	 * } $report
	 * @return array<int,array{section:string,status:string,key:string,count:string,message:string}>
	 */
	public static function rows_for_display( array $report, bool $failed_only = false ): array {
		$rows = array();

		foreach ( $report['sections'] as $section ) {
			foreach ( $section['checks'] as $check ) {
				$status = (string) $check['status'];
				if ( $failed_only && HealthReport::STATUS_OK === $status ) {
					continue;
				}

				$rows[] = array(
					'section' => (string) $section['title'],
					'status'  => $status,
					'key'     => (string) $check['key'],
					'count'   => (string) (int) $check['count'],
					'message' => (string) $check['message'],
				);
			}
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $result
	 * @phpstan-param array{
	 *   actions:array<string,array{label:string,count:int,applied:bool}>
	 * } $result
	 * @return array<int,array{action:string,count:string,applied:string}>
	 */
	public static function repair_rows_for_display( array $result ): array {
		$rows = array();

		foreach ( $result['actions'] as $action ) {
			$rows[] = array(
				'action'  => (string) $action['label'],
				'count'   => (string) (int) $action['count'],
				'applied' => $action['applied'] ? 'yes' : 'no',
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, string> $assoc_args
	 */
	private static function format_arg( array $assoc_args ): string {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		return in_array( $format, array( 'table', 'csv', 'json', 'yaml' ), true ) ? $format : 'table';
	}
}
