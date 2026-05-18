<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin\Pages;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Core\Diagnostics;
use Samsiani\CodeonMultilingual\Core\HealthReport;
use Samsiani\CodeonMultilingual\Core\HealthRepair;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Admin -> Multilingual -> Health.
 *
 * Read-only production report with safe operational actions. The first repair
 * action is deliberately limited to flushing CodeOn runtime caches.
 */
final class HealthPage {

	public const PAGE_SLUG = 'cml-health';

	private const ACTION_SAVE_DEBUG  = 'cml_save_debug_logging';
	private const ACTION_FLUSH_CACHE = 'cml_flush_runtime_caches';
	private const ACTION_REPAIR      = 'cml_repair_health_issues';

	private const NONCE_SAVE_DEBUG  = 'cml_save_debug_logging';
	private const NONCE_FLUSH_CACHE = 'cml_flush_runtime_caches';
	private const NONCE_REPAIR      = 'cml_repair_health_issues';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_post_' . self::ACTION_SAVE_DEBUG, array( self::class, 'handle_save_debug' ) );
		add_action( 'admin_post_' . self::ACTION_FLUSH_CACHE, array( self::class, 'handle_flush_caches' ) );
		add_action( 'admin_post_' . self::ACTION_REPAIR, array( self::class, 'handle_repair' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		$report = HealthReport::generate();
		?>
		<div class="wrap cml-health">
			<h1><?php esc_html_e( 'Health', 'codeon-multilingual' ); ?></h1>

			<?php self::render_notices(); ?>
			<?php self::render_styles(); ?>

			<div class="cml-health-summary">
				<div>
					<p class="cml-health-kicker"><?php esc_html_e( 'Overall status', 'codeon-multilingual' ); ?></p>
					<p class="cml-health-title">
						<span class="<?php echo esc_attr( self::status_class( (string) $report['status'] ) ); ?>">
							<?php echo esc_html( self::status_label( (string) $report['status'] ) ); ?>
						</span>
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: generated timestamp */
							esc_html__( 'Generated %s.', 'codeon-multilingual' ),
							esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['generated_at'] ) )
						);
						?>
					</p>
				</div>
				<ul class="cml-health-counts" aria-label="<?php esc_attr_e( 'Health check counts', 'codeon-multilingual' ); ?>">
					<li><strong><?php echo (int) $report['summary']['critical']; ?></strong><span><?php esc_html_e( 'Critical', 'codeon-multilingual' ); ?></span></li>
					<li><strong><?php echo (int) $report['summary']['warning']; ?></strong><span><?php esc_html_e( 'Warnings', 'codeon-multilingual' ); ?></span></li>
					<li><strong><?php echo (int) $report['summary']['info']; ?></strong><span><?php esc_html_e( 'Info', 'codeon-multilingual' ); ?></span></li>
					<li><strong><?php echo (int) $report['summary']['ok']; ?></strong><span><?php esc_html_e( 'Passing', 'codeon-multilingual' ); ?></span></li>
				</ul>
			</div>

			<div class="cml-health-actions">
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<?php wp_nonce_field( self::NONCE_FLUSH_CACHE ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_FLUSH_CACHE ); ?>">
					<?php submit_button( __( 'Flush CodeOn runtime caches', 'codeon-multilingual' ), 'secondary', 'submit', false ); ?>
				</form>

				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="cml-health-repair-form">
					<?php wp_nonce_field( self::NONCE_REPAIR ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REPAIR ); ?>">
					<label>
						<input type="checkbox" name="cml_confirm_repair" value="1" required>
						<?php esc_html_e( 'I have a recent database backup.', 'codeon-multilingual' ); ?>
					</label>
					<?php submit_button( __( 'Repair safe data issues', 'codeon-multilingual' ), 'secondary', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'Deletes orphaned CodeOn rows, backfills missing public post/term language rows, and normalizes unknown source-string languages. Source WordPress posts and terms are never deleted.', 'codeon-multilingual' ); ?></p>
				</form>

				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="cml-health-debug-form">
					<?php wp_nonce_field( self::NONCE_SAVE_DEBUG ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_DEBUG ); ?>">
					<label>
						<input type="checkbox" name="debug_logging_enabled" value="1" <?php checked( Diagnostics::debug_logging_enabled() ); ?>>
						<?php esc_html_e( 'Enable CodeOn debug logging', 'codeon-multilingual' ); ?>
					</label>
					<?php submit_button( __( 'Save logging', 'codeon-multilingual' ), 'secondary', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'When enabled, CodeOn writes explicit diagnostics messages through PHP error_log. Leave off unless you are investigating a production issue.', 'codeon-multilingual' ); ?></p>
				</form>
			</div>

			<?php foreach ( $report['sections'] as $section ) : ?>
				<h2>
					<?php echo esc_html( (string) $section['title'] ); ?>
					<span class="<?php echo esc_attr( self::status_class( (string) $section['status'] ) ); ?>">
						<?php echo esc_html( self::status_label( (string) $section['status'] ) ); ?>
					</span>
				</h2>
				<table class="widefat striped cml-health-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Status', 'codeon-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Check', 'codeon-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'codeon-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Samples', 'codeon-multilingual' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $section['checks'] as $check ) : ?>
							<tr>
								<td><span class="<?php echo esc_attr( self::status_class( (string) $check['status'] ) ); ?>"><?php echo esc_html( self::status_label( (string) $check['status'] ) ); ?></span></td>
								<td>
									<strong><?php echo esc_html( (string) $check['title'] ); ?></strong>
									<br><code><?php echo esc_html( (string) $check['key'] ); ?></code>
								</td>
								<td>
									<?php echo esc_html( (string) $check['message'] ); ?>
									<?php if ( (int) $check['count'] > 0 ) : ?>
										<br><strong>
											<?php
											printf(
												/* translators: %d: number of detected health-check issues. */
												esc_html__( 'Count: %d', 'codeon-multilingual' ),
												(int) $check['count']
											);
											?>
										</strong>
									<?php endif; ?>
								</td>
								<td><?php self::render_samples( $check['samples'] ?? array() ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public static function handle_save_debug(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_SAVE_DEBUG );

		Diagnostics::set_debug_logging_enabled( ! empty( $_POST['debug_logging_enabled'] ) );
		self::redirect_with( array( 'debug_saved' => '1' ) );
	}

	public static function handle_flush_caches(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_FLUSH_CACHE );

		Languages::flush_cache();
		StringTranslator::flush_cache();
		TranslationGroups::flush();
		Diagnostics::debug( 'Runtime caches flushed from admin Health page' );

		self::redirect_with( array( 'caches_flushed' => '1' ) );
	}

	public static function handle_repair(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_REPAIR );

		if ( empty( $_POST['cml_confirm_repair'] ) ) {
			self::redirect_with( array( 'repair_confirm_required' => '1' ) );
		}

		$result = HealthRepair::run( 'all', false );
		Diagnostics::debug(
			'Health repair applied from admin',
			array(
				'total'   => $result['total'],
				'actions' => array_keys( $result['actions'] ),
			)
		);

		self::redirect_with(
			array(
				'repaired' => '1',
				'total'    => (int) $result['total'],
			)
		);
	}

	/**
	 * @param mixed $samples
	 */
	private static function render_samples( $samples ): void {
		if ( empty( $samples ) ) {
			echo '<span aria-hidden="true">&mdash;</span>';
			return;
		}

		$items = self::sample_items( $samples );
		if ( array() === $items ) {
			echo '<span aria-hidden="true">&mdash;</span>';
			return;
		}

		echo '<ul class="cml-health-samples">';
		foreach ( array_slice( $items, 0, 5 ) as $item ) {
			$encoded = wp_json_encode( $item );
			echo '<li><code>' . esc_html( is_string( $encoded ) ? $encoded : (string) $item ) . '</code></li>';
		}
		echo '</ul>';
	}

	/**
	 * @param mixed $samples
	 * @return array<int,mixed>
	 */
	private static function sample_items( $samples ): array {
		if ( is_array( $samples ) ) {
			$is_list = array_keys( $samples ) === range( 0, count( $samples ) - 1 );
			return $is_list ? $samples : array( $samples );
		}
		if ( is_object( $samples ) ) {
			return array( get_object_vars( $samples ) );
		}
		return array();
	}

	private static function render_notices(): void {
		if ( isset( $_GET['debug_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::notice( 'success', __( 'Debug logging setting saved.', 'codeon-multilingual' ) );
		}
		if ( isset( $_GET['caches_flushed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::notice( 'success', __( 'CodeOn runtime caches flushed.', 'codeon-multilingual' ) );
		}
		if ( isset( $_GET['repair_confirm_required'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::notice( 'error', __( 'Confirm that you have a recent database backup before repairing health issues.', 'codeon-multilingual' ) );
		}
		if ( isset( $_GET['repaired'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$total = isset( $_GET['total'] ) ? absint( wp_unslash( $_GET['total'] ) ) : 0;
			self::notice(
				'success',
				sprintf(
					/* translators: %d: number of repaired rows. */
					__( 'Safe health repairs complete. Rows affected: %d.', 'codeon-multilingual' ),
					$total
				)
			);
		}
	}

	private static function notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * @param array<string,string> $args
	 */
	private static function redirect_with( array $args ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function status_label( string $status ): string {
		return match ( $status ) {
			HealthReport::STATUS_CRITICAL => __( 'Critical', 'codeon-multilingual' ),
			HealthReport::STATUS_WARNING  => __( 'Warning', 'codeon-multilingual' ),
			HealthReport::STATUS_INFO     => __( 'Info', 'codeon-multilingual' ),
			default                       => __( 'OK', 'codeon-multilingual' ),
		};
	}

	private static function status_class( string $status ): string {
		return 'cml-health-status cml-health-status--' . sanitize_html_class( $status );
	}

	private static function render_styles(): void {
		?>
		<style>
			.cml-health-summary {
				display: flex;
				gap: 24px;
				align-items: center;
				justify-content: space-between;
				margin: 16px 0;
				padding: 16px;
				background: #fff;
				border: 1px solid #c3c4c7;
			}
			.cml-health-kicker {
				margin: 0 0 4px;
				color: #646970;
				text-transform: uppercase;
				font-size: 11px;
				font-weight: 600;
			}
			.cml-health-title {
				margin: 0;
				font-size: 24px;
				line-height: 1.2;
			}
			.cml-health-counts {
				display: flex;
				flex-wrap: wrap;
				gap: 12px;
				margin: 0;
			}
			.cml-health-counts li {
				min-width: 82px;
				margin: 0;
				padding: 10px 12px;
				background: #f6f7f7;
				border: 1px solid #dcdcde;
			}
			.cml-health-counts strong,
			.cml-health-counts span {
				display: block;
			}
			.cml-health-counts strong {
				font-size: 20px;
			}
			.cml-health-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 18px;
				align-items: flex-start;
				margin: 18px 0 24px;
			}
			.cml-health-debug-form {
				max-width: 560px;
			}
			.cml-health-repair-form {
				max-width: 640px;
			}
			.cml-health-debug-form .button {
				margin-left: 8px;
			}
			.cml-health-repair-form .button {
				display: block;
				margin-top: 8px;
			}
			.cml-health-status {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 999px;
				font-size: 12px;
				font-weight: 600;
				line-height: 1.7;
				background: #f0f0f1;
				color: #1d2327;
			}
			.cml-health-status--ok {
				background: #edfaef;
				color: #0a6b1d;
			}
			.cml-health-status--warning {
				background: #fff8e5;
				color: #8a4b00;
			}
			.cml-health-status--critical {
				background: #fcf0f1;
				color: #b32d2e;
			}
			.cml-health-status--info {
				background: #eef5ff;
				color: #135e96;
			}
			.cml-health-table {
				margin: 8px 0 24px;
			}
			.cml-health-table th:first-child,
			.cml-health-table td:first-child {
				width: 96px;
			}
			.cml-health-table th:nth-child(2),
			.cml-health-table td:nth-child(2) {
				width: 260px;
			}
			.cml-health-samples {
				margin: 0;
			}
			.cml-health-samples li {
				margin: 0 0 4px;
			}
			@media (max-width: 782px) {
				.cml-health-summary {
					display: block;
				}
				.cml-health-counts {
					margin-top: 14px;
				}
				.cml-health-debug-form .button {
					display: block;
					margin: 10px 0 0;
				}
			}
		</style>
		<?php
	}
}
