<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin\Pages;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Core\Backfill;
use Samsiani\CodeonMultilingual\Core\LanguageCatalog;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Admin\Pages\SetupWizard;

/**
 * Languages list + add/edit form + delete + set-default handlers.
 *
 * One screen, dispatched by ?action= (list / new / edit). Mutations route through
 * admin-post.php with per-row nonces. Languages::flush_cache() runs after every
 * mutation so the request- and object-cache layers stay consistent.
 */
final class LanguagesPage {

	private const ACTION_SAVE    = 'cml_save_language';
	private const ACTION_DELETE  = 'cml_delete_language';
	private const ACTION_DEFAULT = 'cml_set_default_language';

	private const NONCE_SAVE    = 'cml_save_language';
	private const NONCE_DELETE  = 'cml_delete_language';
	private const NONCE_DEFAULT = 'cml_set_default_language';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_post_' . self::ACTION_SAVE, array( self::class, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( self::class, 'handle_delete' ) );
		add_action( 'admin_post_' . self::ACTION_DEFAULT, array( self::class, 'handle_set_default' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : 'list';

		if ( 'new' === $action || 'edit' === $action ) {
			self::render_form();
		} else {
			self::render_list();
		}
	}

	private static function render_list(): void {
		$languages = Languages::all();
		$default   = Languages::default_code();
		$backfill  = Backfill::status();
		$add_url   = self::admin_page_url( array( 'action' => 'new' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Languages', 'codeon-multilingual' ); ?></h1>
			<a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'codeon-multilingual' ); ?></a>
			<a href="<?php echo esc_url( SetupWizard::url( 1 ) ); ?>" class="page-title-action"><?php esc_html_e( 'Run setup wizard', 'codeon-multilingual' ); ?></a>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<style>
				.cml-langs-table .cml-row-flag {
					display: inline-block;
					vertical-align: middle;
					margin-right: 6px;
					width: 22px;
					text-align: center;
					line-height: 0;
				}
				.cml-langs-table .cml-row-flag img {
					display: inline-block;
					width: 22px;
					height: auto;
					border-radius: 2px;
					box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.08);
					vertical-align: middle;
				}
				.cml-langs-table .cml-row-flag .cml-flag-emoji {
					font-size: 18px;
					line-height: 1;
				}
			</style>
			<table class="wp-list-table widefat fixed striped cml-langs-table">
				<thead>
					<tr>
						<th scope="col" style="width:32px"><span class="screen-reader-text"><?php esc_html_e( 'Flag', 'codeon-multilingual' ); ?></span></th>
						<th scope="col"><?php esc_html_e( 'Code', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Name', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Native', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Locale', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Default', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Active', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'RTL', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Position', 'codeon-multilingual' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $languages ) : ?>
						<tr><td colspan="9"><?php esc_html_e( 'No languages configured.', 'codeon-multilingual' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $languages as $lang ) : ?>
							<tr>
								<td class="cml-row-flag-cell">
									<span class="cml-row-flag"><?php echo self::flag_html( (string) $lang->flag, (string) $lang->name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — already escaped inside helper ?></span>
								</td>
								<td>
									<strong><code><?php echo esc_html( $lang->code ); ?></code></strong>
									<?php self::render_row_actions( $lang, $default ); ?>
								</td>
								<td><?php echo esc_html( $lang->name ); ?></td>
								<td><?php echo esc_html( $lang->native ); ?></td>
								<td><code><?php echo esc_html( $lang->locale ); ?></code></td>
								<td>
									<?php if ( $lang->code === $default ) : ?>
										<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php esc_html_e( 'Default', 'codeon-multilingual' ); ?></span>
									<?php else : ?>
										<span aria-hidden="true">—</span>
									<?php endif; ?>
								</td>
								<td><?php echo (int) $lang->active ? '✓' : '—'; ?></td>
								<td><?php echo (int) $lang->rtl ? '✓' : '—'; ?></td>
								<td><?php echo (int) $lang->position; ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Backfill', 'codeon-multilingual' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: backfill status, 2: last processed post ID, 3: posts remaining */
					esc_html__( 'Status: %1$s · last processed id: %2$d · remaining: %3$d', 'codeon-multilingual' ),
					'<code>' . esc_html( $backfill['status'] ) . '</code>',
					(int) $backfill['last_id'],
					(int) $backfill['remaining']
				);
				?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Tags every existing post with the default language so router filtering covers legacy content. Runs in 500-row batches via WP-Cron.', 'codeon-multilingual' ); ?>
			</p>

			<h2><?php esc_html_e( 'Diagnostics', 'codeon-multilingual' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Build ID', 'codeon-multilingual' ); ?></th>
					<td><code><?php echo esc_html( defined( 'CML_BUILD_ID' ) ? CML_BUILD_ID : '—' ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Plugin version', 'codeon-multilingual' ); ?></th>
					<td><code><?php echo esc_html( defined( 'CML_VERSION' ) ? CML_VERSION : '—' ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default language', 'codeon-multilingual' ); ?></th>
					<td><code><?php echo esc_html( $default ); ?></code></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the flag for a row: bundled SVG <img> when one ships for the
	 * country code, Unicode emoji fallback when not. Returned markup is
	 * already escaped — caller must NOT re-escape.
	 */
	private static function flag_html( string $country_code, string $alt ): string {
		$code = trim( $country_code );
		if ( '' === $code ) {
			return '<span class="cml-flag-emoji" aria-hidden="true">—</span>';
		}
		$svg_url = LanguageCatalog::flag_svg_url( $code );
		if ( null !== $svg_url ) {
			return '<img src="' . esc_url( $svg_url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy">';
		}
		$emoji = LanguageCatalog::flag_emoji( $code );
		if ( '' !== $emoji ) {
			return '<span class="cml-flag-emoji" aria-hidden="true">' . esc_html( $emoji ) . '</span>';
		}
		return '<span class="cml-flag-emoji" aria-hidden="true">—</span>';
	}

	private static function render_row_actions( object $lang, string $default ): void {
		$edit_url    = self::admin_page_url(
			array(
				'action' => 'edit',
				'code'   => $lang->code,
			)
		);
		$delete_url  = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_DELETE,
					'code'   => $lang->code,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_DELETE . '_' . $lang->code
		);
		$default_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_DEFAULT,
					'code'   => $lang->code,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_DEFAULT . '_' . $lang->code
		);
		?>
		<div class="row-actions">
			<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'codeon-multilingual' ); ?></a></span>
			<?php if ( $lang->code !== $default ) : ?>
				| <span class="set-default"><a href="<?php echo esc_url( $default_url ); ?>"><?php esc_html_e( 'Set as default', 'codeon-multilingual' ); ?></a></span>
				| <span class="trash"><a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this language? Posts tagged with it become orphaned until re-tagged.', 'codeon-multilingual' ) ); ?>');"><?php esc_html_e( 'Delete', 'codeon-multilingual' ); ?></a></span>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_form(): void {
		$edit_code = isset( $_GET['code'] ) ? sanitize_key( wp_unslash( (string) $_GET['code'] ) ) : '';
		$editing   = '' !== $edit_code ? Languages::get( $edit_code ) : null;
		$is_edit   = null !== $editing;

		$values = $is_edit ? array(
			'code'       => $editing->code,
			'locale'     => $editing->locale,
			'name'       => $editing->name,
			'native'     => $editing->native,
			'flag'       => $editing->flag,
			'rtl'        => (int) $editing->rtl,
			'active'     => (int) $editing->active,
			'is_default' => (int) $editing->is_default,
			'position'   => (int) $editing->position,
		) : array(
			'code'       => '',
			'locale'     => '',
			'name'       => '',
			'native'     => '',
			'flag'       => '',
			'rtl'        => 0,
			'active'     => 1,
			'is_default' => 0,
			'position'   => 10,
		);
		?>
		<div class="wrap">
			<h1>
				<?php
				echo $is_edit
					? esc_html__( 'Edit Language', 'codeon-multilingual' )
					: esc_html__( 'Add Language', 'codeon-multilingual' );
				?>
			</h1>

			<?php self::render_notices(); ?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php wp_nonce_field( self::NONCE_SAVE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="edit_code" value="<?php echo esc_attr( $values['code'] ); ?>">
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cml-code"><?php esc_html_e( 'Code', 'codeon-multilingual' ); ?></label></th>
						<td>
							<input type="text" id="cml-code" name="code"
								value="<?php echo esc_attr( $values['code'] ); ?>"
								pattern="[a-z]{2,3}(-[a-z0-9]+)?"
								class="regular-text"
								required
								<?php disabled( $is_edit ); ?>>
							<p class="description"><?php esc_html_e( 'Lowercase, 2-3 letters, optional region suffix (e.g., en, ka, pt-br). Immutable after creation.', 'codeon-multilingual' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cml-locale"><?php esc_html_e( 'Locale', 'codeon-multilingual' ); ?></label></th>
						<td>
							<input type="text" id="cml-locale" name="locale"
								value="<?php echo esc_attr( $values['locale'] ); ?>"
								class="regular-text"
								required>
							<p class="description"><?php esc_html_e( 'WordPress locale (e.g., en_US, ka_GE, pt_BR).', 'codeon-multilingual' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cml-name"><?php esc_html_e( 'Name (English)', 'codeon-multilingual' ); ?></label></th>
						<td>
							<input type="text" id="cml-name" name="name"
								value="<?php echo esc_attr( $values['name'] ); ?>"
								class="regular-text"
								required>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cml-native"><?php esc_html_e( 'Native name', 'codeon-multilingual' ); ?></label></th>
						<td>
							<input type="text" id="cml-native" name="native"
								value="<?php echo esc_attr( $values['native'] ); ?>"
								class="regular-text"
								required>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cml-flag"><?php esc_html_e( 'Flag code', 'codeon-multilingual' ); ?></label></th>
						<td>
							<input type="text" id="cml-flag" name="flag"
								value="<?php echo esc_attr( $values['flag'] ); ?>"
								class="regular-text">
							<p class="description"><?php esc_html_e( 'Optional. Country code for flag images (us, ge, ru, …).', 'codeon-multilingual' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'codeon-multilingual' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="active" value="1" <?php checked( $values['active'], 1 ); ?>> <?php esc_html_e( 'Active', 'codeon-multilingual' ); ?></label><br>
								<label><input type="checkbox" name="rtl" value="1" <?php checked( $values['rtl'], 1 ); ?>> <?php esc_html_e( 'Right-to-left', 'codeon-multilingual' ); ?></label><br>
								<label><input type="checkbox" name="is_default" value="1" <?php checked( $values['is_default'], 1 ); ?>> <?php esc_html_e( 'Default language', 'codeon-multilingual' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cml-position"><?php esc_html_e( 'Position', 'codeon-multilingual' ); ?></label></th>
						<td>
							<input type="number" id="cml-position" name="position"
								value="<?php echo esc_attr( (string) $values['position'] ); ?>"
								min="0" max="999"
								class="small-text">
							<p class="description"><?php esc_html_e( 'Sort order in switchers (lower = first).', 'codeon-multilingual' ); ?></p>
						</td>
					</tr>
				</table>

				<?php
				submit_button(
					$is_edit
						? __( 'Update Language', 'codeon-multilingual' )
						: __( 'Add Language', 'codeon-multilingual' )
				);
				?>
				<a href="<?php echo esc_url( self::admin_page_url() ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Cancel', 'codeon-multilingual' ); ?>
				</a>
			</form>

			<?php if ( ! $is_edit ) : ?>
				<?php
				// Trim the catalog to just the fields the JS uses so we don't ship
				// `major` / `description` bytes to every admin page load.
				$catalog_map = array();
				foreach ( LanguageCatalog::all() as $entry ) {
					$catalog_map[ $entry['code'] ] = array(
						'locale' => $entry['locale'],
						'name'   => $entry['name'],
						'native' => $entry['native'],
						'flag'   => $entry['flag'],
						'rtl'    => $entry['rtl'],
					);
				}
				?>
				<script>
					(function () {
						const CATALOG = <?php echo wp_json_encode( $catalog_map ); ?>;
						const codeInput   = document.getElementById('cml-code');
						const localeInput = document.getElementById('cml-locale');
						const nameInput   = document.getElementById('cml-name');
						const nativeInput = document.getElementById('cml-native');
						const flagInput   = document.getElementById('cml-flag');
						const rtlInput    = document.querySelector('input[name="rtl"]');
						if (!codeInput) return;

						function applyEntry(entry) {
							// Only overwrite fields the admin hasn't manually customised.
							if (!localeInput.value || localeInput.dataset.cmlAuto) {
								localeInput.value = entry.locale;
								localeInput.dataset.cmlAuto = '1';
							}
							if (!nameInput.value || nameInput.dataset.cmlAuto) {
								nameInput.value = entry.name;
								nameInput.dataset.cmlAuto = '1';
							}
							if (!nativeInput.value || nativeInput.dataset.cmlAuto) {
								nativeInput.value = entry.native;
								nativeInput.dataset.cmlAuto = '1';
							}
							if (!flagInput.value || flagInput.dataset.cmlAuto) {
								flagInput.value = entry.flag;
								flagInput.dataset.cmlAuto = '1';
							}
							if (rtlInput && rtlInput.dataset.cmlAuto !== 'manual') {
								rtlInput.checked = entry.rtl === 1;
								rtlInput.dataset.cmlAuto = '1';
							}
						}

						codeInput.addEventListener('input', function () {
							const code = codeInput.value.trim().toLowerCase();
							if (CATALOG[code]) {
								applyEntry(CATALOG[code]);
							}
						});

						// Clear the auto-fill marker once the admin actively edits a field;
						// from then on we won't overwrite it.
						[localeInput, nameInput, nativeInput, flagInput].forEach(function (el) {
							if (!el) return;
							el.addEventListener('input', function () { delete el.dataset.cmlAuto; });
						});
						if (rtlInput) {
							rtlInput.addEventListener('change', function () {
								rtlInput.dataset.cmlAuto = 'manual';
							});
						}
					})();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_notices(): void {
		if ( isset( $_GET['saved'] ) ) {
			self::notice( 'success', __( 'Language saved.', 'codeon-multilingual' ) );
		}
		if ( isset( $_GET['deleted'] ) ) {
			self::notice( 'success', __( 'Language deleted.', 'codeon-multilingual' ) );
		}
		if ( isset( $_GET['default_set'] ) ) {
			self::notice( 'success', __( 'Default language updated.', 'codeon-multilingual' ) );
		}
		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_key( wp_unslash( (string) $_GET['error'] ) );
			$msg   = match ( $error ) {
				'invalid_code'          => __( 'Invalid language code.', 'codeon-multilingual' ),
				'duplicate_code'        => __( 'A language with this code already exists.', 'codeon-multilingual' ),
				'missing_fields'        => __( 'Required fields are missing.', 'codeon-multilingual' ),
				'cannot_delete_default' => __( 'Cannot delete the default language. Set another as default first.', 'codeon-multilingual' ),
				'not_found'             => __( 'Language not found.', 'codeon-multilingual' ),
				default                 => __( 'Unknown error.', 'codeon-multilingual' ),
			};
			self::notice( 'error', $msg );
		}
	}

	private static function notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	public static function handle_save(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_SAVE );

		$edit_code = isset( $_POST['edit_code'] ) ? sanitize_key( wp_unslash( (string) $_POST['edit_code'] ) ) : '';
		$is_edit   = '' !== $edit_code;

		$code       = $is_edit ? $edit_code : sanitize_key( wp_unslash( (string) ( $_POST['code'] ?? '' ) ) );
		$locale     = sanitize_text_field( wp_unslash( (string) ( $_POST['locale'] ?? '' ) ) );
		$name       = sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) );
		$native     = sanitize_text_field( wp_unslash( (string) ( $_POST['native'] ?? '' ) ) );
		$flag       = sanitize_text_field( wp_unslash( (string) ( $_POST['flag'] ?? '' ) ) );
		$active     = isset( $_POST['active'] ) ? 1 : 0;
		$rtl        = isset( $_POST['rtl'] ) ? 1 : 0;
		$is_default = isset( $_POST['is_default'] ) ? 1 : 0;
		$position   = isset( $_POST['position'] ) ? (int) $_POST['position'] : 0;

		if ( ! preg_match( '/^[a-z]{2,3}(-[a-z0-9]+)?$/', $code ) ) {
			self::redirect_with( array( 'error' => 'invalid_code' ) );
		}
		if ( '' === $locale || '' === $name || '' === $native ) {
			self::redirect_with( array( 'error' => 'missing_fields' ) );
		}
		if ( ! $is_edit && Languages::exists( $code ) ) {
			self::redirect_with( array( 'error' => 'duplicate_code' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cml_languages';

		// Only one row may be default. Strip any current default before writing.
		if ( 1 === $is_default ) {
			$wpdb->update( $table, array( 'is_default' => 0 ), array( 'is_default' => 1 ), array( '%d' ), array( '%d' ) );
		}

		$data    = array(
			'locale'     => $locale,
			'name'       => $name,
			'native'     => $native,
			'flag'       => $flag,
			'rtl'        => $rtl,
			'active'     => $active,
			'is_default' => $is_default,
			'position'   => $position,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' );

		if ( $is_edit ) {
			$wpdb->update( $table, $data, array( 'code' => $code ), $formats, array( '%s' ) );
		} else {
			$data['code'] = $code;
			$formats[]    = '%s';
			$wpdb->insert( $table, $data, $formats );
		}

		Languages::flush_cache();

		self::redirect_with( array( 'saved' => '1' ) );
	}

	public static function handle_delete(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_key( wp_unslash( (string) $_GET['code'] ) ) : '';
		check_admin_referer( self::NONCE_DELETE . '_' . $code );

		$lang = Languages::get( $code );
		if ( null === $lang ) {
			self::redirect_with( array( 'error' => 'not_found' ) );
		}
		if ( 1 === (int) $lang->is_default ) {
			self::redirect_with( array( 'error' => 'cannot_delete_default' ) );
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'cml_languages', array( 'code' => $code ), array( '%s' ) );
		Languages::flush_cache();

		self::redirect_with( array( 'deleted' => '1' ) );
	}

	public static function handle_set_default(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		$code = isset( $_GET['code'] ) ? sanitize_key( wp_unslash( (string) $_GET['code'] ) ) : '';
		check_admin_referer( self::NONCE_DEFAULT . '_' . $code );

		$lang = Languages::get( $code );
		if ( null === $lang ) {
			self::redirect_with( array( 'error' => 'not_found' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cml_languages';
		$wpdb->update( $table, array( 'is_default' => 0 ), array( 'is_default' => 1 ), array( '%d' ), array( '%d' ) );
		$wpdb->update( $table, array( 'is_default' => 1 ), array( 'code' => $code ), array( '%d' ), array( '%s' ) );
		Languages::flush_cache();

		self::redirect_with( array( 'default_set' => '1' ) );
	}

	/**
	 * @param array<string, string> $args
	 */
	private static function admin_page_url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => AdminMenu::PARENT_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param array<string, string> $args
	 */
	private static function redirect_with( array $args ): void {
		wp_safe_redirect( self::admin_page_url( $args ) );
		exit;
	}
}
