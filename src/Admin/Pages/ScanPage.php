<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin\Pages;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Strings\Scanner;

/**
 * Admin → Multilingual → Scan.
 *
 * WPML-style explicit scan UI. Checkbox list of themes + plugins; admin picks
 * targets and clicks Scan. Each path is walked once, found strings batch-inserted
 * with INSERT IGNORE so re-scans don't duplicate.
 *
 * Synchronous — fine for a few thousand files. Sites with huge plugin trees
 * (e.g. WC + 20 extensions) may need to scan in groups; the page handles
 * partial scans naturally because INSERT IGNORE is idempotent.
 */
final class ScanPage {

	public const PAGE_SLUG     = 'cml-scan';
	private const ACTION_SCAN  = 'cml_run_scan';
	private const NONCE_SCAN   = 'cml_run_scan';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_post_' . self::ACTION_SCAN, array( self::class, 'handle_scan' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$themes  = wp_get_themes();
		$plugins = get_plugins();
		$active_stylesheet = get_stylesheet();
		$active_plugins    = (array) get_option( 'active_plugins', array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Scan themes & plugins', 'codeon-multilingual' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Recursively scans PHP files for translatable strings (__, _e, _x, esc_html__, etc.) and adds them to the catalog. Re-scanning is safe — duplicates are ignored.', 'codeon-multilingual' ); ?>
			</p>

			<?php self::render_notices(); ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php wp_nonce_field( self::NONCE_SCAN ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SCAN ); ?>">

				<h2><?php esc_html_e( 'Themes', 'codeon-multilingual' ); ?></h2>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th style="width:30px"><input type="checkbox" id="cml-toggle-themes"></th>
							<th><?php esc_html_e( 'Theme', 'codeon-multilingual' ); ?></th>
							<th><?php esc_html_e( 'Version', 'codeon-multilingual' ); ?></th>
							<th><?php esc_html_e( 'Status', 'codeon-multilingual' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $themes as $slug => $theme ) :
							$is_active = ( $active_stylesheet === $slug );
							?>
							<tr>
								<td><input type="checkbox" name="themes[]" value="<?php echo esc_attr( (string) $slug ); ?>" <?php checked( $is_active ); ?>></td>
								<td><?php echo esc_html( (string) $theme->get( 'Name' ) ); ?></td>
								<td><?php echo esc_html( (string) $theme->get( 'Version' ) ); ?></td>
								<td><?php echo $is_active ? '<strong>' . esc_html__( 'Active', 'codeon-multilingual' ) . '</strong>' : esc_html__( 'Inactive', 'codeon-multilingual' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Plugins', 'codeon-multilingual' ); ?></h2>
				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th style="width:30px"><input type="checkbox" id="cml-toggle-plugins"></th>
							<th><?php esc_html_e( 'Plugin', 'codeon-multilingual' ); ?></th>
							<th><?php esc_html_e( 'Version', 'codeon-multilingual' ); ?></th>
							<th><?php esc_html_e( 'Status', 'codeon-multilingual' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $plugins as $file => $data ) :
							$slug = dirname( (string) $file );
							if ( '.' === $slug ) {
								// Single-file plugins; scan by parent file path instead.
								$slug = (string) $file;
							}
							$is_active = in_array( $file, $active_plugins, true );
							?>
							<tr>
								<td><input type="checkbox" name="plugins[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $is_active ); ?>></td>
								<td><?php echo esc_html( (string) $data['Name'] ); ?></td>
								<td><?php echo esc_html( (string) $data['Version'] ); ?></td>
								<td><?php echo $is_active ? '<strong>' . esc_html__( 'Active', 'codeon-multilingual' ) . '</strong>' : esc_html__( 'Inactive', 'codeon-multilingual' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Scan selected', 'codeon-multilingual' ) ); ?>
			</form>

			<script>
			(function(){
				function bindToggle(toggleId){
					var box = document.getElementById(toggleId);
					if(!box) return;
					box.addEventListener('change', function(){
						var checked = box.checked;
						var rows = box.closest('table').querySelectorAll('tbody input[type=checkbox]');
						rows.forEach(function(cb){ cb.checked = checked; });
					});
				}
				bindToggle('cml-toggle-themes');
				bindToggle('cml-toggle-plugins');
			})();
			</script>
		</div>
		<?php
	}

	public static function handle_scan(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_SCAN );

		$themes  = isset( $_POST['themes'] )  && is_array( $_POST['themes'] )  ? wp_unslash( (array) $_POST['themes'] )  : array();
		$plugins = isset( $_POST['plugins'] ) && is_array( $_POST['plugins'] ) ? wp_unslash( (array) $_POST['plugins'] ) : array();

		$started = microtime( true );
		$count   = 0;
		foreach ( $themes as $slug ) {
			$slug = sanitize_text_field( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			$count += Scanner::scan_theme( $slug );
		}
		foreach ( $plugins as $slug ) {
			$slug = sanitize_text_field( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			$count += Scanner::scan_plugin( $slug );
		}
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'scanned' => '1',
					'count'   => $count,
					'ms'      => $elapsed,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function render_notices(): void {
		if ( ! isset( $_GET['scanned'] ) ) {
			return;
		}
		$count   = (int) ( $_GET['count'] ?? 0 );
		$elapsed = (int) ( $_GET['ms']    ?? 0 );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: strings added, 2: elapsed ms */
					__( 'Scan complete — %1$d new strings added in %2$d ms.', 'codeon-multilingual' ),
					$count,
					$elapsed
				)
			)
		);
	}
}
