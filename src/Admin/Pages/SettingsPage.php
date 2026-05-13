<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin\Pages;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Core\Settings;

/**
 * Admin → Multilingual → Settings.
 *
 * Frontend switcher options + string-discovery toggle. All settings live in
 * the cml_settings option; saves go through Settings::save() which merges
 * with existing values so partial forms don't reset everything.
 */
final class SettingsPage {

	public const PAGE_SLUG = 'cml-settings';

	private const ACTION_SAVE = 'cml_save_settings';
	private const NONCE_SAVE  = 'cml_save_settings';

	private const POSITIONS = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );
	private const STYLES    = array( 'dropdown', 'list', 'flags' );

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_post_' . self::ACTION_SAVE, array( self::class, 'handle_save' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		$settings = Settings::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Settings', 'codeon-multilingual' ); ?></h1>

			<?php self::render_notices(); ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php wp_nonce_field( self::NONCE_SAVE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">

				<h2><?php esc_html_e( 'Frontend language switcher', 'codeon-multilingual' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show on frontend', 'codeon-multilingual' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="frontend_switcher_enabled" value="1" <?php checked( (bool) $settings['frontend_switcher_enabled'] ); ?>>
								<?php esc_html_e( 'Display a floating language switcher on every public page.', 'codeon-multilingual' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'You can still place the switcher inline anywhere via the shortcode [cml_language_switcher] or the classic widget.', 'codeon-multilingual' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cml-pos"><?php esc_html_e( 'Position', 'codeon-multilingual' ); ?></label></th>
						<td>
							<select id="cml-pos" name="frontend_switcher_position">
								<?php foreach ( self::POSITIONS as $p ) : ?>
									<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $settings['frontend_switcher_position'], $p ); ?>>
										<?php echo esc_html( ucwords( str_replace( '-', ' ', $p ) ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cml-style"><?php esc_html_e( 'Style', 'codeon-multilingual' ); ?></label></th>
						<td>
							<select id="cml-style" name="frontend_switcher_style">
								<?php foreach ( self::STYLES as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $settings['frontend_switcher_style'], $s ); ?>>
										<?php echo esc_html( ucfirst( $s ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show flags', 'codeon-multilingual' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="frontend_switcher_show_flag" value="1" <?php checked( (bool) $settings['frontend_switcher_show_flag'] ); ?>>
								<?php esc_html_e( 'Include the country flag emoji alongside each language label.', 'codeon-multilingual' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'String discovery', 'codeon-multilingual' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic discovery', 'codeon-multilingual' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_discover_strings" value="1" <?php checked( (bool) $settings['auto_discover_strings'] ); ?>>
								<?php esc_html_e( 'Capture every __() call made during page loads into the strings catalog.', 'codeon-multilingual' ); ?>
							</label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the Scan admin page */
									wp_kses(
										__( 'Off by default — populate the catalog explicitly via the <a href="%s">Scan page</a>. Turning this on captures every string that runs through gettext, which can grow the catalog very quickly on a busy site.', 'codeon-multilingual' ),
										array( 'a' => array( 'href' => array() ) )
									),
									esc_url( admin_url( 'admin.php?page=' . ScanPage::PAGE_SLUG ) )
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'codeon-multilingual' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_SAVE );

		$position = isset( $_POST['frontend_switcher_position'] ) ? sanitize_key( wp_unslash( (string) $_POST['frontend_switcher_position'] ) ) : 'bottom-right';
		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			$position = 'bottom-right';
		}
		$style = isset( $_POST['frontend_switcher_style'] ) ? sanitize_key( wp_unslash( (string) $_POST['frontend_switcher_style'] ) ) : 'dropdown';
		if ( ! in_array( $style, self::STYLES, true ) ) {
			$style = 'dropdown';
		}

		Settings::save(
			array(
				'frontend_switcher_enabled'   => ! empty( $_POST['frontend_switcher_enabled'] ),
				'frontend_switcher_position'  => $position,
				'frontend_switcher_style'     => $style,
				'frontend_switcher_show_flag' => ! empty( $_POST['frontend_switcher_show_flag'] ),
				'auto_discover_strings'       => ! empty( $_POST['auto_discover_strings'] ),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'saved' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function render_notices(): void {
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'codeon-multilingual' ) . '</p></div>';
		}
	}
}
