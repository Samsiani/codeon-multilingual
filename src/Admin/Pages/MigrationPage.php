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

		$import_url = wp_nonce_url(
			add_query_arg( array( 'action' => self::ACTION_IMPORT ), admin_url( 'admin-post.php' ) ),
			self::NONCE_IMPORT
		);
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
				</table>

				<p>
					<a href="<?php echo esc_url( $import_url ); ?>" class="button button-primary"
						onclick="return confirm('<?php echo esc_js( __( 'Run the WPML import? This is idempotent and can be re-run safely.', 'codeon-multilingual' ) ); ?>');">
						<?php esc_html_e( 'Import from WPML', 'codeon-multilingual' ); ?>
					</a>
				</p>

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

		$result = WpmlImporter::import_all();

		self::redirect_with(
			array(
				'imported'   => '1',
				'languages'  => (int) $result['languages'],
				'posts'      => (int) $result['posts'],
				'terms'      => (int) $result['terms'],
				'strings'    => (int) $result['strings'],
				'tstrings'   => (int) $result['translated_strings'],
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
		}
		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_key( wp_unslash( (string) $_GET['error'] ) );
			$msg   = match ( $error ) {
				'no_wpml' => __( 'WPML tables not detected. Cannot import.', 'codeon-multilingual' ),
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
