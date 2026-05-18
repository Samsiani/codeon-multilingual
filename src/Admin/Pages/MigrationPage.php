<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin\Pages;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Migration\PolylangImporter;
use Samsiani\CodeonMultilingual\Migration\WpmlImporter;
use Samsiani\CodeonMultilingual\Migration\WpmlMigrationSnapshot;

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
	private const ACTION_IMPORT_POLYLANG = 'cml_run_polylang_import';
	private const ACTION_EXPORT = 'cml_export_wpml_migration_snapshot';
	private const ACTION_RESTORE = 'cml_restore_wpml_migration_snapshot';
	private const NONCE_IMPORT  = 'cml_run_wpml_import';
	private const NONCE_IMPORT_POLYLANG = 'cml_run_polylang_import';
	private const NONCE_EXPORT  = 'cml_export_wpml_migration_snapshot';
	private const NONCE_RESTORE = 'cml_restore_wpml_migration_snapshot';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_post_' . self::ACTION_IMPORT, array( self::class, 'handle_import' ) );
		add_action( 'admin_post_' . self::ACTION_IMPORT_POLYLANG, array( self::class, 'handle_polylang_import' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( self::class, 'handle_export' ) );
		add_action( 'admin_post_' . self::ACTION_RESTORE, array( self::class, 'handle_restore' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		$available = WpmlImporter::is_available();
		$summary   = WpmlImporter::summary();
		$snapshot  = WpmlMigrationSnapshot::report();

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

			<h3><?php esc_html_e( 'CodeOn rollback snapshot', 'codeon-multilingual' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Export the current CodeOn multilingual tables before importing WPML data. The JSON file can be restored from this page or WP-CLI if you need to roll back the migration.', 'codeon-multilingual' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Snapshot rows', 'codeon-multilingual' ); ?></th>
					<td>
							<?php esc_html_e( 'Total:', 'codeon-multilingual' ); ?> <?php echo (int) $snapshot['total_rows']; ?>
							<ul style="margin:6px 0 0 20px;list-style:disc">
								<li><?php esc_html_e( 'Languages:', 'codeon-multilingual' ); ?> <?php echo (int) $snapshot['tables']['languages']; ?></li>
								<li><?php esc_html_e( 'Post mappings:', 'codeon-multilingual' ); ?> <?php echo (int) $snapshot['tables']['post_language']; ?></li>
								<li><?php esc_html_e( 'Term mappings:', 'codeon-multilingual' ); ?> <?php echo (int) $snapshot['tables']['term_language']; ?></li>
								<li><?php esc_html_e( 'String sources:', 'codeon-multilingual' ); ?> <?php echo (int) $snapshot['tables']['strings']; ?></li>
								<li><?php esc_html_e( 'String translations:', 'codeon-multilingual' ); ?> <?php echo (int) $snapshot['tables']['string_translations']; ?></li>
						</ul>
					</td>
				</tr>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:18px">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_EXPORT ); ?>">
				<?php wp_nonce_field( self::NONCE_EXPORT ); ?>
				<button type="submit" class="button">
					<?php esc_html_e( 'Download CodeOn snapshot', 'codeon-multilingual' ); ?>
				</button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-bottom:24px">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESTORE ); ?>">
				<?php wp_nonce_field( self::NONCE_RESTORE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cml_snapshot_file"><?php esc_html_e( 'Restore snapshot', 'codeon-multilingual' ); ?></label></th>
						<td>
							<input type="file" id="cml_snapshot_file" name="cml_snapshot_file" accept="application/json,.json">
							<p class="description"><?php esc_html_e( 'Restoring replaces current CodeOn languages, translation mappings, and string tables with the snapshot contents.', 'codeon-multilingual' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Safety checks', 'codeon-multilingual' ); ?></th>
						<td>
							<p>
								<label>
									<input type="checkbox" name="cml_confirm_restore" value="1">
									<?php esc_html_e( 'I understand this replaces current CodeOn migration data.', 'codeon-multilingual' ); ?>
								</label>
							</p>
							<p>
								<label for="cml_restore_phrase">
									<?php esc_html_e( 'Type RESTORE to confirm:', 'codeon-multilingual' ); ?>
								</label>
								<input type="text" id="cml_restore_phrase" name="cml_restore_phrase" value="" autocomplete="off" style="width:110px">
							</p>
							<p>
								<label>
									<input type="checkbox" name="cml_allow_site_mismatch" value="1">
									<?php esc_html_e( 'Allow snapshot from a different site URL.', 'codeon-multilingual' ); ?>
								</label>
							</p>
							<p>
								<label>
									<input type="checkbox" name="cml_allow_prefix_mismatch" value="1">
									<?php esc_html_e( 'Allow snapshot from a different database table prefix.', 'codeon-multilingual' ); ?>
								</label>
							</p>
							<button type="submit" class="button button-secondary">
								<?php esc_html_e( 'Restore CodeOn snapshot', 'codeon-multilingual' ); ?>
							</button>
						</td>
					</tr>
				</table>
			</form>

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
									<li><?php esc_html_e( 'Language settings:', 'codeon-multilingual' ); ?> <?php echo (int) $conflicts['language_settings']; ?></li>
									<li><?php esc_html_e( 'Post mappings:', 'codeon-multilingual' ); ?> <?php echo (int) $conflicts['post_mappings']; ?></li>
									<li><?php esc_html_e( 'Term mappings:', 'codeon-multilingual' ); ?> <?php echo (int) $conflicts['term_mappings']; ?></li>
									<li><?php esc_html_e( 'String translations:', 'codeon-multilingual' ); ?> <?php echo (int) $conflicts['string_translations']; ?></li>
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

			<?php self::render_polylang_section(); ?>
		</div>
		<?php
	}

	private static function render_polylang_section(): void {
		$available = PolylangImporter::is_available();
		$summary   = PolylangImporter::summary();
		$conflicts = $summary['conflicts'];

		$has_conflicts = $conflicts['language_settings'] > 0
			|| $conflicts['post_mappings'] > 0
			|| $conflicts['term_mappings'] > 0
			|| $conflicts['string_translations'] > 0;
		?>
		<hr>
		<h2><?php esc_html_e( 'Polylang', 'codeon-multilingual' ); ?></h2>

		<?php if ( ! $available ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Polylang language rows not detected. Nothing to migrate.', 'codeon-multilingual' ); ?></p>
			</div>
		<?php else : ?>

		<p class="description">
			<?php esc_html_e( 'CodeOn found Polylang taxonomy data. The importer copies Polylang languages, post and term translation relationships, and supported dynamic string translations into CodeOn. Polylang source data is left untouched.', 'codeon-multilingual' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Detected default language', 'codeon-multilingual' ); ?></th>
				<td><code><?php echo esc_html( (string) ( $summary['default_language'] ?? '—' ) ); ?></code></td>
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
						<li><?php esc_html_e( 'Language settings:', 'codeon-multilingual' ); ?> <?php echo (int) $conflicts['language_settings']; ?></li>
						<li><?php esc_html_e( 'Post mappings:', 'codeon-multilingual' ); ?> <?php echo (int) $conflicts['post_mappings']; ?></li>
						<li><?php esc_html_e( 'Term mappings:', 'codeon-multilingual' ); ?> <?php echo (int) $conflicts['term_mappings']; ?></li>
						<li><?php esc_html_e( 'String translations:', 'codeon-multilingual' ); ?> <?php echo (int) $conflicts['string_translations']; ?></li>
					</ul>
				</td>
			</tr>
		</table>

			<?php foreach ( $summary['warnings'] as $warning ) : ?>
				<div class="notice notice-warning inline">
					<p><?php echo esc_html( $warning ); ?></p>
				</div>
			<?php endforeach; ?>

			<?php if ( $has_conflicts ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Existing CodeOn data conflicts with Polylang data. The admin importer is blocked to avoid overwriting live language settings or mappings. Resolve conflicts manually or use WP-CLI with an explicit conflict policy.', 'codeon-multilingual' ); ?></p>
				</div>
			<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IMPORT_POLYLANG ); ?>">
			<?php wp_nonce_field( self::NONCE_IMPORT_POLYLANG ); ?>
			<p>
				<label>
					<input type="checkbox" name="cml_confirm_backup" value="1" required <?php disabled( $has_conflicts ); ?>>
					<?php esc_html_e( 'I have a recent database backup and understand this import writes to CodeOn tables.', 'codeon-multilingual' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" class="button button-primary" <?php disabled( $has_conflicts ); ?>>
					<?php esc_html_e( 'Import from Polylang', 'codeon-multilingual' ); ?>
				</button>
			</p>
		</form>
		<?php endif; ?>
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

		$snapshot = self::create_admin_import_snapshot( 'wpml' );
		if ( empty( $snapshot['ok'] ) ) {
			self::redirect_with( array( 'error' => 'snapshot_export_failed' ) );
		}

		$result = WpmlImporter::import_all();

		self::redirect_with(
			array(
				'imported'   => '1',
				'snapshot'   => '1',
				'ssource'    => (string) $snapshot['source'],
				'sfile'      => (string) $snapshot['file'],
				'srows'      => (int) $snapshot['rows'],
				'stime'      => (string) $snapshot['generated_at'],
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

	public static function handle_polylang_import(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_IMPORT_POLYLANG );

		if ( ! PolylangImporter::is_available() ) {
			self::redirect_with( array( 'error' => 'no_polylang' ) );
		}
		if ( empty( $_POST['cml_confirm_backup'] ) ) {
			self::redirect_with( array( 'error' => 'backup_required' ) );
		}

		$snapshot = self::create_admin_import_snapshot( 'polylang' );
		if ( empty( $snapshot['ok'] ) ) {
			self::redirect_with( array( 'error' => 'snapshot_export_failed' ) );
		}

		$result = PolylangImporter::import_all();

		self::redirect_with(
			array(
				'polylang_imported' => '1',
				'snapshot'          => '1',
				'ssource'           => (string) $snapshot['source'],
				'sfile'             => (string) $snapshot['file'],
				'srows'             => (int) $snapshot['rows'],
				'stime'             => (string) $snapshot['generated_at'],
				'planguages'        => (int) $result['languages'],
				'pposts'            => (int) $result['posts'],
				'pterms'            => (int) $result['terms'],
				'pstrings'          => (int) $result['strings'],
				'ptstrings'         => (int) $result['translated_strings'],
				'plconflicts'       => (int) $result['conflicts']['language_settings'],
				'ppconflicts'       => (int) $result['conflicts']['post_mappings'],
				'ptconflicts'       => (int) $result['conflicts']['term_mappings'],
				'psconflicts'       => (int) $result['conflicts']['string_translations'],
				'perrors'           => empty( $result['errors'] ) ? '' : implode( '||', $result['errors'] ),
				'pwarnings'         => empty( $result['warnings'] ) ? '' : implode( '||', $result['warnings'] ),
			)
		);
	}

	public static function handle_export(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_EXPORT );

		try {
			$json = WpmlMigrationSnapshot::to_json( 'manual' );
		} catch ( \Throwable $_e ) {
			self::redirect_with( array( 'error' => 'snapshot_export_failed' ) );
			return;
		}

		$filename = 'codeon-migration-snapshot-' . gmdate( 'Ymd-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * @return array{ok:bool,source:string,file:string,path:string,rows:int,generated_at:string,error:string}
	 */
	public static function create_admin_import_snapshot( string $source ): array {
		$source = self::normalize_snapshot_source( $source );

		try {
			$json     = WpmlMigrationSnapshot::to_json( $source );
			$decoded  = json_decode( $json, true );
			$rows     = is_array( $decoded ) ? (int) ( $decoded['report']['total_rows'] ?? 0 ) : 0;
			$generated_at = is_array( $decoded ) ? (string) ( $decoded['generated_at'] ?? gmdate( 'c' ) ) : gmdate( 'c' );
		} catch ( \Throwable $e ) {
			return self::snapshot_result( false, $source, '', '', 0, gmdate( 'c' ), $e->getMessage() );
		}

		$directory = self::admin_snapshot_directory();
		if ( '' === $directory || ! self::ensure_snapshot_directory( $directory ) ) {
			return self::snapshot_result( false, $source, '', '', $rows, $generated_at, 'Snapshot directory is not writable.' );
		}

		$file = sprintf(
			'codeon-before-%s-%s-%s.json',
			$source,
			gmdate( 'Ymd-His' ),
			self::snapshot_token()
		);
		$path = trailingslashit( $directory ) . $file;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Snapshot export must create a user-restorable JSON artifact.
		if ( false === file_put_contents( $path, $json, LOCK_EX ) ) {
			return self::snapshot_result( false, $source, $file, $path, $rows, $generated_at, 'Could not write snapshot file.' );
		}

		return self::snapshot_result( true, $source, $file, $path, $rows, $generated_at, '' );
	}

	private static function normalize_snapshot_source( string $source ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			$source = sanitize_key( $source );
		} else {
			$source = strtolower( trim( $source ) );
			$source = (string) preg_replace( '/[^a-z0-9_-]/', '', $source );
		}

		return '' !== $source ? $source : 'manual';
	}

	private static function admin_snapshot_directory(): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}

		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return rtrim( (string) $uploads['basedir'], '/\\' ) . '/codeon-multilingual/snapshots';
	}

	private static function ensure_snapshot_directory( string $directory ): bool {
		if ( ! is_dir( $directory ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				if ( ! wp_mkdir_p( $directory ) ) {
					return false;
				}
			} elseif ( ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
				return false;
			}
		}

		if ( ! is_writable( $directory ) ) {
			return false;
		}

		self::write_snapshot_guard_file( $directory . '/index.html', '' );
		self::write_snapshot_guard_file( $directory . '/.htaccess', "Deny from all\n" );
		self::write_snapshot_guard_file( $directory . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></system.webServer></configuration>\n" );

		return true;
	}

	private static function write_snapshot_guard_file( string $path, string $contents ): void {
		if ( file_exists( $path ) ) {
			return;
		}

		@file_put_contents( $path, $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	private static function snapshot_token(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		}

		return substr( md5( uniqid( 'cml', true ) ), 0, 12 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5
	}

	/**
	 * @return array{ok:bool,source:string,file:string,path:string,rows:int,generated_at:string,error:string}
	 */
	private static function snapshot_result( bool $ok, string $source, string $file, string $path, int $rows, string $generated_at, string $error ): array {
		return array(
			'ok'           => $ok,
			'source'       => $source,
			'file'         => $file,
			'path'         => $path,
			'rows'         => $rows,
			'generated_at' => $generated_at,
			'error'        => $error,
		);
	}

	public static function handle_restore(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		check_admin_referer( self::NONCE_RESTORE );

		$phrase = isset( $_POST['cml_restore_phrase'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['cml_restore_phrase'] ) )
			: '';
		if ( empty( $_POST['cml_confirm_restore'] ) || 'RESTORE' !== $phrase ) {
			self::redirect_with( array( 'error' => 'restore_confirm_required' ) );
		}

			$file = null;
		if ( isset( $_FILES['cml_snapshot_file'] ) && is_array( $_FILES['cml_snapshot_file'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File-array values are validated and sanitized into scalar fields below.
			$file = wp_unslash( $_FILES['cml_snapshot_file'] );
		}
		if ( ! is_array( $file ) || is_array( $file['error'] ?? null ) || is_array( $file['tmp_name'] ?? null ) ) {
			self::redirect_with( array( 'error' => 'snapshot_required' ) );
		}
			$tmp_name = isset( $file['tmp_name'] ) ? sanitize_text_field( (string) $file['tmp_name'] ) : '';
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp_name ) {
			self::redirect_with( array( 'error' => 'snapshot_required' ) );
		}

			$json = (string) @file_get_contents( $tmp_name );
		if ( '' === $json ) {
			self::redirect_with( array( 'error' => 'snapshot_required' ) );
		}

		$result = WpmlMigrationSnapshot::restore_from_json(
			$json,
			array(
				'confirm_destructive'   => true,
				'allow_site_mismatch'   => ! empty( $_POST['cml_allow_site_mismatch'] ),
				'allow_prefix_mismatch' => ! empty( $_POST['cml_allow_prefix_mismatch'] ),
			)
		);

		self::redirect_with(
			array(
				'restored' => $result['restored'] ? '1' : '0',
				'rows'     => array_sum( $result['counts'] ),
				'errors'   => empty( $result['errors'] ) ? '' : implode( '||', $result['errors'] ),
				'warnings' => empty( $result['warnings'] ) ? '' : implode( '||', $result['warnings'] ),
			)
		);
	}

	private static function render_notices(): void {
		if ( isset( $_GET['imported'] ) ) {
			$summary = sprintf(
				/* translators: 1: languages, 2: posts, 3: terms, 4: strings, 5: translated strings */
				esc_html__( 'Import complete — languages: %1$d, post translations: %2$d, term translations: %3$d, string sources: %4$d, string translations: %5$d.', 'codeon-multilingual' ),
				self::get_int_arg( 'languages' ),
				self::get_int_arg( 'posts' ),
				self::get_int_arg( 'terms' ),
				self::get_int_arg( 'strings' ),
				self::get_int_arg( 'tstrings' )
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $summary ) . '</p></div>';
			self::render_snapshot_created_notice();

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
			$conflict_total = self::get_int_arg( 'lconflicts' )
				+ self::get_int_arg( 'pconflicts' )
				+ self::get_int_arg( 'tconflicts' )
				+ self::get_int_arg( 'sconflicts' );
			if ( $conflict_total > 0 ) {
				echo '<div class="notice notice-warning is-dismissible"><p>'
					. esc_html__( 'Preflight detected existing CodeOn rows that disagree with WPML data. Conflicting rows were not overwritten.', 'codeon-multilingual' )
					. '</p></div>';
			}
		}
		if ( isset( $_GET['restored'] ) ) {
			$restored     = '1' === self::get_string_arg( 'restored' );
			$rows         = self::get_int_arg( 'rows' );
			$notice_class = $restored ? 'notice-success' : 'notice-error';
			$message      = $restored
				? sprintf(
					/* translators: %d: restored row count */
					__( 'CodeOn migration snapshot restored (%d rows).', 'codeon-multilingual' ),
					$rows
				)
				: __( 'CodeOn migration snapshot was not restored.', 'codeon-multilingual' );
			echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';

			$warnings_raw = isset( $_GET['warnings'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['warnings'] ) ) : '';
			if ( '' !== $warnings_raw ) {
				echo '<div class="notice notice-warning is-dismissible"><p><strong>'
					. esc_html__( 'Restore warnings:', 'codeon-multilingual' )
					. '</strong></p><ul style="list-style:disc;margin-left:24px">';
				foreach ( explode( '||', $warnings_raw ) as $warning ) {
					echo '<li><code>' . esc_html( $warning ) . '</code></li>';
				}
				echo '</ul></div>';
			}

			$errors_raw = isset( $_GET['errors'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['errors'] ) ) : '';
			if ( '' !== $errors_raw ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>'
					. esc_html__( 'Restore errors:', 'codeon-multilingual' )
					. '</strong></p><ul style="list-style:disc;margin-left:24px">';
				foreach ( explode( '||', $errors_raw ) as $error ) {
					echo '<li><code>' . esc_html( $error ) . '</code></li>';
				}
				echo '</ul></div>';
			}
		}
		if ( isset( $_GET['polylang_imported'] ) ) {
			$summary = sprintf(
				/* translators: 1: languages, 2: posts, 3: terms, 4: source strings, 5: translated strings */
				esc_html__( 'Polylang import complete — languages: %1$d, post translations: %2$d, term translations: %3$d, string sources: %4$d, translated strings: %5$d.', 'codeon-multilingual' ),
				self::get_int_arg( 'planguages' ),
				self::get_int_arg( 'pposts' ),
				self::get_int_arg( 'pterms' ),
				self::get_int_arg( 'pstrings' ),
				self::get_int_arg( 'ptstrings' )
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $summary ) . '</p></div>';
			self::render_snapshot_created_notice();

			$warnings_raw = isset( $_GET['pwarnings'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['pwarnings'] ) ) : '';
			if ( '' !== $warnings_raw ) {
				echo '<div class="notice notice-warning is-dismissible"><p><strong>'
					. esc_html__( 'Polylang import warnings:', 'codeon-multilingual' )
					. '</strong></p><ul style="list-style:disc;margin-left:24px">';
				foreach ( explode( '||', $warnings_raw ) as $warning ) {
					echo '<li><code>' . esc_html( $warning ) . '</code></li>';
				}
				echo '</ul></div>';
			}

			$errors_raw = isset( $_GET['perrors'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['perrors'] ) ) : '';
			if ( '' !== $errors_raw ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>'
					. esc_html__( 'Polylang import errors:', 'codeon-multilingual' )
					. '</strong></p><ul style="list-style:disc;margin-left:24px">';
				foreach ( explode( '||', $errors_raw ) as $error ) {
					echo '<li><code>' . esc_html( $error ) . '</code></li>';
				}
				echo '</ul></div>';
			}
		}
		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_key( wp_unslash( (string) $_GET['error'] ) );
			$msg   = match ( $error ) {
				'no_wpml' => __( 'WPML tables not detected. Cannot import.', 'codeon-multilingual' ),
				'no_polylang' => __( 'Polylang language rows not detected. Cannot import.', 'codeon-multilingual' ),
				'backup_required' => __( 'Confirm that you have a recent database backup before running the import.', 'codeon-multilingual' ),
				'snapshot_export_failed' => __( 'Could not create the CodeOn migration snapshot.', 'codeon-multilingual' ),
				'snapshot_required' => __( 'Choose a CodeOn migration snapshot JSON file to restore.', 'codeon-multilingual' ),
				'restore_confirm_required' => __( 'Confirm the restore checkbox and type RESTORE before replacing CodeOn migration data.', 'codeon-multilingual' ),
				default   => __( 'Import failed.', 'codeon-multilingual' ),
			};
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	private static function render_snapshot_created_notice(): void {
		if ( '1' !== self::get_string_arg( 'snapshot' ) ) {
			return;
		}

		$file   = self::get_string_arg( 'sfile' );
		$source = self::get_string_arg( 'ssource' );
		$rows   = self::get_int_arg( 'srows' );
		$time   = self::get_string_arg( 'stime' );

		$message = sprintf(
			/* translators: 1: source plugin, 2: row count, 3: generated timestamp, 4: snapshot file name */
			__( 'Rollback snapshot created before %1$s import (%2$d rows, %3$s): %4$s', 'codeon-multilingual' ),
			strtoupper( $source ),
			$rows,
			$time,
			$file
		);
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * @param array<string, mixed> $args
	 */
	private static function redirect_with( array $args ): void {
		$args = array_merge( array( 'page' => self::PAGE_SLUG ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function get_int_arg( string $key ): int {
		return isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
	}

	private static function get_string_arg( string $key ): string {
		return isset( $_GET[ $key ] )
			? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) )
			: '';
	}
}
