<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Cli;

use Samsiani\CodeonMultilingual\Core\Backfill;
use WP_CLI;
use WP_CLI_Command;

/**
 * Drive the post-language backfill synchronously from CLI.
 *
 * The admin path schedules backfill via wp-cron in 500-row batches with a 5s
 * gap; on shared hosting with disabled cron that stalls. CLI runs the same
 * batches inline so a `wp cml backfill run --all` finishes the job
 * regardless of cron health.
 *
 * Examples
 *
 *     wp cml backfill status
 *     wp cml backfill run
 *     wp cml backfill run --all
 *     wp cml backfill reset
 */
final class BackfillCommand extends WP_CLI_Command {

	/**
	 * Processes one or more backfill batches inline.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Loop until every untagged post has a language row (no wp-cron involved).
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function run( array $args, array $assoc_args ): void {
		$loop = isset( $assoc_args['all'] );

		do {
			$result = Backfill::run_batch_now();
			WP_CLI::log(
				sprintf(
					'  batch: processed=%d remaining=%d done=%s',
					$result['processed'],
					$result['remaining'],
					$result['done'] ? 'yes' : 'no'
				)
			);
			if ( $result['done'] || ! $loop ) {
				break;
			}
		} while ( $result['remaining'] > 0 );

		$status = Backfill::status();
		WP_CLI::success(
			sprintf(
				'Backfill state: status=%s last_id=%d remaining=%d',
				$status['status'],
				$status['last_id'],
				$status['remaining']
			)
		);
	}

	/**
	 * Prints current backfill status without writing anything.
	 */
	public function status(): void {
		$status = Backfill::status();
		WP_CLI::log( sprintf( 'status:    %s', $status['status'] ) );
		WP_CLI::log( sprintf( 'last_id:   %d', $status['last_id'] ) );
		WP_CLI::log( sprintf( 'remaining: %d', $status['remaining'] ) );
	}

	/**
	 * Resets backfill bookkeeping (status + last_id + scheduled cron event).
	 * Does not delete cml_post_language rows — re-running run will just skip
	 * already-tagged posts.
	 */
	public function reset(): void {
		Backfill::reset();
		WP_CLI::success( 'Backfill state reset. Run `wp cml backfill run --all` to start over.' );
	}
}
