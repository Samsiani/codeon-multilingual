<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin\Pages;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Content\MenuTranslator;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;

/**
 * Multilingual → Menus admin page.
 *
 * Lists every `nav_menu` term in one table with: name, source language,
 * per-language sync state + action. "Sync to <LANG>" creates the
 * translation menu when missing; "Re-sync" wipes the existing target and
 * re-clones from source so newly-translated posts/terms show up.
 *
 * Source-of-truth detection: a menu is treated as source when its
 * `cml_term_language` row's `group_id` matches its own `term_id`
 * (Backfill convention). All other rows in the same group are
 * translations of that source.
 *
 * Performance: one bulk `wp_get_nav_menus()` call, then per-row group
 * lookups go through TranslationGroups which has its own request-static
 * cache. No N+1 DB hits.
 */
final class MenusPage {

	public const PAGE_SLUG = 'cml-menus';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}

		$menus            = wp_get_nav_menus();
		$active_languages = Languages::active();
		$default_lang     = Languages::default_code();

		// Group rows: each `group_id` collects its source + translations.
		// Menus with NO language row attach to their own term id as a
		// pseudo-group (= "untracked, treated as source in default lang").
		$groups = self::group_menus( is_array( $menus ) ? $menus : array() );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Menus', 'codeon-multilingual' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'nav-menus.php' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Edit menus', 'codeon-multilingual' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php self::render_notices(); ?>

			<p class="description">
				<?php esc_html_e( 'Each menu is listed below with its source language. Click "Sync" to create or refresh a per-language copy — pages and taxonomy terms that have a translation are rewired to that translation; untranslated items copy the source.', 'codeon-multilingual' ); ?>
			</p>

			<?php if ( count( $active_languages ) <= 1 ) : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'Only one language is configured. Add more languages on the Languages page to enable menu sync.', 'codeon-multilingual' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Menu', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Source language', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Items', 'codeon-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Translations', 'codeon-multilingual' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $groups ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No menus found. Create one on the Edit menus screen.', 'codeon-multilingual' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $groups as $group ) : ?>
							<?php self::render_row( $group, $active_languages, $default_lang ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param array<int, \WP_Term> $menus
	 * @return array<int, array{source:\WP_Term, source_lang:string, translations:array<string, \WP_Term>}>
	 */
	private static function group_menus( array $menus ): array {
		$by_group = array();
		foreach ( $menus as $m ) {
			if ( ! $m instanceof \WP_Term ) {
				continue;
			}
			$lang    = TranslationGroups::get_term_language( (int) $m->term_id );
			$group   = TranslationGroups::get_term_group_id( (int) $m->term_id );
			$is_src  = null === $group || $group === (int) $m->term_id;
			$gid_key = $is_src ? (int) $m->term_id : (int) $group;

			if ( ! isset( $by_group[ $gid_key ] ) ) {
				$by_group[ $gid_key ] = array(
					'source'       => $is_src ? $m : null,
					'source_lang'  => $is_src ? ( $lang ?? Languages::default_code() ) : '',
					'translations' => array(),
				);
			}
			if ( $is_src ) {
				$by_group[ $gid_key ]['source']      = $m;
				$by_group[ $gid_key ]['source_lang'] = $lang ?? Languages::default_code();
			} elseif ( null !== $lang ) {
				$by_group[ $gid_key ]['translations'][ $lang ] = $m;
			}
		}
		// Discard rows where source is still null (orphan translation — rare).
		return array_values( array_filter( $by_group, static fn( $g ) => isset( $g['source'] ) && $g['source'] instanceof \WP_Term ) );
	}

	/**
	 * @param array{source:\WP_Term, source_lang:string, translations:array<string, \WP_Term>} $group
	 * @param array<string, object>                                                            $active_languages
	 */
	private static function render_row( array $group, array $active_languages, string $default_lang ): void {
		$source        = $group['source'];
		$source_lang   = (string) $group['source_lang'];
		$source_native = self::lang_native( $source_lang );

		$count_query = wp_get_nav_menu_items( $source->term_id, array( 'update_post_term_cache' => false ) );
		$item_count  = is_array( $count_query ) ? count( $count_query ) : 0;

		$edit_url = add_query_arg(
			array( 'action' => 'edit', 'menu' => (int) $source->term_id ),
			admin_url( 'nav-menus.php' )
		);
		?>
		<tr>
			<td>
				<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $source->name ); ?></a></strong>
				<div class="row-actions">
					<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'codeon-multilingual' ); ?></a></span>
				</div>
			</td>
			<td>
				<code><?php echo esc_html( $source_lang ); ?></code>
				<?php if ( $source_native ) : ?>
					<span style="opacity:0.7">(<?php echo esc_html( $source_native ); ?>)</span>
				<?php endif; ?>
				<?php if ( $source_lang === $default_lang ) : ?>
					<span class="dashicons dashicons-star-filled" style="font-size:14px;color:#dba617;vertical-align:-2px" title="<?php esc_attr_e( 'Site default', 'codeon-multilingual' ); ?>"></span>
				<?php endif; ?>
			</td>
			<td><?php echo (int) $item_count; ?></td>
			<td>
				<?php foreach ( $active_languages as $code => $lang ) : ?>
					<?php
					if ( $code === $source_lang ) {
						continue;
					}
					$sibling    = $group['translations'][ $code ] ?? null;
					$code_upper = strtoupper( (string) $code );
					?>
					<div style="margin-bottom:4px;line-height:1.5">
						<strong style="display:inline-block;min-width:42px;font-family:monospace"><?php echo esc_html( $code_upper ); ?></strong>
						<?php if ( $sibling instanceof \WP_Term ) : ?>
							<span class="dashicons dashicons-yes-alt" style="color:#46b450;font-size:16px;vertical-align:-3px"></span>
							<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'edit', 'menu' => (int) $sibling->term_id ), admin_url( 'nav-menus.php' ) ) ); ?>">
								<?php echo esc_html( $sibling->name ); ?>
							</a>
							&nbsp;·&nbsp;
							<a href="<?php echo esc_url( MenuTranslator::sync_url( (int) $source->term_id, (string) $code ) ); ?>"
							   onclick="return confirm('<?php echo esc_js( __( 'Re-sync will wipe the translated menu\'s items and re-clone from source. Custom edits to that menu will be lost. Continue?', 'codeon-multilingual' ) ); ?>');">
								<?php esc_html_e( 'Re-sync', 'codeon-multilingual' ); ?>
							</a>
						<?php else : ?>
							<span class="dashicons dashicons-plus-alt" style="color:#2271b1;font-size:16px;vertical-align:-3px"></span>
							<a href="<?php echo esc_url( MenuTranslator::sync_url( (int) $source->term_id, (string) $code ) ); ?>" class="button button-small">
								<?php
								/* translators: %s: target language code uppercase */
								printf( esc_html__( 'Sync to %s', 'codeon-multilingual' ), esc_html( $code_upper ) );
								?>
							</a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
	}

	private static function lang_native( string $code ): string {
		$lang = Languages::get( $code );
		return $lang ? (string) $lang->native : '';
	}

	private static function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended — read-only flash.
		if ( isset( $_GET['cml_synced'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Menu synced.', 'codeon-multilingual' ) . '</p></div>';
		}
	}
}
