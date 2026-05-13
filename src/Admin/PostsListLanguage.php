<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin;

use Samsiani\CodeonMultilingual\Content\PostTranslator;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use WP_Query;

/**
 * Per-post-type admin list (edit.php): language quick-links + "Languages"
 * column with translation status icons.
 *
 * Quick links render as a second .subsubsub row immediately below WP's stock
 * "All | Published | Drafts" via a $views array trick (no JS, no extra HTTP).
 *
 * View priority resolution: ?cml_admin_lang= URL param wins; falls back to
 * the user's previously selected language stored in a cookie; falls back to
 * the site default. The "all" pseudo-value disables the language filter.
 *
 * PostsClauses honours the cml_admin_lang_filter query var we set here so
 * admin filtering is opt-in (and doesn't bleed into the frontend).
 */
final class PostsListLanguage {

	public const QUERY_VAR      = 'cml_admin_lang';
	private const COOKIE_NAME   = 'cml_admin_lang';
	private const COOKIE_DAYS   = 30;
	private const ALL_VALUE     = 'all';

	private static bool $registered = false;

	/** @var array<string, array<string, int>> */
	private static array $counts_cache = array();

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_init',                  array( self::class, 'maybe_persist_choice' ) );
		add_action( 'admin_menu',                  array( self::class, 'register_per_post_type' ), 99 );
		add_action( 'pre_get_posts',               array( self::class, 'filter_admin_query' ) );
		add_filter( 'the_posts',                   array( self::class, 'preload_translations' ), 10, 2 );
		add_action( 'admin_print_styles-edit.php', array( self::class, 'render_styles' ) );
	}

	public static function register_per_post_type(): void {
		foreach ( PostTranslator::translatable_post_types() as $post_type ) {
			add_filter( "views_edit-{$post_type}",                 array( self::class, 'inject_views' ) );
			add_filter( "manage_{$post_type}_posts_columns",       array( self::class, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( self::class, 'render_column' ), 10, 2 );
		}
	}

	// ---- Current language resolution + cookie ---------------------------

	public static function maybe_persist_choice(): void {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		$value = sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
		if ( self::ALL_VALUE !== $value && ! Languages::exists_and_active( $value ) ) {
			return;
		}
		setcookie(
			self::COOKIE_NAME,
			$value,
			time() + self::COOKIE_DAYS * DAY_IN_SECONDS,
			defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
			defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
			is_ssl(),
			true
		);
		// Reflect the choice for the rest of the current request.
		$_COOKIE[ self::COOKIE_NAME ] = $value;
	}

	public static function get_admin_language(): string {
		if ( isset( $_GET[ self::QUERY_VAR ] ) ) {
			$value = sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
			if ( self::ALL_VALUE === $value ) {
				return self::ALL_VALUE;
			}
			if ( Languages::exists_and_active( $value ) ) {
				return $value;
			}
		}
		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$value = sanitize_key( (string) $_COOKIE[ self::COOKIE_NAME ] );
			if ( self::ALL_VALUE === $value ) {
				return self::ALL_VALUE;
			}
			if ( Languages::exists_and_active( $value ) ) {
				return $value;
			}
		}
		return Languages::default_code();
	}

	// ---- Query filter ----------------------------------------------------

	public static function filter_admin_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}
		if ( ! in_array( $screen->post_type, PostTranslator::translatable_post_types(), true ) ) {
			return;
		}

		$lang = self::get_admin_language();
		if ( self::ALL_VALUE === $lang ) {
			return; // No filtering — show everything.
		}

		CurrentLanguage::set( $lang );
		$query->set( 'cml_admin_lang_filter', true );
	}

	// ---- Views (subsubsub quick links) ----------------------------------

	/**
	 * @param array<string, string> $views
	 * @return array<string, string>
	 */
	public static function inject_views( array $views ): array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return $views;
		}
		$post_type = $screen->post_type;

		$active = Languages::active();
		if ( count( $active ) <= 1 ) {
			return $views;
		}

		$current = self::get_admin_language();
		$counts  = self::language_counts( $post_type );

		$items = array();
		foreach ( $active as $code => $lang ) {
			$is_current = ( $current === $code );
			$items[]    = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( self::lang_url( $post_type, $code ) ),
				$is_current ? ' class="current" aria-current="page"' : '',
				esc_html( $lang->native ),
				(int) ( $counts[ $code ] ?? 0 )
			);
		}
		$items[] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( self::lang_url( $post_type, self::ALL_VALUE ) ),
			self::ALL_VALUE === $current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'All languages', 'codeon-multilingual' ),
			(int) array_sum( $counts )
		);

		// HTML trick: WP renders views as <li class='cml-lang-row'>...</li> inside
		// <ul class='subsubsub'>. We close that <li> + <ul> immediately, open a
		// second <ul.subsubsub>, render our links, and leave the last <li> open so
		// WP's trailing </li></ul> closes our last item + our second ul cleanly.
		$html = '</li></ul><ul class="subsubsub cml-lang-views">';
		$last = count( $items ) - 1;
		foreach ( $items as $i => $item ) {
			if ( $i === $last ) {
				$html .= '<li>' . $item; // unclosed — WP appends </li></ul>
			} else {
				$html .= '<li>' . $item . '</li> | ';
			}
		}

		$views['cml-lang-row'] = $html;
		return $views;
	}

	// ---- Languages column ------------------------------------------------

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function add_column( array $columns ): array {
		$languages = Languages::active();
		if ( count( $languages ) <= 1 ) {
			return $columns;
		}

		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['cml-languages'] = __( 'Languages', 'codeon-multilingual' );
			}
		}
		if ( ! isset( $new['cml-languages'] ) ) {
			$new['cml-languages'] = __( 'Languages', 'codeon-multilingual' );
		}
		return $new;
	}

	public static function render_column( string $column_name, int $post_id ): void {
		if ( 'cml-languages' !== $column_name ) {
			return;
		}

		$post_lang = TranslationGroups::get_language( $post_id ) ?? Languages::default_code();
		$group_id  = TranslationGroups::get_group_id( $post_id ) ?? $post_id;
		$siblings  = TranslationGroups::get_siblings( $group_id );
		$languages = Languages::active();

		echo '<span class="cml-lang-badge">' . esc_html( strtoupper( $post_lang ) ) . '</span>';

		foreach ( $languages as $code => $lang ) {
			if ( $code === $post_lang ) {
				continue;
			}

			$sibling_id = (int) ( array_search( $code, $siblings, true ) ?: 0 );

			if ( $sibling_id > 0 && $sibling_id !== $post_id ) {
				$url   = (string) ( get_edit_post_link( $sibling_id, 'raw' ) ?: '' );
				$title = sprintf(
					/* translators: %s: language native name */
					__( 'Edit %s translation', 'codeon-multilingual' ),
					$lang->native
				);
				printf(
					'<a class="cml-lang-link" href="%s" title="%s" aria-label="%s"><span class="dashicons dashicons-yes-alt cml-icon-translated" aria-hidden="true"></span></a>',
					esc_url( $url ),
					esc_attr( $title ),
					esc_attr( $title )
				);
			} else {
				$url   = PostTranslator::add_translation_url( $post_id, $code );
				$title = sprintf(
					/* translators: %s: language native name */
					__( 'Add %s translation', 'codeon-multilingual' ),
					$lang->native
				);
				printf(
					'<a class="cml-lang-link" href="%s" title="%s" aria-label="%s"><span class="dashicons dashicons-plus-alt2 cml-icon-add" aria-hidden="true"></span></a>',
					esc_url( $url ),
					esc_attr( $title ),
					esc_attr( $title )
				);
			}
		}
	}

	// ---- Performance -----------------------------------------------------

	/**
	 * @param array<int, \WP_Post|int> $posts
	 * @return array<int, \WP_Post|int>
	 */
	public static function preload_translations( $posts, WP_Query $query ): array {
		if ( ! is_array( $posts ) || empty( $posts ) ) {
			return $posts;
		}
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return $posts;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base ) {
			return $posts;
		}

		$ids = array();
		foreach ( $posts as $p ) {
			$ids[] = is_object( $p ) ? (int) $p->ID : (int) $p;
		}
		TranslationGroups::preload( $ids );

		$seen = array();
		foreach ( $ids as $id ) {
			$group = TranslationGroups::get_group_id( $id );
			if ( null === $group || isset( $seen[ $group ] ) ) {
				continue;
			}
			$seen[ $group ] = true;
			TranslationGroups::get_siblings( $group );
		}

		return $posts;
	}

	// ---- Helpers ---------------------------------------------------------

	/**
	 * @return array<string, int> language => count
	 */
	private static function language_counts( string $post_type ): array {
		if ( isset( self::$counts_cache[ $post_type ] ) ) {
			return self::$counts_cache[ $post_type ];
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pl.language, COUNT(*) AS c
				 FROM {$wpdb->prefix}cml_post_language pl
				 INNER JOIN {$wpdb->posts} p ON p.ID = pl.post_id
				 WHERE p.post_type = %s
				   AND p.post_status NOT IN ('auto-draft', 'trash')
				 GROUP BY pl.language",
				$post_type
			)
		);

		$counts = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$counts[ (string) $r->language ] = (int) $r->c;
			}
		}

		self::$counts_cache[ $post_type ] = $counts;
		return $counts;
	}

	private static function lang_url( string $post_type, string $lang ): string {
		return add_query_arg(
			array(
				'post_type'     => $post_type,
				self::QUERY_VAR => $lang,
			),
			admin_url( 'edit.php' )
		);
	}

	// ---- Styles ----------------------------------------------------------

	public static function render_styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}
		if ( ! in_array( $screen->post_type, PostTranslator::translatable_post_types(), true ) ) {
			return;
		}
		?>
		<style>
			.subsubsub.cml-lang-views {
				clear: both;
				display: block;
				margin: 4px 0 6px;
				padding: 0;
				font-size: 13px;
				color: #50575e;
			}
			.subsubsub.cml-lang-views .count {
				color: #646970;
				font-weight: 400;
			}
			.column-cml-languages { width: 140px; text-align: left; white-space: nowrap; }
			.cml-lang-badge {
				display: inline-block;
				padding: 1px 7px;
				background: #2271b1;
				color: #fff;
				border-radius: 3px;
				font-size: 10px;
				font-weight: 700;
				letter-spacing: 0.5px;
				margin-right: 6px;
				vertical-align: middle;
				line-height: 18px;
			}
			.cml-lang-link { text-decoration: none; vertical-align: middle; display: inline-block; }
			.column-cml-languages .dashicons {
				font-size: 18px;
				height: 18px;
				width: 18px;
				line-height: 18px;
				vertical-align: middle;
				margin-right: 2px;
			}
			.cml-icon-translated { color: #46b450; }
			.cml-icon-add { color: #2271b1; opacity: 0.6; transition: opacity 0.15s; }
			.cml-lang-link:hover .cml-icon-add { opacity: 1; }
		</style>
		<?php
	}
}
