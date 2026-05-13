<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin\Pages;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Rest\StringsApi;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Strings admin page — WPML-style: domain + status filter, per-language
 * columns with +/✏ icons, inline editor opened in-row, saved via REST API
 * (no page refresh).
 *
 * Two queries to render a page of 50 strings:
 *   1. SELECT … FROM cml_strings + translated_count subquery (search/domain/status filter applied)
 *   2. SELECT string_id, language, translation FROM cml_string_translations WHERE string_id IN (visible-ids)
 * The translation map is then embedded into the row dataset; no per-row queries.
 *
 * The full-page edit screen the previous version had is gone — inline editor
 * replaces it. The page is stateless: filters live in the URL, saves live in
 * REST, navigation is by ?paged=.
 */
final class StringsPage {

	public const PAGE_SLUG = 'cml-strings';

	private const ACTION_PURGE = 'cml_purge_strings';
	private const NONCE_PURGE  = 'cml_purge_strings';
	private const PER_PAGE     = 50;

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_post_' . self::ACTION_PURGE, array( self::class, 'handle_purge' ) );
		add_action( 'admin_enqueue_scripts',            array( self::class, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'multilingual_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$build_id = defined( 'CML_BUILD_ID' ) ? CML_BUILD_ID : '0';
		wp_enqueue_style(
			'cml-strings-admin',
			CML_URL . 'assets/strings-admin.css',
			array(),
			$build_id
		);
		wp_enqueue_script(
			'cml-strings-admin',
			CML_URL . 'assets/strings-admin.js',
			array(),
			$build_id,
			true
		);
		wp_localize_script(
			'cml-strings-admin',
			'cmlStringsData',
			array(
				'restUrl'   => esc_url_raw( rest_url( StringsApi::NAMESPACE . '/strings' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'save'              => __( 'Save', 'codeon-multilingual' ),
					'cancel'            => __( 'Cancel', 'codeon-multilingual' ),
					'saving'            => __( 'Saving…', 'codeon-multilingual' ),
					'error'             => __( 'Failed to save', 'codeon-multilingual' ),
					'source'            => __( 'Source', 'codeon-multilingual' ),
					'translateTo'       => __( 'Translate to', 'codeon-multilingual' ),
					'placeholder'       => __( 'Enter translation (Ctrl/⌘+Enter to save, Esc to cancel)', 'codeon-multilingual' ),
					'addTranslation'    => __( 'Add translation', 'codeon-multilingual' ),
					'editTranslation'   => __( 'Edit translation', 'codeon-multilingual' ),
				),
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		self::render_list();
	}

	private static function render_list(): void {
		global $wpdb;

		$search        = isset( $_GET['s'] )      ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) )      : '';
		$domain_filter = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['domain'] ) ) : '';
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) )        : '';
		$paged         = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$offset        = ( $paged - 1 ) * self::PER_PAGE;

		$all_languages     = Languages::active();
		$default_code      = Languages::default_code();
		$translation_langs = array_filter(
			$all_languages,
			static fn( $l ): bool => $l->code !== $default_code
		);
		$needed_count = count( $translation_langs );

		// Build WHERE + HAVING.
		$where        = '1=1';
		$prepare_args = array();
		if ( '' !== $search ) {
			$where         .= ' AND (s.source LIKE %s OR s.domain LIKE %s)';
			$prepare_args[] = '%' . $wpdb->esc_like( $search ) . '%';
			$prepare_args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		if ( '' !== $domain_filter ) {
			$where         .= ' AND s.domain = %s';
			$prepare_args[] = $domain_filter;
		}

		$having       = '';
		$having_args  = array();
		if ( $needed_count > 0 ) {
			if ( 'untranslated' === $status_filter ) {
				$having = ' HAVING translated_count = 0';
			} elseif ( 'partial' === $status_filter ) {
				$having       = ' HAVING translated_count > 0 AND translated_count < %d';
				$having_args[] = $needed_count;
			} elseif ( 'translated' === $status_filter ) {
				$having        = ' HAVING translated_count >= %d';
				$having_args[] = $needed_count;
			}
		}

		// Count for pagination (matches the same filters).
		$count_sql = "SELECT COUNT(*) FROM (
			SELECT s.id, (SELECT COUNT(*) FROM {$wpdb->prefix}cml_string_translations st WHERE st.string_id = s.id) AS translated_count
			FROM {$wpdb->prefix}cml_strings s
			WHERE {$where}
			{$having}
		) sub";
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( $count_sql, ...array_merge( $prepare_args, $having_args ) )
		);

		// Page of rows.
		$list_sql = "SELECT s.id, s.domain, s.context, s.source,
				(SELECT COUNT(*) FROM {$wpdb->prefix}cml_string_translations st WHERE st.string_id = s.id) AS translated_count
			FROM {$wpdb->prefix}cml_strings s
			WHERE {$where}
			{$having}
			ORDER BY translated_count ASC, s.id DESC
			LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$list_sql,
				...array_merge( $prepare_args, $having_args, array( self::PER_PAGE, $offset ) )
			)
		);

		// Batch-fetch translations for visible strings.
		$translations_map = array();
		if ( ! empty( $rows ) ) {
			$ids          = array_map( static fn( $r ): int => (int) $r->id, $rows );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$trans_rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT string_id, language, translation FROM {$wpdb->prefix}cml_string_translations WHERE string_id IN ({$placeholders})",
					...$ids
				)
			);
			foreach ( $trans_rows as $t ) {
				$translations_map[ (int) $t->string_id ][ (string) $t->language ] = (string) $t->translation;
			}
		}

		// Distinct domains for the filter dropdown.
		$domains = $wpdb->get_col( "SELECT DISTINCT domain FROM {$wpdb->prefix}cml_strings ORDER BY domain ASC" );

		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$purge_url   = wp_nonce_url(
			add_query_arg( array( 'action' => self::ACTION_PURGE ), admin_url( 'admin-post.php' ) ),
			self::NONCE_PURGE
		);

		if ( $needed_count <= 0 ) {
			self::render_no_languages_state();
			return;
		}
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Strings', 'codeon-multilingual' ); ?></h1>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<form method="get" class="cml-strings-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">

				<label class="screen-reader-text" for="cml-search"><?php esc_html_e( 'Search', 'codeon-multilingual' ); ?></label>
				<input type="search" id="cml-search" name="s"
					value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search source or domain…', 'codeon-multilingual' ); ?>">

				<label class="screen-reader-text" for="cml-domain-filter"><?php esc_html_e( 'Domain', 'codeon-multilingual' ); ?></label>
				<select id="cml-domain-filter" name="domain">
					<option value=""><?php esc_html_e( 'All domains', 'codeon-multilingual' ); ?></option>
					<?php foreach ( $domains as $d ) : ?>
						<option value="<?php echo esc_attr( (string) $d ); ?>" <?php selected( $domain_filter, $d ); ?>>
							<?php echo esc_html( (string) $d ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label class="screen-reader-text" for="cml-status-filter"><?php esc_html_e( 'Status', 'codeon-multilingual' ); ?></label>
				<select id="cml-status-filter" name="status">
					<option value=""              <?php selected( $status_filter, '' ); ?>><?php esc_html_e( 'All statuses', 'codeon-multilingual' ); ?></option>
					<option value="untranslated"  <?php selected( $status_filter, 'untranslated' ); ?>><?php esc_html_e( 'Untranslated', 'codeon-multilingual' ); ?></option>
					<option value="partial"       <?php selected( $status_filter, 'partial' ); ?>><?php esc_html_e( 'Partial', 'codeon-multilingual' ); ?></option>
					<option value="translated"    <?php selected( $status_filter, 'translated' ); ?>><?php esc_html_e( 'Fully translated', 'codeon-multilingual' ); ?></option>
				</select>

				<button type="submit" class="button"><?php esc_html_e( 'Apply', 'codeon-multilingual' ); ?></button>
				<?php if ( '' !== $search || '' !== $domain_filter || '' !== $status_filter ) : ?>
					<a class="button button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
						<?php esc_html_e( 'Reset', 'codeon-multilingual' ); ?>
					</a>
				<?php endif; ?>

				<span style="flex:1"></span>
				<span class="cml-total-count">
					<?php
					printf(
						/* translators: %d: total matching strings */
						esc_html__( '%d strings', 'codeon-multilingual' ),
						(int) $total
					);
					?>
				</span>
			</form>

			<table class="wp-list-table widefat fixed striped cml-strings-table">
				<thead>
					<tr>
						<th scope="col" class="cml-col-source"><?php esc_html_e( 'Source', 'codeon-multilingual' ); ?></th>
						<th scope="col" class="cml-col-domain"><?php esc_html_e( 'Domain', 'codeon-multilingual' ); ?></th>
						<th scope="col" class="cml-col-progress"><?php esc_html_e( 'Progress', 'codeon-multilingual' ); ?></th>
						<?php foreach ( $translation_langs as $code => $lang ) : ?>
							<th scope="col" class="cml-col-lang" title="<?php echo esc_attr( $lang->native ); ?>">
								<?php echo esc_html( strtoupper( $code ) ); ?>
							</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="<?php echo 3 + count( $translation_langs ); ?>">
								<?php esc_html_e( 'No strings match the current filters.', 'codeon-multilingual' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) :
							$id     = (int) $row->id;
							$preview = mb_strimwidth( (string) $row->source, 0, 200, '…' );
							?>
							<tr class="cml-string-row"
								data-string-id="<?php echo (int) $id; ?>"
								data-source="<?php echo esc_attr( (string) $row->source ); ?>"
								data-domain="<?php echo esc_attr( (string) $row->domain ); ?>"
								data-context="<?php echo esc_attr( (string) $row->context ); ?>">
								<td class="cml-col-source">
									<span class="cml-source-text"><?php echo esc_html( $preview ); ?></span>
								</td>
								<td class="cml-col-domain">
									<code><?php echo esc_html( (string) $row->domain ); ?></code>
								</td>
								<td class="cml-col-progress">
									<span class="cml-progress-badge<?php echo (int) $row->translated_count >= $needed_count ? ' cml-fully-translated' : ''; ?>"
										data-total="<?php echo (int) $needed_count; ?>">
										<?php echo (int) $row->translated_count . ' / ' . (int) $needed_count; ?>
									</span>
								</td>
								<?php foreach ( $translation_langs as $code => $lang ) :
									$existing = $translations_map[ $id ][ $code ] ?? null;
									$is_set   = null !== $existing && '' !== $existing;
									?>
									<td class="cml-col-lang">
										<a href="#"
											class="cml-translate-btn <?php echo $is_set ? 'cml-translated' : 'cml-missing'; ?>"
											data-string-id="<?php echo (int) $id; ?>"
											data-lang="<?php echo esc_attr( $code ); ?>"
											data-lang-native="<?php echo esc_attr( $lang->native ); ?>"
											data-translation="<?php echo esc_attr( $existing ?? '' ); ?>"
											title="<?php echo esc_attr( $is_set ? __( 'Edit translation', 'codeon-multilingual' ) : __( 'Add translation', 'codeon-multilingual' ) ); ?>">
											<span class="dashicons <?php echo $is_set ? 'dashicons-edit cml-icon-translated' : 'dashicons-plus-alt2 cml-icon-missing'; ?>" aria-hidden="true"></span>
										</a>
									</td>
								<?php endforeach; ?>
							</tr>
							<tr class="cml-edit-row" id="cml-edit-<?php echo (int) $id; ?>" style="display:none"></tr>
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
									'page'   => self::PAGE_SLUG,
									's'      => '' !== $search        ? $search        : null,
									'domain' => '' !== $domain_filter ? $domain_filter : null,
									'status' => '' !== $status_filter ? $status_filter : null,
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
				<a href="<?php echo esc_url( $purge_url ); ?>" class="button button-secondary"
					onclick="return confirm('<?php echo esc_js( __( 'Clear the string catalog? Existing translations are kept; sources are rediscovered as traffic flows.', 'codeon-multilingual' ) ); ?>');">
					<?php esc_html_e( 'Purge discovered sources', 'codeon-multilingual' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private static function render_no_languages_state(): void {
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Strings', 'codeon-multilingual' ); ?></h1>
			<hr class="wp-header-end">
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: admin URL for the Languages page */
						wp_kses(
							__( 'Add a non-default language on the <a href="%s">Languages page</a> to enable string translation.', 'codeon-multilingual' ),
							array( 'a' => array( 'href' => array() ) )
						),
						esc_url( admin_url( 'admin.php?page=' . AdminMenu::PARENT_SLUG ) )
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	public static function handle_purge(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_PURGE );

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}cml_strings" );
		$wpdb->query( "DELETE st FROM {$wpdb->prefix}cml_string_translations st LEFT JOIN {$wpdb->prefix}cml_strings s ON s.id = st.string_id WHERE s.id IS NULL" );

		StringTranslator::flush_cache();

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'purged' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function render_notices(): void {
		if ( isset( $_GET['purged'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Catalog purged. Strings will rediscover as traffic flows.', 'codeon-multilingual' )
				. '</p></div>';
		}
	}
}
