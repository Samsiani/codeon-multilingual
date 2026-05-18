<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin\Pages;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Migration\WpmlImporter;

/**
 * Admin → Multilingual → Migration screen.
 *
 * Detects WPML's icl_* tables, shows a preview of what would be imported, and
 * exposes a single "Import from WPML" button. The importer is idempotent so
 * the button can be clicked repeatedly without duplicating data.
 */
final class MigrationPage {

	public const PAGE_SLUG = 'cml-migration';

	private const ACTION_IMPORT = 'cml_run_wpml_import';
	private const NONCE_IMPORT  = 'cml_run_wpml_import';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_post_' . self::ACTION_IMPORT, array( self::class, 'handle_import' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		$available = WpmlImporter::is_available();
		$summary   = WpmlImporter::summary();

		$conflicts     = $summary['conflicts'];
		$has_conflicts = $conflicts['language_settings'] > 0
			|| $conflicts['post_mappings'] > 0
			|| $conflicts['term_mappings'] > 0
			|| $conflicts['string_translations'] > 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Migration', 'codeon-multilingual' ); ?></h1>

			<?php self::render_notices(); ?>

			<h2><?php esc_html_e( 'WPML', 'codeon-multilingual' ); ?></h2>

			<?php if ( ! $available ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'WPML tables not detected. Nothing to migrate.', 'codeon-multilingual' ); ?></p>
				</div>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'CodeOn Multilingual found WPML data on this site. The importer copies WPML\'s translation groups, language tags, and string translations into CodeOn\'s tables. WPML\'s own tables are left untouched — you can deactivate WPML afterwards.', 'codeon-multilingual' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Detected default language', 'codeon-multilingual' ); ?></th>
						<td>
							<code><?php echo esc_html( (string) ( $summary['default_language'] ?? '—' ) ); ?></code>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Languages', 'codeon-multilingual' ); ?></th>
						<td><?php echo (int) $summary['languages']; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post translations', 'codeon-multilingual' ); ?></th>
						<td><?php echo (int) $summary['posts']; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Term translations', 'codeon-multilingual' ); ?></th>
						<td><?php echo (int) $summary['terms']; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'String sources', 'codeon-multilingual' ); ?></th>
						<td><?php echo (int) $summary['strings']; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Translated strings', 'codeon-multilingual' ); ?></th>
						<td><?php echo (int) $summary['translated_strings']; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Conflicts', 'codeon-multilingual' ); ?></th>
						<td>
							<?php if ( $has_conflicts ) : ?>
								<strong style="color:#b32d2e"><?php esc_html_e( 'Review required', 'codeon-multilingual' ); ?></strong>
							<?php else : ?>
								<?php esc_html_e( 'None detected', 'codeon-multilingual' ); ?>
							<?php endif; ?>
							<ul style="margin:6px 0 0 20px;list-style:disc">
								<li><?php printf( esc_html__( 'Language settings: %d', 'codeon-multilingual' ), (int) $conflicts['language_settings'] ); ?></li>
								<li><?php printf( esc_html__( 'Post mappings: %d', 'codeon-multilingual' ), (int) $conflicts['post_mappings'] ); ?></li>
								<li><?php printf( esc_html__( 'Term mappings: %d', 'codeon-multilingual' ), (int) $conflicts['term_mappings'] ); ?></li>
								<li><?php printf( esc_html__( 'String translations: %d', 'codeon-multilingual' ), (int) $conflicts['string_translations'] ); ?></li>
							</ul>
						</td>
					</tr>
				</table>

				<?php if ( $has_conflicts ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php esc_html_e( 'Existing CodeOn data conflicts with WPML data. The admin importer is blocked to avoid overwriting live language settings, mappings, or translations. Resolve the conflicts manually, reset the affected CodeOn data, or use WP-CLI with an explicit conflict policy.', 'codeon-multilingual' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IMPORT ); ?>">
					<?php wp_nonce_field( self::NONCE_IMPORT ); ?>
					<p>
						<label>
							<input type="checkbox" name="cml_confirm_backup" value="1" required <?php disabled( $has_conflicts ); ?>>
							<?php esc_html_e( 'I have a recent database backup and understand this import writes to CodeOn tables.', 'codeon-multilingual' ); ?>
						</label>
					</p>
					<p>
						<button type="submit" class="button button-primary" <?php disabled( $has_conflicts ); ?>>
							<?php esc_html_e( 'Import from WPML', 'codeon-multilingual' ); ?>
						</button>
					</p>
				</form>

				<h3><?php esc_html_e( 'Out of scope (v0.3)', 'codeon-multilingual' ); ?></h3>
				<ul style="margin-left:20px;list-style:disc">
					<li><?php esc_html_e( 'String packages (Yoast / ACF / Elementor blob translations) — manual re-translation needed.', 'codeon-multilingual' ); ?></li>
					<li><?php esc_html_e( 'Custom-field translations registered via WPML\'s wpml-config.xml — re-register against CodeOn\'s filters.', 'codeon-multilingual' ); ?></li>
					<li><?php esc_html_e( 'Multi-currency configuration — CodeOn does not implement multi-currency.', 'codeon-multilingual' ); ?></li>
					<li><?php esc_html_e( 'Translation workflow / status metadata — not modelled in CodeOn.', 'codeon-multilingual' ); ?></li>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_import(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_IMPORT );

		if ( ! WpmlImporter::is_available() ) {
			self::redirect_with( array( 'error' => 'no_wpml' ) );
		}
		if ( empty( $_POST['cml_confirm_backup'] ) ) {
			self::redirect_with( array( 'error' => 'backup_required' ) );
		}

		$result = WpmlImporter::import_all();

		self::redirect_with(
			array(
				'imported'   => '1',
				'languages'  => (int) $result['languages'],
				'posts'      => (int) $result['posts'],
				'terms'      => (int) $result['terms'],
				'strings'    => (int) $result['strings'],
				'tstrings'   => (int) $result['translated_strings'],
				'lconflicts' => (int) $result['conflicts']['language_settings'],
				'pconflicts' => (int) $result['conflicts']['post_mappings'],
				'tconflicts' => (int) $result['conflicts']['term_mappings'],
				'sconflicts' => (int) $result['conflicts']['string_translations'],
				'errors'     => empty( $result['errors'] ) ? '' : implode( '||', $result['errors'] ),
			)
		);
	}

	private static function render_notices(): void {
		if ( isset( $_GET['imported'] ) ) {
			$summary = sprintf(
				/* translators: 1: languages, 2: posts, 3: terms, 4: strings, 5: translated strings */
				esc_html__( 'Import complete — languages: %1$d, post translations: %2$d, term translations: %3$d, string sources: %4$d, string translations: %5$d.', 'codeon-multilingual' ),
				(int) ( $_GET['languages'] ?? 0 ),
				(int) ( $_GET['posts'] ?? 0 ),
				(int) ( $_GET['terms'] ?? 0 ),
				(int) ( $_GET['strings'] ?? 0 ),
				(int) ( $_GET['tstrings'] ?? 0 )
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $summary ) . '</p></div>';

			$errors_raw = isset( $_GET['errors'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['errors'] ) ) : '';
			if ( '' !== $errors_raw ) {
				echo '<div class="notice notice-warning is-dismissible"><p><strong>'
					. esc_html__( 'Some steps reported errors:', 'codeon-multilingual' )
					. '</strong></p><ul style="list-style:disc;margin-left:24px">';
				foreach ( explode( '||', $errors_raw ) as $e ) {
					echo '<li><code>' . esc_html( $e ) . '</code></li>';
				}
				echo '</ul></div>';
			}
			$conflict_total = (int) ( $_GET['lconflicts'] ?? 0 )
				+ (int) ( $_GET['pconflicts'] ?? 0 )
				+ (int) ( $_GET['tconflicts'] ?? 0 )
				+ (int) ( $_GET['sconflicts'] ?? 0 );
			if ( $conflict_total > 0 ) {
				echo '<div class="notice notice-warning is-dismissible"><p>'
					. esc_html__( 'Preflight detected existing CodeOn rows that disagree with WPML data. Conflicting rows were not overwritten.', 'codeon-multilingual' )
					. '</p></div>';
			}
		}
		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_key( wp_unslash( (string) $_GET['error'] ) );
			$msg   = match ( $error ) {
				'no_wpml' => __( 'WPML tables not detected. Cannot import.', 'codeon-multilingual' ),
				'backup_required' => __( 'Confirm that you have a recent database backup before running the import.', 'codeon-multilingual' ),
				default   => __( 'Import failed.', 'codeon-multilingual' ),
			};
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function redirect_with( array $args ): void {
		$args = array_merge( array( 'page' => self::PAGE_SLUG ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
