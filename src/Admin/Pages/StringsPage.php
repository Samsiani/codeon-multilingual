<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin\Pages;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Strings admin page — paginated list + per-language inline edit form.
 *
 * Lookups go through StringTranslator's compiled cache; the admin DB queries
 * here are infrequent (manual admin browsing) so they're written for clarity
 * not raw throughput. Saves invalidate the compiled cache via
 * StringTranslator::flush_cache().
 */
final class StringsPage {

	public const PAGE_SLUG = 'cml-strings';

	private const ACTION_SAVE  = 'cml_save_string';
	private const ACTION_PURGE = 'cml_purge_strings';
	private const NONCE_SAVE   = 'cml_save_string';
	private const NONCE_PURGE  = 'cml_purge_strings';
	private const PER_PAGE     = 50;

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_post_' . self::ACTION_SAVE, array( self::class, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_PURGE, array( self::class, 'handle_purge' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : 'list';
		if ( 'edit' === $action ) {
			self::render_edit();
		} else {
			self::render_list();
		}
	}

	private static function render_list(): void {
		global $wpdb;

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$where        = '1=1';
		$prepare_args = array();
		if ( '' !== $search ) {
			$where         .= ' AND (s.source LIKE %s OR s.domain LIKE %s)';
			$prepare_args[] = '%' . $wpdb->esc_like( $search ) . '%';
			$prepare_args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$count_args = $prepare_args;
		$total      = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}cml_strings s WHERE {$where}",
				...$count_args
			)
		);

		$list_args = array_merge( $prepare_args, array( self::PER_PAGE, $offset ) );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.domain, s.context, s.source,
				        (SELECT COUNT(*) FROM {$wpdb->prefix}cml_string_translations st WHERE st.string_id = s.id) AS translated_count
				 FROM {$wpdb->prefix}cml_strings s
				 WHERE {$where}
				 ORDER BY s.id DESC
				 LIMIT %d OFFSET %d",
				...$list_args
			)
		);

		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$lang_count  = count( Languages::active() ) - 1;
		$purge_url   = wp_nonce_url(
			add_query_arg( array( 'action' => self::ACTION_PURGE ), admin_url( 'admin-post.php' ) ),
			self::NONCE_PURGE
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Strings', 'codeon-multilingual' ); ?></h1>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<form method="get" style="margin-bottom:12px">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<p class="search-box">
					<label class="screen-reader-text" for="cml-string-search"><?php esc_html_e( 'Search strings', 'codeon-multilingual' ); ?></label>
					<input type="search" id="cml-string-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search source or domain…', 'codeon-multilingual' ); ?>">
					<?php submit_button( __( 'Search', 'codeon-multilingual' ), 'secondary', '', false ); ?>
				</p>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style="width:40%"><?php esc_html_e( 'Source', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Domain', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Context', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Translated', 'codeon-multilingual' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="4">
								<?php esc_html_e( 'No strings discovered yet. Browse the site (front or admin) to populate the catalog.', 'codeon-multilingual' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$edit_url = add_query_arg(
								array(
									'page'   => self::PAGE_SLUG,
									'action' => 'edit',
									'id'     => (int) $row->id,
								),
								admin_url( 'admin.php' )
							);
							$preview  = mb_strimwidth( (string) $row->source, 0, 200, '…' );
							?>
							<tr>
								<td>
									<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $preview ); ?></a></strong>
								</td>
								<td><code><?php echo esc_html( (string) $row->domain ); ?></code></td>
								<td><?php echo '' !== $row->context ? '<code>' . esc_html( (string) $row->context ) . '</code>' : '<span aria-hidden="true">—</span>'; ?></td>
								<td>
									<?php
									$count = (int) $row->translated_count;
									if ( $count >= $lang_count && $lang_count > 0 ) {
										echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450" aria-hidden="true"></span> ';
									}
									echo (int) $count . ' / ' . (int) $lang_count;
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						$base = add_query_arg(
							array_filter(
								array(
									'page' => self::PAGE_SLUG,
									's'    => '' !== $search ? $search : null,
								)
							),
							admin_url( 'admin.php' )
						);
						echo paginate_links(
							array(
								'base'      => $base . '%_%',
								'format'    => '&paged=%#%',
								'total'     => $total_pages,
								'current'   => $paged,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Catalog', 'codeon-multilingual' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %d: total number of discovered strings */
					esc_html__( '%d strings discovered.', 'codeon-multilingual' ),
					(int) $total
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( $purge_url ); ?>" class="button button-secondary"
					onclick="return confirm('<?php echo esc_js( __( 'Clear the string catalog? Existing translations are kept; sources are rediscovered as traffic flows.', 'codeon-multilingual' ) ); ?>');">
					<?php esc_html_e( 'Purge discovered sources', 'codeon-multilingual' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private static function render_edit(): void {
		global $wpdb;

		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( $id <= 0 ) {
			wp_die( esc_html__( 'Invalid string id.', 'codeon-multilingual' ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, domain, context, source FROM {$wpdb->prefix}cml_strings WHERE id = %d",
				$id
			)
		);
		if ( ! $row ) {
			wp_die( esc_html__( 'String not found.', 'codeon-multilingual' ) );
		}

		$translations_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT language, translation FROM {$wpdb->prefix}cml_string_translations WHERE string_id = %d",
				$id
			)
		);
		$existing          = array();
		if ( is_array( $translations_rows ) ) {
			foreach ( $translations_rows as $t ) {
				$existing[ (string) $t->language ] = (string) $t->translation;
			}
		}

		$languages = Languages::active();
		$default   = Languages::default_code();
		$back_url  = add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Translate string', 'codeon-multilingual' ); ?></h1>

			<?php self::render_notices(); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Source', 'codeon-multilingual' ); ?></th>
					<td><textarea rows="3" cols="60" readonly style="width:100%;font-family:monospace"><?php echo esc_textarea( (string) $row->source ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Domain', 'codeon-multilingual' ); ?></th>
					<td><code><?php echo esc_html( (string) $row->domain ); ?></code></td>
				</tr>
				<?php if ( '' !== $row->context ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Context', 'codeon-multilingual' ); ?></th>
						<td><code><?php echo esc_html( (string) $row->context ); ?></code></td>
					</tr>
				<?php endif; ?>
			</table>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php wp_nonce_field( self::NONCE_SAVE . '_' . $id ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
				<input type="hidden" name="string_id" value="<?php echo (int) $id; ?>">

				<h2><?php esc_html_e( 'Translations', 'codeon-multilingual' ); ?></h2>

				<table class="form-table" role="presentation">
					<?php foreach ( $languages as $code => $lang ) : ?>
						<?php
						if ( $code === $default ) {
							continue; }
						?>
						<tr>
							<th scope="row">
								<label for="cml-translation-<?php echo esc_attr( $code ); ?>">
									<?php echo esc_html( $lang->native ); ?>
									<br><small><code><?php echo esc_html( $code ); ?></code></small>
								</label>
							</th>
							<td>
								<textarea
									id="cml-translation-<?php echo esc_attr( $code ); ?>"
									name="translations[<?php echo esc_attr( $code ); ?>]"
									rows="3"
									cols="60"
									style="width:100%"
									<?php echo (int) $lang->rtl === 1 ? 'dir="rtl"' : ''; ?>
								><?php echo esc_textarea( $existing[ $code ] ?? '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Leave empty to remove this translation.', 'codeon-multilingual' ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button( __( 'Save translations', 'codeon-multilingual' ) ); ?>
				<a href="<?php echo esc_url( $back_url ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Back', 'codeon-multilingual' ); ?>
				</a>
			</form>
		</div>
		<?php
	}

	public static function handle_save(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		$string_id = isset( $_POST['string_id'] ) ? (int) $_POST['string_id'] : 0;
		check_admin_referer( self::NONCE_SAVE . '_' . $string_id );

		if ( $string_id <= 0 ) {
			wp_die( esc_html__( 'Invalid request.', 'codeon-multilingual' ) );
		}

		$raw_translations = isset( $_POST['translations'] ) && is_array( $_POST['translations'] )
			? wp_unslash( (array) $_POST['translations'] )
			: array();

		global $wpdb;
		$now = time();

		foreach ( $raw_translations as $lang_raw => $value ) {
			$lang = sanitize_key( (string) $lang_raw );
			if ( '' === $lang || ! Languages::exists_and_active( $lang ) ) {
				continue;
			}
			$translation = (string) $value;

			if ( '' === trim( $translation ) ) {
				$wpdb->delete(
					$wpdb->prefix . 'cml_string_translations',
					array(
						'string_id' => $string_id,
						'language'  => $lang,
					),
					array( '%d', '%s' )
				);
				continue;
			}

			$wpdb->query(
				$wpdb->prepare(
					"REPLACE INTO {$wpdb->prefix}cml_string_translations (string_id, language, translation, updated_at)
					 VALUES (%d, %s, %s, %d)",
					$string_id,
					$lang,
					$translation,
					$now
				)
			);
		}

		StringTranslator::flush_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'action' => 'edit',
					'id'     => $string_id,
					'saved'  => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_purge(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_PURGE );

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}cml_strings" );
		// translations are FK-less; orphaned rows are harmless but trim them anyway.
		$wpdb->query( "DELETE st FROM {$wpdb->prefix}cml_string_translations st LEFT JOIN {$wpdb->prefix}cml_strings s ON s.id = st.string_id WHERE s.id IS NULL" );

		StringTranslator::flush_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'purged' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function render_notices(): void {
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Translations saved.', 'codeon-multilingual' ) . '</p></div>';
		}
		if ( isset( $_GET['purged'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Catalog purged. Strings will rediscover as traffic flows.', 'codeon-multilingual' ) . '</p></div>';
		}
	}
}
