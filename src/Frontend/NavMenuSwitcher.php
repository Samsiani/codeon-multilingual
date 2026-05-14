<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\LanguageCatalog;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Url\Router;
use WP_Post;

/**
 * Drop-in nav-menu item that expands into one link per active language
 * at render time. Mirrors the WPML "Languages" menu pattern with per-item
 * customization (display + layout).
 *
 * Admin workflow:
 *   - Appearance → Menus, side meta-box "Languages"
 *   - One-click "Add to menu" inserts a single placeholder item marked with
 *     `object = 'cml_language_switcher'` (type = 'custom')
 *   - Expand the placeholder in the menu editor and choose:
 *       • Display: names / flags / names + flags
 *       • Layout:  inline (flat) / dropdown (current-language wrapper + children)
 *
 * Frontend workflow:
 *   - `wp_get_nav_menu_items` filter picks up the placeholder and replaces
 *     it (inline) OR wraps it (dropdown) with one item per active language.
 *     Current language gets a `current-menu-item` class.
 *
 * Why placeholder-expansion vs N admin-inserted items: when a new language
 * is added later, expansion picks it up automatically. With N separate items
 * the admin would need to re-edit every menu after enabling a new language.
 */
final class NavMenuSwitcher {

	/** Marker on the placeholder so the render filter recognises it. */
	public const PLACEHOLDER_OBJECT = 'cml_language_switcher';

	/** Postmeta keys on the nav_menu_item post. */
	private const META_DISPLAY = '_cml_switcher_display';
	private const META_LAYOUT  = '_cml_switcher_layout';

	/** Allowed values. */
	private const DISPLAY_MODES = array( 'names', 'flags', 'both' );
	private const LAYOUTS       = array( 'inline', 'dropdown' );

	/** Defaults applied when the placeholder hasn't been customised yet. */
	private const DEFAULT_DISPLAY = 'both';
	private const DEFAULT_LAYOUT  = 'inline';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_init', array( self::class, 'register_meta_box' ) );

		// Per-item customization in the menu editor.
		add_action( 'wp_nav_menu_item_custom_fields', array( self::class, 'render_custom_fields' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( self::class, 'save_custom_fields' ), 10, 3 );

		// Frontend expansion.
		add_filter( 'wp_get_nav_menu_items', array( self::class, 'expand_placeholder' ), 10, 3 );

		// Make the placeholder render in the menu editor with a helpful label.
		add_filter( 'wp_setup_nav_menu_item', array( self::class, 'label_placeholder' ), 10, 1 );
	}

	// ---- Admin: meta-box + custom fields ------------------------------------

	public static function register_meta_box(): void {
		add_meta_box(
			'cml-language-switcher-menu',
			__( 'Languages', 'codeon-multilingual' ),
			array( self::class, 'render_meta_box' ),
			'nav-menus',
			'side',
			'low'
		);
	}

	public static function render_meta_box(): void {
		?>
		<div id="cml-language-switcher-meta-box" class="posttypediv">
			<div class="tabs-panel tabs-panel-active">
				<p class="description" style="margin: 6px 0 10px;">
					<?php esc_html_e( 'Adds a language switcher to your menu. At render time, the placeholder expands into one link per active language — adding a new language later updates every menu automatically.', 'codeon-multilingual' ); ?>
				</p>
				<ul class="categorychecklist form-no-clear">
					<li>
						<label class="menu-item-title">
							<input type="checkbox" class="menu-item-checkbox" name="menu-item[-1][menu-item-object-id]" value="-1" checked disabled>
							<?php esc_html_e( 'Language switcher (auto-expands)', 'codeon-multilingual' ); ?>
						</label>
						<input type="hidden" class="menu-item-type"     name="menu-item[-1][menu-item-type]"   value="custom">
						<input type="hidden" class="menu-item-object"   name="menu-item[-1][menu-item-object]" value="<?php echo esc_attr( self::PLACEHOLDER_OBJECT ); ?>">
						<input type="hidden" class="menu-item-title"    name="menu-item[-1][menu-item-title]" value="<?php echo esc_attr__( 'Languages', 'codeon-multilingual' ); ?>">
						<input type="hidden" class="menu-item-url"      name="menu-item[-1][menu-item-url]"   value="#cml-languages">
					</li>
				</ul>
			</div>
			<p class="button-controls wp-clearfix">
				<span class="add-to-menu">
					<input
						type="submit"
						class="button submit-add-to-menu right"
						value="<?php esc_attr_e( 'Add to menu', 'codeon-multilingual' ); ?>"
						name="add-cml-languages-menu-item"
						id="submit-cml-language-switcher-meta-box">
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Per-item editor fields (fires inside each menu item panel).
	 *
	 * @param int    $item_id current menu item ID
	 * @param object $item    full menu item
	 */
	public static function render_custom_fields( $item_id, $item = null ): void {
		if ( ! is_object( $item ) || self::PLACEHOLDER_OBJECT !== ( $item->object ?? '' ) ) {
			return;
		}
		$display = self::get_display_mode( (int) $item_id );
		$layout  = self::get_layout( (int) $item_id );
		?>
		<p class="description description-wide cml-switcher-display">
			<label for="cml-display-<?php echo (int) $item_id; ?>">
				<strong><?php esc_html_e( 'Display', 'codeon-multilingual' ); ?></strong>
			</label><br>
			<select id="cml-display-<?php echo (int) $item_id; ?>" name="menu-item-cml-display[<?php echo (int) $item_id; ?>]">
				<option value="names" <?php selected( $display, 'names' ); ?>><?php esc_html_e( 'Language names', 'codeon-multilingual' ); ?></option>
				<option value="flags" <?php selected( $display, 'flags' ); ?>><?php esc_html_e( 'Flags only', 'codeon-multilingual' ); ?></option>
				<option value="both"  <?php selected( $display, 'both' ); ?>><?php esc_html_e( 'Flags + language names', 'codeon-multilingual' ); ?></option>
			</select>
		</p>
		<p class="description description-wide cml-switcher-layout">
			<label for="cml-layout-<?php echo (int) $item_id; ?>">
				<strong><?php esc_html_e( 'Layout', 'codeon-multilingual' ); ?></strong>
			</label><br>
			<select id="cml-layout-<?php echo (int) $item_id; ?>" name="menu-item-cml-layout[<?php echo (int) $item_id; ?>]">
				<option value="inline"   <?php selected( $layout, 'inline' ); ?>><?php esc_html_e( 'Inline (one item per language)', 'codeon-multilingual' ); ?></option>
				<option value="dropdown" <?php selected( $layout, 'dropdown' ); ?>><?php esc_html_e( 'Dropdown (current language opens submenu)', 'codeon-multilingual' ); ?></option>
			</select>
		</p>
		<?php
	}

	/**
	 * Persist the custom-field values when the menu is saved.
	 *
	 * @param int   $menu_id
	 * @param int   $menu_item_db_id
	 * @param array<string, mixed> $args
	 */
	public static function save_custom_fields( $menu_id, $menu_item_db_id, $args = array() ): void {
		unset( $menu_id, $args );
		$id = (int) $menu_item_db_id;
		if ( $id <= 0 ) {
			return;
		}
		if ( self::PLACEHOLDER_OBJECT !== (string) get_post_meta( $id, '_menu_item_object', true ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing — menu save is nonced upstream by wp-admin/nav-menus.php.
		$display_raw = isset( $_POST['menu-item-cml-display'][ $id ] ) ? sanitize_key( (string) $_POST['menu-item-cml-display'][ $id ] ) : '';
		$layout_raw  = isset( $_POST['menu-item-cml-layout'][ $id ] )  ? sanitize_key( (string) $_POST['menu-item-cml-layout'][ $id ] )  : '';
		// phpcs:enable

		$display = in_array( $display_raw, self::DISPLAY_MODES, true ) ? $display_raw : self::DEFAULT_DISPLAY;
		$layout  = in_array( $layout_raw, self::LAYOUTS, true )        ? $layout_raw  : self::DEFAULT_LAYOUT;

		update_post_meta( $id, self::META_DISPLAY, $display );
		update_post_meta( $id, self::META_LAYOUT, $layout );
	}

	public static function get_display_mode( int $item_id ): string {
		$value = (string) get_post_meta( $item_id, self::META_DISPLAY, true );
		return in_array( $value, self::DISPLAY_MODES, true ) ? $value : self::DEFAULT_DISPLAY;
	}

	public static function get_layout( int $item_id ): string {
		$value = (string) get_post_meta( $item_id, self::META_LAYOUT, true );
		return in_array( $value, self::LAYOUTS, true ) ? $value : self::DEFAULT_LAYOUT;
	}

	// ---- Admin: placeholder labelling --------------------------------------

	/**
	 * @param WP_Post|object $menu_item
	 * @return WP_Post|object
	 */
	public static function label_placeholder( $menu_item ) {
		if ( ! is_object( $menu_item ) ) {
			return $menu_item;
		}
		if ( self::PLACEHOLDER_OBJECT !== ( $menu_item->object ?? '' ) ) {
			return $menu_item;
		}
		$menu_item->type_label = __( 'Language switcher', 'codeon-multilingual' );
		return $menu_item;
	}

	// ---- Frontend: placeholder expansion ------------------------------------

	/**
	 * @param array<int, WP_Post>   $items
	 * @param object                $menu
	 * @param array<string, mixed>  $args
	 * @return array<int, WP_Post>
	 */
	public static function expand_placeholder( $items, $menu = null, $args = array() ) {
		unset( $menu, $args );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return $items;
		}
		// Skip in admin so the menu editor shows the placeholder, not the expansion.
		if ( is_admin() ) {
			return $items;
		}

		$active_languages = Languages::active();
		if ( empty( $active_languages ) ) {
			// No languages configured — drop placeholders silently.
			return array_values(
				array_filter(
					$items,
					static fn( $i ) => self::PLACEHOLDER_OBJECT !== ( is_object( $i ) ? ( $i->object ?? '' ) : '' )
				)
			);
		}

		$current_code = CurrentLanguage::code();
		$expanded     = array();
		$position     = 0;

		foreach ( $items as $item ) {
			++$position;
			if ( ! is_object( $item ) || self::PLACEHOLDER_OBJECT !== ( $item->object ?? '' ) ) {
				$item->menu_order = $position;
				$expanded[]       = $item;
				continue;
			}

			$db_id          = isset( $item->db_id ) ? (int) $item->db_id : 0;
			$placeholder_id = $db_id > 0 ? $db_id : (int) ( property_exists( $item, 'ID' ) ? $item->ID : 0 );
			$display        = self::get_display_mode( $placeholder_id );
			$layout         = self::get_layout( $placeholder_id );

			if ( 'dropdown' === $layout ) {
				// Keep the placeholder as a visible parent showing the current
				// language; nest the other languages as children.
				$parent = self::synthesize_dropdown_parent( $item, $current_code, $active_languages, $display, $position );
				$expanded[]        = $parent;
				$parent_id         = (int) $parent->db_id;

				foreach ( $active_languages as $code => $lang ) {
					++$position;
					$expanded[] = self::synthesize_language_item( $item, $code, $lang, $current_code, $display, $position, $parent_id );
				}
			} else {
				// Inline: replace the placeholder with N flat items.
				$first = true;
				foreach ( $active_languages as $code => $lang ) {
					if ( ! $first ) {
						++$position;
					}
					$first      = false;
					$expanded[] = self::synthesize_language_item( $item, $code, $lang, $current_code, $display, $position, 0 );
				}
			}
		}

		return $expanded;
	}

	/**
	 * Wrapper item for dropdown layout — labelled with the current language.
	 *
	 * @param WP_Post|object                  $template
	 * @param array<string, object>           $active_languages keyed by code
	 */
	private static function synthesize_dropdown_parent( $template, string $current_code, array $active_languages, string $display, int $position ): object {
		$current_lang = $active_languages[ $current_code ] ?? reset( $active_languages );
		$item         = clone $template;
		// Synthetic ID — unique per request, used so children can declare
		// menu_item_parent against this wrapper.
		$item->ID            = 90000000 + (int) crc32( 'cml-parent-' . spl_object_hash( $template ) ) % 1000000;
		$item->db_id         = $item->ID;
		$item->menu_item_parent = 0;
		$item->object_id     = 0;
		$item->object        = self::PLACEHOLDER_OBJECT . '_parent';
		$item->type          = 'custom';
		$item->title         = self::build_label( $current_code, $current_lang, $display );
		$item->url           = '#cml-languages';
		$item->target        = '';
		$item->attr_title    = '';
		$item->description   = '';
		$item->xfn           = '';
		$item->menu_order    = $position;
		$item->classes       = array( 'menu-item', 'menu-item-type-custom', 'menu-item-has-children', 'cml-language-switcher', 'cml-style-dropdown' );

		return $item;
	}

	/**
	 * Build one synthetic menu item for the given language.
	 *
	 * @param WP_Post|object $template
	 * @param object         $lang     row from cml_languages
	 */
	private static function synthesize_language_item( $template, string $code, object $lang, string $current_code, string $display, int $position, int $parent_id ): object {
		$item                = clone $template;
		$item->ID            = 0;
		$item->db_id         = 0;
		$item->menu_item_parent = $parent_id;
		$item->object_id     = 0;
		$item->object        = self::PLACEHOLDER_OBJECT . '_item';
		$item->type          = 'custom';
		$item->type_label    = __( 'Language switcher', 'codeon-multilingual' );
		$item->title         = self::build_label( $code, $lang, $display );
		$item->url           = self::url_for_language( $code );
		$item->target        = '';
		$item->attr_title    = sprintf(
			/* translators: %s: language native name */
			__( 'Switch to %s', 'codeon-multilingual' ),
			(string) $lang->native
		);
		$item->description   = '';
		$item->xfn           = 'alternate';
		$item->menu_order    = $position;
		$item->classes       = self::class_list( $code, $current_code, $lang );

		$item->cml_language_code   = $code;
		$item->cml_language_native = (string) $lang->native;
		$item->cml_language_flag   = (string) ( $lang->flag ?? '' );

		return $item;
	}

	/**
	 * Compose the item's title text/HTML according to the display mode.
	 *
	 * Returns HTML when flags are involved (Walker_Nav_Menu preserves HTML in
	 * titles via apply_filters('the_title', ...) without escaping). Pure-name
	 * mode returns plain text.
	 */
	public static function build_label( string $code, object $lang, string $display ): string {
		$flag_code = (string) ( $lang->flag ?? '' );
		$native    = (string) ( $lang->native ?? $code );

		switch ( $display ) {
			case 'flags':
				$flag = self::flag_html( $flag_code, $native );
				return '<span class="cml-language-item-content cml-flag-only">' . $flag . '<span class="screen-reader-text">' . esc_html( $native ) . '</span></span>';

			case 'names':
				return esc_html( $native );

			case 'both':
			default:
				$flag = self::flag_html( $flag_code, $native );
				return '<span class="cml-language-item-content">' . $flag . '<span class="cml-language-item-name">' . esc_html( $native ) . '</span></span>';
		}
	}

	/**
	 * Render the flag — bundled SVG when available, emoji fallback otherwise,
	 * empty string when neither resolves (Esperanto / Yiddish have no country).
	 */
	private static function flag_html( string $country_code, string $alt ): string {
		if ( '' === $country_code ) {
			return '';
		}
		$svg_url = LanguageCatalog::flag_svg_url( $country_code );
		if ( null !== $svg_url ) {
			return '<img class="cml-flag cml-flag-svg" src="' . esc_url( $svg_url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy">';
		}
		$emoji = LanguageCatalog::flag_emoji( $country_code );
		return '' !== $emoji ? '<span class="cml-flag cml-flag-emoji" aria-hidden="true">' . esc_html( $emoji ) . '</span>' : '';
	}

	/**
	 * @return array<int, string>
	 */
	private static function class_list( string $code, string $current_code, object $lang ): array {
		$classes = array(
			'menu-item',
			'menu-item-type-custom',
			'cml-language-item',
			'cml-language-' . $code,
		);
		if ( $code === $current_code ) {
			$classes[] = 'current-menu-item';
			$classes[] = 'cml-current';
		}
		if ( (int) ( $lang->rtl ?? 0 ) === 1 ) {
			$classes[] = 'cml-rtl';
		}
		return $classes;
	}

	/**
	 * URL of the equivalent of the currently-queried object in the given
	 * language — falling back to that language's home URL.
	 */
	public static function url_for_language( string $code ): string {
		$queried_id = self::current_object_id();
		if ( $queried_id > 0 ) {
			$group_id = TranslationGroups::get_group_id( $queried_id );
			if ( null !== $group_id ) {
				$siblings   = TranslationGroups::get_siblings( $group_id );
				$sibling_id = (int) ( array_search( $code, $siblings, true ) ?: 0 );
				if ( $sibling_id > 0 ) {
					$link = get_permalink( $sibling_id );
					if ( $link ) {
						return (string) $link;
					}
				}
			}
		}
		$home = home_url( '/' );
		if ( Languages::is_default( $code ) ) {
			return $home;
		}
		return Router::with_lang( $home, $code );
	}

	private static function current_object_id(): int {
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}
		return 0;
	}
}
