<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin\Pages;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Core\Settings;
use Samsiani\CodeonMultilingual\Strings\L10nFileWriter;

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

				<h2><?php esc_html_e( 'Performance — native .l10n.php files', 'codeon-multilingual' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Use native translation files', 'codeon-multilingual' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="use_native_l10n_files" value="1" <?php checked( (bool) $settings['use_native_l10n_files'] ); ?>>
								<?php esc_html_e( 'Write per-domain .l10n.php files to the uploads directory and load them via WP\'s native path.', 'codeon-multilingual' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Faster on hosts without a persistent object cache (Redis/Memcached) — translations skip the wp_cache round-trip and use WP 6.5+\'s native in-memory lookup. The wp_cache + gettext fallback stays active either way, so a failed write never breaks translation rendering.', 'codeon-multilingual' ); ?>
							</p>

							<?php $health = L10nFileWriter::health_check(); ?>
							<p>
								<strong><?php esc_html_e( 'Uploads directory:', 'codeon-multilingual' ); ?></strong>
								<code><?php echo esc_html( $health['path'] ); ?></code>
								<?php if ( $health['ok'] ) : ?>
									<span style="color:#1d6b1d;font-weight:600">✓ <?php esc_html_e( 'writable', 'codeon-multilingual' ); ?></span>
								<?php else : ?>
									<span style="color:#d63638;font-weight:600">⚠ <?php echo esc_html( self::label_for_health_reason( (string) $health['reason'] ) ); ?></span>
								<?php endif; ?>
							</p>

							<?php if ( (bool) $settings['use_native_l10n_files'] ) : ?>
								<p>
									<button type="submit" name="cml_regenerate_l10n" value="1" class="button">
										<?php esc_html_e( 'Regenerate all .l10n.php files now', 'codeon-multilingual' ); ?>
									</button>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'codeon-multilingual' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function label_for_health_reason( string $reason ): string {
		return match ( $reason ) {
			'mkdir_failed'      => __( 'directory cannot be created — using wp_cache fallback', 'codeon-multilingual' ),
			'not_writable'      => __( 'directory is not writable — using wp_cache fallback', 'codeon-multilingual' ),
			'write_failed'      => __( 'previous file write failed — using wp_cache fallback', 'codeon-multilingual' ),
			'rename_failed'     => __( 'previous atomic rename failed — using wp_cache fallback', 'codeon-multilingual' ),
			'previous_failure'  => __( 'previous write failed — using wp_cache fallback', 'codeon-multilingual' ),
			default             => __( 'unknown error — using wp_cache fallback', 'codeon-multilingual' ),
		};
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

		$previous_l10n = (bool) Settings::get( 'use_native_l10n_files', false );
		$new_l10n      = ! empty( $_POST['use_native_l10n_files'] );

		Settings::save(
			array(
				'frontend_switcher_enabled'   => ! empty( $_POST['frontend_switcher_enabled'] ),
				'frontend_switcher_position'  => $position,
				'frontend_switcher_style'     => $style,
				'frontend_switcher_show_flag' => ! empty( $_POST['frontend_switcher_show_flag'] ),
				'auto_discover_strings'       => ! empty( $_POST['auto_discover_strings'] ),
				'use_native_l10n_files'       => $new_l10n,
			)
		);

		// Toggle handling: bulk-regenerate on enable, full cleanup on disable,
		// explicit "regenerate now" button.
		$regen   = ! empty( $_POST['cml_regenerate_l10n'] );
		$args    = array( 'page' => self::PAGE_SLUG, 'saved' => '1' );

		if ( ! $previous_l10n && $new_l10n ) {
			$result = L10nFileWriter::regenerate_all();
			$args['regenerated'] = (int) $result['count'];
			if ( ! empty( $result['errors'] ) ) {
				$args['regen_errors'] = count( $result['errors'] );
			}
		} elseif ( $previous_l10n && ! $new_l10n ) {
			L10nFileWriter::delete_all();
			$args['l10n_disabled'] = '1';
		} elseif ( $new_l10n && $regen ) {
			$result = L10nFileWriter::regenerate_all();
			$args['regenerated'] = (int) $result['count'];
			if ( ! empty( $result['errors'] ) ) {
				$args['regen_errors'] = count( $result['errors'] );
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function render_notices(): void {
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'codeon-multilingual' ) . '</p></div>';
		}
		if ( isset( $_GET['regenerated'] ) ) {
			$count  = (int) $_GET['regenerated'];
			$errors = isset( $_GET['regen_errors'] ) ? (int) $_GET['regen_errors'] : 0;
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of regenerated files */
						__( 'Regenerated %d .l10n.php file(s).', 'codeon-multilingual' ),
						$count
					)
				)
			);
			if ( $errors > 0 ) {
				printf(
					'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
					esc_html(
						sprintf(
							/* translators: %d: number of (domain, locale) pairs that failed */
							__( '%d (domain, locale) pair(s) failed to write — wp_cache fallback handles them.', 'codeon-multilingual' ),
							$errors
						)
					)
				);
			}
		}
		if ( isset( $_GET['l10n_disabled'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>'
				. esc_html__( 'Native .l10n.php files disabled. Existing files removed. The wp_cache + gettext path is now sole source of translations.', 'codeon-multilingual' )
				. '</p></div>';
		}
	}
}
