<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Content;

use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use WP_Term;

/**
 * Menu translation flow.
 *
 * WordPress menus are terms in the `nav_menu` taxonomy. Each menu item is a
 * `nav_menu_item` post that references its target via three meta keys
 * (`_menu_item_type` + `_menu_item_object` + `_menu_item_object_id`). Term
 * translation alone is not enough — when an admin translates "Main menu"
 * into Russian they expect:
 *
 *   1. A new `nav_menu` term in Russian (siblings under the same group),
 *   2. Every item copied across with `object_id` rewritten to the translation
 *      of the post/term it points at (when one exists),
 *   3. Item hierarchy (`menu_item_parent`) rewired to use the new item IDs.
 *
 * `translate_menu()` is the single public entry point.
 *
 * Theme-location mapping is handled separately by the `theme_mod_nav_menu_locations`
 * filter so the front-end serves the language-matching menu at each registered
 * theme location automatically.
 */
final class MenuTranslator {

	public const ACTION_TRANSLATE = 'cml_translate_menu';
	public const NONCE_TRANSLATE  = 'cml_translate_menu';
	public const ACTION_SYNC      = 'cml_sync_menu';
	public const NONCE_SYNC       = 'cml_sync_menu';

	private static bool $registered = false;

	/** @var array<string, int> cache: 'location|lang' => translated_menu_id */
	private static array $location_cache = array();

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Admin: side meta-box on nav-menus.php + handler.
		add_action( 'admin_init', array( self::class, 'register_meta_box' ) );
		add_action( 'admin_post_' . self::ACTION_TRANSLATE, array( self::class, 'handle_translate' ) );
		add_action( 'admin_post_' . self::ACTION_SYNC,      array( self::class, 'handle_sync' ) );

		// Clean up the cml_term_language row when a nav_menu is deleted via
		// any path (Appearance → Menus delete, wp_delete_nav_menu(), etc.).
		// Without this, stale rows survive the term and confuse sibling
		// lookups — sync_menu() would think a long-dead translation still
		// exists and "re-sync" into a phantom term that has no items.
		add_action( 'delete_nav_menu', array( self::class, 'on_nav_menu_deleted' ), 10, 1 );

		// Frontend: swap each theme location's menu for the current-language sibling.
		add_filter( 'theme_mod_nav_menu_locations', array( self::class, 'filter_theme_locations' ) );
	}

	// ---- Public API ---------------------------------------------------------

	/**
	 * Clone a menu (term + items) into the target language. Idempotent: if
	 * the source menu already has a sibling in $target_lang, returns that
	 * sibling's ID without touching it.
	 *
	 * @return int new menu's term_id, or 0 on failure
	 */
	/**
	 * Create the target-language menu if it doesn't exist, then clone items.
	 * Idempotent: existing sibling short-circuits and returns its ID without
	 * re-cloning (use sync_menu() for that).
	 */
	public static function translate_menu( int $source_menu_id, string $target_lang ): int {
		if ( $source_menu_id <= 0 || '' === $target_lang ) {
			return 0;
		}
		if ( ! Languages::exists_and_active( $target_lang ) ) {
			return 0;
		}
		$source = get_term( $source_menu_id, 'nav_menu' );
		if ( ! ( $source instanceof WP_Term ) ) {
			return 0;
		}

		$group_id = TranslationGroups::get_term_group_id( $source_menu_id );
		if ( null === $group_id ) {
			$group_id = $source_menu_id;
		}
		$siblings = TranslationGroups::get_term_siblings( $group_id );
		$existing = (int) ( array_search( $target_lang, $siblings, true ) ?: 0 );
		if ( $existing > 0 ) {
			return $existing;
		}

		$target_menu_id = self::create_translated_menu_term( $source, $target_lang, $group_id );
		if ( $target_menu_id <= 0 ) {
			return 0;
		}

		self::clone_items( $source_menu_id, $target_menu_id, $target_lang );

		do_action( 'cml_menu_translation_created', $target_menu_id, $source_menu_id, $target_lang );
		return $target_menu_id;
	}

	/**
	 * Sync items from the source menu into the target-language menu.
	 * Creates the target if it doesn't exist, otherwise empties it and
	 * re-clones from the source so newly-translated posts/terms show up
	 * and removed source items disappear from the translation.
	 *
	 * Source title overrides are preserved; missing translations fall
	 * back to the original post/term (matches "if not translated, copy
	 * original" rule).
	 *
	 * @return int target menu id, or 0 on failure
	 */
	public static function sync_menu( int $source_menu_id, string $target_lang ): int {
		if ( $source_menu_id <= 0 || '' === $target_lang ) {
			return 0;
		}
		if ( ! Languages::exists_and_active( $target_lang ) ) {
			return 0;
		}
		$source = get_term( $source_menu_id, 'nav_menu' );
		if ( ! ( $source instanceof WP_Term ) ) {
			return 0;
		}

		$group_id = TranslationGroups::get_term_group_id( $source_menu_id );
		if ( null === $group_id ) {
			$group_id = $source_menu_id;
		}
		$siblings  = TranslationGroups::get_term_siblings( $group_id );
		$target_id = (int) ( array_search( $target_lang, $siblings, true ) ?: 0 );

		// Validate the candidate target term still exists. A previous delete
		// may have removed the term but left an orphan cml_term_language row
		// behind — in that case treat the target as missing and re-create.
		if ( $target_id > 0 && ! ( get_term( $target_id, 'nav_menu' ) instanceof WP_Term ) ) {
			self::orphan_language_row( $target_id );
			TranslationGroups::flush();
			$target_id = 0;
		}

		if ( $target_id <= 0 ) {
			$target_id = self::create_translated_menu_term( $source, $target_lang, $group_id );
			if ( $target_id <= 0 ) {
				return 0;
			}
		} else {
			// Existing target — wipe items so the sync is authoritative.
			\Samsiani\CodeonMultilingual\Frontend\NavMenuSwitcher::bypass_expansion( true );
			try {
				$existing_items = wp_get_nav_menu_items( $target_id, array( 'update_post_term_cache' => false ) );
			} finally {
				\Samsiani\CodeonMultilingual\Frontend\NavMenuSwitcher::bypass_expansion( false );
			}
			if ( is_array( $existing_items ) ) {
				foreach ( $existing_items as $item ) {
					wp_delete_post( (int) $item->db_id, true );
				}
			}
		}

		self::clone_items( $source_menu_id, $target_id, $target_lang );
		do_action( 'cml_menu_translation_synced', $target_id, $source_menu_id, $target_lang );
		return $target_id;
	}

	/**
	 * Create the nav_menu term for the translation. Adds the language label
	 * to the name so wp_insert_term's name-uniqueness check passes, then
	 * writes the cml_term_language row directly so TranslationGroups picks
	 * up the new sibling without going through TermTranslator's hooks.
	 */
	private static function create_translated_menu_term( WP_Term $source, string $target_lang, int $group_id ): int {
		$suffix    = strtoupper( $target_lang );
		$base_name = (string) $source->name;
		$candidate = $base_name . ' (' . $suffix . ')';

		// Disambiguate if a menu with that exact name already exists (e.g.
		// admin previously created one manually). get_term_by returns false
		// for no-match (NOT null), so guard against falsy explicitly.
		$counter = 2;
		while ( false !== get_term_by( 'name', $candidate, 'nav_menu' ) ) {
			$candidate = $base_name . ' (' . $suffix . ' ' . $counter . ')';
			++$counter;
			if ( $counter > 50 ) {
				return 0;
			}
		}

		$inserted = wp_insert_term(
			$candidate,
			'nav_menu',
			array( 'description' => $source->description )
		);
		if ( is_wp_error( $inserted ) || ! is_array( $inserted ) || empty( $inserted['term_id'] ) ) {
			return 0;
		}
		$new_term_id = (int) $inserted['term_id'];

		// Write the language row directly. The `created_nav_menu` hook (if
		// any) may have already tagged this term with the default language;
		// REPLACE INTO clobbers that with the correct target language and
		// the source's group id, so the new menu joins the existing group.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"REPLACE INTO {$wpdb->prefix}cml_term_language (term_id, group_id, language) VALUES (%d, %d, %s)",
				$new_term_id,
				$group_id,
				$target_lang
			)
		);
		TranslationGroups::invalidate_term( (int) $source->term_id );
		TranslationGroups::invalidate_term( $new_term_id );

		return $new_term_id;
	}

	/**
	 * Resolve the language-matching menu ID for a given source menu, or 0
	 * when none exists. Pure helper — covered by tests via reflection.
	 */
	public static function translated_menu_id( int $source_menu_id, string $lang ): int {
		if ( $source_menu_id <= 0 || '' === $lang ) {
			return 0;
		}
		$group_id = TranslationGroups::get_term_group_id( $source_menu_id );
		if ( null === $group_id ) {
			return 0;
		}
		$siblings = TranslationGroups::get_term_siblings( $group_id );
		if ( ( $siblings[ $source_menu_id ] ?? null ) === $lang ) {
			return $source_menu_id;
		}
		return (int) ( array_search( $lang, $siblings, true ) ?: 0 );
	}

	// ---- Cloning ------------------------------------------------------------

	private static function clone_items( int $source_menu_id, int $target_menu_id, string $target_lang ): void {
		// Bypass our own placeholder-expansion filter via the explicit
		// switcher flag — otherwise the language-switcher placeholders
		// would already be expanded into rendered <img> + native-name
		// markup and we'd clone those synthesized rows instead of the
		// raw placeholder. remove_filter() proved too brittle here
		// because the static-method callback identity isn't always equal
		// across class autoload paths.
		\Samsiani\CodeonMultilingual\Frontend\NavMenuSwitcher::bypass_expansion( true );
		try {
			$source_items = wp_get_nav_menu_items(
				$source_menu_id,
				array( 'update_post_term_cache' => false )
			);
		} finally {
			\Samsiani\CodeonMultilingual\Frontend\NavMenuSwitcher::bypass_expansion( false );
		}

		if ( ! is_array( $source_items ) || empty( $source_items ) ) {
			return;
		}

		// Pass 1: create translation items, capture old_id → new_id mapping.
		$id_map = array();
		foreach ( $source_items as $source ) {
			$new_id = self::insert_item( $source, $target_menu_id, $target_lang );
			if ( $new_id > 0 ) {
				$id_map[ (int) $source->db_id ] = $new_id;
			}
		}

		// Pass 2: rewire parents using the mapping.
		foreach ( $source_items as $source ) {
			$old_id = (int) $source->db_id;
			$new_id = $id_map[ $old_id ] ?? 0;
			if ( $new_id <= 0 ) {
				continue;
			}
			$old_parent = (int) $source->menu_item_parent;
			if ( $old_parent <= 0 ) {
				continue;
			}
			$new_parent = $id_map[ $old_parent ] ?? 0;
			if ( $new_parent > 0 ) {
				update_post_meta( $new_id, '_menu_item_menu_item_parent', $new_parent );
			}
		}
	}

	/**
	 * Insert one translated menu item. Object id is rewritten to the
	 * sibling in $target_lang when one exists; otherwise we fall back to
	 * the source object so the link still resolves (e.g. an English version
	 * referencing an untranslated Georgian page).
	 *
	 * @param object $source
	 */
	private static function insert_item( $source, int $target_menu_id, string $target_lang ): int {
		$translated_object_id = self::translate_object_id(
			(string) $source->type,
			(string) $source->object,
			(int) $source->object_id,
			$target_lang
		);

		// Title resolution. WP fills `$source->title` from the LINKED post's
		// title when the menu_item's own post_title is empty. So we can't
		// just copy $source->title — that would carry the source-language
		// page title (e.g. "მთავარი") into the translated menu.
		//
		// Strategy:
		//   1. Read menu_item.post_title from the DB. Empty → admin used the
		//      linked post's title automatically → pass empty so the
		//      translated post's title renders in the new menu.
		//   2. Non-empty BUT identical to the source page's current title
		//      → almost certainly auto-filled (older WP behaviour) → also
		//      pass empty so the translated title shows.
		//   3. Non-empty AND different → admin override → preserve verbatim.
		$source_post = get_post( (int) $source->db_id );
		$raw_title   = $source_post instanceof \WP_Post ? (string) $source_post->post_title : '';

		$title = $raw_title;
		if ( '' !== $raw_title && 'post_type' === (string) $source->type ) {
			$linked = get_post( (int) $source->object_id );
			if ( $linked instanceof \WP_Post && (string) $linked->post_title === $raw_title ) {
				$title = '';
			}
		}

		$args = array(
			'menu-item-type'        => (string) $source->type,
			'menu-item-object'      => (string) $source->object,
			'menu-item-object-id'   => $translated_object_id,
			'menu-item-parent-id'   => 0, // wired in pass 2
			'menu-item-position'    => (int) $source->menu_order,
			'menu-item-title'       => $title,
			'menu-item-url'         => (string) $source->url,
			'menu-item-description' => (string) $source->description,
			'menu-item-attr-title'  => (string) $source->attr_title,
			'menu-item-classes'     => implode( ' ', (array) $source->classes ),
			'menu-item-xfn'         => (string) $source->xfn,
			'menu-item-target'      => (string) $source->target,
			'menu-item-status'      => 'publish',
		);

		$new_id = wp_update_nav_menu_item( $target_menu_id, 0, $args );
		if ( is_wp_error( $new_id ) || (int) $new_id <= 0 ) {
			return 0;
		}
		$new_id = (int) $new_id;

		// Preserve the language-switcher placeholder flag so the new item
		// continues to act as a switcher anchor in the translated menu.
		if ( '1' === (string) get_post_meta( (int) $source->db_id, '_cml_language_switcher', true ) ) {
			update_post_meta( $new_id, '_cml_language_switcher', '1' );
			$display = (string) get_post_meta( (int) $source->db_id, '_cml_switcher_display', true );
			$layout  = (string) get_post_meta( (int) $source->db_id, '_cml_switcher_layout', true );
			if ( '' !== $display ) {
				update_post_meta( $new_id, '_cml_switcher_display', $display );
			}
			if ( '' !== $layout ) {
				update_post_meta( $new_id, '_cml_switcher_layout', $layout );
			}
		}

		return $new_id;
	}

	/**
	 * Map a menu item's object_id to its translation in the target language.
	 * Returns the source ID unchanged when the type isn't translatable (custom
	 * link) or when no sibling exists for that language.
	 */
	public static function translate_object_id( string $type, string $object, int $source_object_id, string $target_lang ): int {
		if ( $source_object_id <= 0 ) {
			return 0;
		}
		if ( 'post_type' === $type ) {
			$group = TranslationGroups::get_group_id( $source_object_id );
			if ( null === $group ) {
				return $source_object_id;
			}
			return self::pick_sibling_or_fallback(
				TranslationGroups::get_siblings( $group ),
				$target_lang,
				$source_object_id
			);
		}
		if ( 'taxonomy' === $type ) {
			$group = TranslationGroups::get_term_group_id( $source_object_id );
			if ( null === $group ) {
				return $source_object_id;
			}
			return self::pick_sibling_or_fallback(
				TranslationGroups::get_term_siblings( $group ),
				$target_lang,
				$source_object_id
			);
		}
		// Custom links + unknown types: leave as-is.
		unset( $object );
		return $source_object_id;
	}

	/**
	 * Pure decision: given a siblings map (id => lang), pick the id for
	 * the target language; if none exists, return $fallback (typically the
	 * source ID). Extracted so tests can exercise the contract without
	 * touching the DB-backed TranslationGroups service.
	 *
	 * @param array<int|string, string> $siblings
	 */
	public static function pick_sibling_or_fallback( array $siblings, string $target_lang, int $fallback ): int {
		$key = array_search( $target_lang, $siblings, true );
		if ( false === $key ) {
			return $fallback;
		}
		$id = (int) $key;
		return $id > 0 ? $id : $fallback;
	}

	// ---- Theme-location mapping ---------------------------------------------

	/**
	 * Filter `theme_mod_nav_menu_locations` — for each registered theme
	 * location, if the assigned menu has a sibling in the current language,
	 * swap to that sibling. Otherwise leave the source menu in place so the
	 * front-end always renders SOMETHING.
	 *
	 * @param mixed $locations
	 * @return mixed
	 */
	public static function filter_theme_locations( $locations ) {
		if ( ! is_array( $locations ) || empty( $locations ) ) {
			return $locations;
		}
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $locations;
		}

		$lang = CurrentLanguage::code();
		if ( '' === $lang ) {
			return $locations;
		}

		$out = $locations;
		foreach ( $locations as $location => $menu_id ) {
			$menu_id = (int) $menu_id;
			if ( $menu_id <= 0 ) {
				continue;
			}
			$cache_key = $location . '|' . $lang . '|' . $menu_id;
			if ( isset( self::$location_cache[ $cache_key ] ) ) {
				$out[ $location ] = self::$location_cache[ $cache_key ];
				continue;
			}
			$translated = self::translated_menu_id( $menu_id, $lang );
			if ( $translated > 0 && $translated !== $menu_id ) {
				$out[ $location ] = $translated;
				self::$location_cache[ $cache_key ] = $translated;
			} else {
				self::$location_cache[ $cache_key ] = $menu_id;
			}
		}
		return $out;
	}

	// ---- Admin: meta-box on nav-menus.php -----------------------------------

	public static function register_meta_box(): void {
		add_meta_box(
			'cml-translate-menu',
			__( 'Menu translations', 'codeon-multilingual' ),
			array( self::class, 'render_meta_box' ),
			'nav-menus',
			'side',
			'low'
		);
	}

	public static function render_meta_box(): void {
		$menu_id = self::current_menu_id();
		if ( $menu_id <= 0 ) {
			?>
			<p class="description"><?php esc_html_e( 'Save the menu first, then this panel will let you create translations.', 'codeon-multilingual' ); ?></p>
			<?php
			return;
		}

		$source_lang = TranslationGroups::get_term_language( $menu_id );
		$group_id    = TranslationGroups::get_term_group_id( $menu_id );
		$siblings    = null !== $group_id ? TranslationGroups::get_term_siblings( $group_id ) : array();

		$current = Languages::get( (string) $source_lang );
		?>
		<p style="margin-top:0">
			<strong><?php esc_html_e( 'Language:', 'codeon-multilingual' ); ?></strong>
			<code><?php echo esc_html( (string) ( $source_lang ?? Languages::default_code() ) ); ?></code>
			<?php if ( $current ) : ?>
				<span style="opacity:0.6">(<?php echo esc_html( $current->native ); ?>)</span>
			<?php endif; ?>
		</p>
		<?php if ( count( Languages::active() ) <= 1 ) : ?>
			<p class="description"><?php esc_html_e( 'Only one language is configured. Add more languages to enable menu translations.', 'codeon-multilingual' ); ?></p>
			<?php
			return;
		endif;
		?>
		<ul style="margin:8px 0">
			<?php foreach ( Languages::active() as $code => $lang ) : ?>
				<?php
				if ( $code === $source_lang ) {
					continue;
				}
				$sibling   = (int) ( array_search( $code, $siblings, true ) ?: 0 );
				$code_upper = strtoupper( (string) $code );
				?>
				<li style="margin-bottom:6px">
					<?php if ( $sibling > 0 ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color:#46b450"></span>
						<a href="<?php echo esc_url( self::menu_edit_url( $sibling ) ); ?>">
							<?php
							/* translators: %s: language code uppercase, e.g. RU */
							printf( esc_html__( 'Edit %s menu', 'codeon-multilingual' ), esc_html( $code_upper ) );
							?>
						</a>
						&nbsp;·&nbsp;
						<a href="<?php echo esc_url( self::sync_url( $menu_id, (string) $code ) ); ?>" title="<?php esc_attr_e( 'Re-sync items from the source menu — translated post/term references are refreshed, untranslated items copy the source.', 'codeon-multilingual' ); ?>">
							<?php esc_html_e( 'Re-sync', 'codeon-multilingual' ); ?>
						</a>
					<?php else : ?>
						<span class="dashicons dashicons-plus-alt" style="color:#2271b1"></span>
						<a href="<?php echo esc_url( self::sync_url( $menu_id, (string) $code ) ); ?>">
							<?php
							/* translators: %s: language code uppercase, e.g. RU */
							printf( esc_html__( 'Sync to %s', 'codeon-multilingual' ), esc_html( $code_upper ) );
							?>
						</a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="description">
			<?php esc_html_e( 'Syncing creates / refreshes a per-language copy of this menu. Each item is rewired to the translated post / term when one exists; untranslated items copy the source. The new menu is named with the original name plus the language code in parentheses.', 'codeon-multilingual' ); ?>
		</p>
		<?php
	}

	public static function handle_translate(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		$source = isset( $_GET['source'] ) ? (int) $_GET['source'] : 0;
		$target = isset( $_GET['target'] ) ? sanitize_key( wp_unslash( (string) $_GET['target'] ) ) : '';
		check_admin_referer( self::NONCE_TRANSLATE . '_' . $source . '_' . $target );

		if ( $source <= 0 || '' === $target ) {
			wp_die( esc_html__( 'Invalid request.', 'codeon-multilingual' ) );
		}

		$new_id = self::translate_menu( $source, $target );
		if ( $new_id <= 0 ) {
			wp_die( esc_html__( 'Failed to create menu translation.', 'codeon-multilingual' ) );
		}

		wp_safe_redirect( self::menu_edit_url( $new_id ) );
		exit;
	}

	// ---- URL builders / helpers --------------------------------------------

	public static function translate_url( int $source_menu_id, string $target_lang ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_TRANSLATE,
					'source' => $source_menu_id,
					'target' => $target_lang,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_TRANSLATE . '_' . $source_menu_id . '_' . $target_lang
		);
	}

	public static function sync_url( int $source_menu_id, string $target_lang ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_SYNC,
					'source' => $source_menu_id,
					'target' => $target_lang,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_SYNC . '_' . $source_menu_id . '_' . $target_lang
		);
	}

	public static function handle_sync(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'codeon-multilingual' ) );
		}
		$source = isset( $_GET['source'] ) ? (int) $_GET['source'] : 0;
		$target = isset( $_GET['target'] ) ? sanitize_key( wp_unslash( (string) $_GET['target'] ) ) : '';
		check_admin_referer( self::NONCE_SYNC . '_' . $source . '_' . $target );

		if ( $source <= 0 || '' === $target ) {
			wp_die( esc_html__( 'Invalid request.', 'codeon-multilingual' ) );
		}

		$new_id = self::sync_menu( $source, $target );
		if ( $new_id <= 0 ) {
			wp_die( esc_html__( 'Failed to sync menu.', 'codeon-multilingual' ) );
		}

		wp_safe_redirect( self::menu_edit_url( $new_id ) );
		exit;
	}

	public static function menu_edit_url( int $menu_id ): string {
		return add_query_arg(
			array( 'action' => 'edit', 'menu' => $menu_id ),
			admin_url( 'nav-menus.php' )
		);
	}

	private static function current_menu_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended — read-only screen detection.
		$id = isset( $_GET['menu'] ) ? (int) $_GET['menu'] : 0;
		if ( $id <= 0 ) {
			$id = (int) get_user_option( 'nav_menu_recently_edited' );
		}
		return $id > 0 ? $id : 0;
	}

	/**
	 * Reset the request-static cache. For tests + handlers that mutate
	 * language assignments mid-request.
	 */
	public static function reset_cache(): void {
		self::$location_cache = array();
	}

	/**
	 * `delete_nav_menu` action handler — remove the cml_term_language row
	 * for the deleted menu so it stops appearing in sibling lookups.
	 *
	 * @param int|object $term  WP passes the term object on this action.
	 */
	public static function on_nav_menu_deleted( $term ): void {
		$term_id = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : (int) $term;
		if ( $term_id <= 0 ) {
			return;
		}
		self::orphan_language_row( $term_id );
		TranslationGroups::flush();
		self::reset_cache();
	}

	/** Delete the cml_term_language row for a term — used after a real or detected delete. */
	private static function orphan_language_row( int $term_id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'cml_term_language', array( 'term_id' => $term_id ), array( '%d' ) );
		TranslationGroups::invalidate_term( $term_id );
	}
}
