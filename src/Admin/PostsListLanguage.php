<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin;

use Samsiani\CodeonMultilingual\Content\PostTranslator;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\LanguageCatalog;
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

	// ---- Languages columns (one per active language) --------------------

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function add_column( array $columns ): array {
		$languages = Languages::active();
		if ( count( $languages ) <= 1 ) {
			return $columns;
		}

		$lang_columns = array();
		foreach ( $languages as $code => $lang ) {
			$lang_columns[ 'cml-lang-' . $code ] = self::column_header_html( (string) $code, $lang );
		}

		$new      = array();
		$inserted = false;
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new      = array_merge( $new, $lang_columns );
				$inserted = true;
			}
		}
		if ( ! $inserted ) {
			$new = array_merge( $new, $lang_columns );
		}
		return $new;
	}

	public static function render_column( string $column_name, int $post_id ): void {
		if ( '' === $column_name || 0 !== strpos( $column_name, 'cml-lang-' ) ) {
			return;
		}
		$col_lang = substr( $column_name, strlen( 'cml-lang-' ) );
		$lang     = Languages::get( $col_lang );
		if ( null === $lang ) {
			return;
		}

		$post_lang = TranslationGroups::get_language( $post_id ) ?? Languages::default_code();
		$group_id  = TranslationGroups::get_group_id( $post_id ) ?? $post_id;
		$siblings  = TranslationGroups::get_siblings( $group_id );

		// Source language for this row — render the flag itself.
		if ( $col_lang === $post_lang ) {
			$flag  = self::flag_html( $lang );
			$title = sprintf(
				/* translators: %s: language native name */
				__( 'Source language: %s', 'codeon-multilingual' ),
				$lang->native
			);
			printf(
				'<span class="cml-lang-source" title="%s" aria-label="%s">%s</span>',
				esc_attr( $title ),
				esc_attr( $title ),
				$flag // already HTML
			);
			return;
		}

		$sibling_id = (int) ( array_search( $col_lang, $siblings, true ) ?: 0 );

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
			return;
		}

		$url   = PostTranslator::add_translation_url( $post_id, $col_lang );
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

	/**
	 * Column header HTML: flag + uppercase code.
	 *
	 * @param string $code
	 * @param object $lang
	 */
	private static function column_header_html( string $code, $lang ): string {
		$flag       = self::flag_html( $lang );
		$code_label = esc_html( strtoupper( $code ) );
		$tooltip    = esc_attr( $lang->native ?? $code );

		return sprintf(
			'<span class="cml-col-header" title="%s">%s<span class="cml-col-code">%s</span></span>',
			$tooltip,
			$flag,
			$code_label
		);
	}

	/**
	 * Flag HTML — SVG preferred, regional-indicator emoji fallback, empty
	 * when the language row has no country code.
	 *
	 * @param object $lang
	 */
	private static function flag_html( $lang ): string {
		$flag_code = (string) ( $lang->flag ?? '' );
		if ( '' === $flag_code ) {
			return '';
		}
		$svg = LanguageCatalog::flag_svg_url( $flag_code );
		if ( null !== $svg ) {
			return sprintf(
				'<img class="cml-flag-svg" src="%s" alt="" width="16" height="11" />',
				esc_url( $svg )
			);
		}
		$emoji = LanguageCatalog::flag_emoji( $flag_code );
		if ( '' !== $emoji ) {
			return '<span class="cml-flag-emoji" aria-hidden="true">' . esc_html( $emoji ) . '</span>';
		}
		return '';
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
			th[class*="column-cml-lang-"], td[class*="column-cml-lang-"] {
				width: 56px; min-width: 56px; text-align: center; white-space: nowrap;
			}
			.cml-col-header {
				display: inline-flex; align-items: center; gap: 4px;
				font-weight: 600; line-height: 1;
			}
			.cml-col-header .cml-flag-svg {
				width: 16px; height: 11px; border-radius: 1px;
				box-shadow: 0 0 0 1px rgba(0,0,0,0.08); display: inline-block;
			}
			.cml-col-header .cml-flag-emoji { font-size: 14px; line-height: 1; }
			.cml-col-code { font-size: 11px; letter-spacing: 0.3px; color: #2c3338; }
			.cml-lang-source .cml-flag-svg {
				width: 18px; height: 13px; border-radius: 1px;
				box-shadow: 0 0 0 1px rgba(0,0,0,0.08); vertical-align: middle;
			}
			.cml-lang-source .cml-flag-emoji { font-size: 16px; line-height: 1; vertical-align: middle; }
			td[class*="column-cml-lang-"] .cml-lang-link {
				text-decoration: none; display: inline-block; vertical-align: middle;
			}
			td[class*="column-cml-lang-"] .dashicons {
				font-size: 18px; height: 18px; width: 18px; line-height: 18px;
				vertical-align: middle;
			}
			td[class*="column-cml-lang-"] .cml-icon-translated { color: #46b450; }
			td[class*="column-cml-lang-"] .cml-icon-add { color: #b4b9be; transition: color 0.15s; }
			td[class*="column-cml-lang-"] .cml-lang-link:hover .cml-icon-add { color: #2271b1; }
		</style>
		<?php
	}
}
