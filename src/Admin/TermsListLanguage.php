<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin;

use Samsiani\CodeonMultilingual\Content\TermTranslator;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\LanguageCatalog;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use WP_Term_Query;

/**
 * Per-taxonomy admin list (`edit-tags.php`): language quick-link row + a
 * "Languages" column with translate-status icons per row.
 *
 * Mirrors `PostsListLanguage` for taxonomies — every translatable taxonomy
 * (categories, tags, product_cat, product_tag, pa_*) gets the same UI so
 * the admin's translation workflow is consistent across content types.
 *
 * Query filter opts in via `cml_admin_lang_filter` on `WP_Term_Query`
 * (TermsClauses defaults to "show all" in admin); when the admin picks a
 * language from the subsubsub row, we set that arg before the list table
 * runs the query.
 *
 * Performance note: each rendered list page issues one `language_counts`
 * query per taxonomy (cached per request); column rendering relies on
 * `TranslationGroups` which has its own request-static cache, so 50 rows
 * cost ≤ 2 DB hits for sibling lookups.
 */
final class TermsListLanguage {

	public const QUERY_VAR    = 'cml_admin_lang';
	private const COOKIE_NAME = 'cml_admin_term_lang';
	private const COOKIE_DAYS = 30;
	private const ALL_VALUE   = 'all';

	private static bool $registered = false;

	/** @var array<string, array<string, int>> taxonomy => lang => count */
	private static array $counts_cache = array();

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_init',                       array( self::class, 'maybe_persist_choice' ) );
		add_action( 'admin_init',                       array( self::class, 'register_per_taxonomy' ), 99 );
		add_action( 'pre_get_terms',                    array( self::class, 'filter_admin_terms_query' ) );
		add_action( 'admin_print_styles-edit-tags.php', array( self::class, 'render_styles' ) );
	}

	public static function register_per_taxonomy(): void {
		foreach ( TermTranslator::translatable_taxonomies() as $taxonomy ) {
			add_filter( "views_edit-{$taxonomy}",            array( self::class, 'inject_views' ) );
			add_filter( "manage_edit-{$taxonomy}_columns",   array( self::class, 'add_column' ) );
			add_filter( "manage_{$taxonomy}_custom_column",  array( self::class, 'render_column' ), 10, 3 );
		}
	}

	// ---- Current language resolution + cookie ---------------------------

	public static function maybe_persist_choice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended — read-only screen filter.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$value = sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
		// phpcs:enable
		if ( self::ALL_VALUE !== $value && ! Languages::exists_and_active( $value ) ) {
			return;
		}
		setcookie(
			self::COOKIE_NAME,
			$value,
			time() + self::COOKIE_DAYS * DAY_IN_SECONDS,
			defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			is_ssl(),
			true
		);
		$_COOKIE[ self::COOKIE_NAME ] = $value;
	}

	public static function get_admin_language(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended — read-only filter.
		if ( isset( $_GET[ self::QUERY_VAR ] ) ) {
			$v = sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
			if ( self::ALL_VALUE === $v || Languages::exists_and_active( $v ) ) {
				return $v;
			}
		}
		// phpcs:enable
		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			$v = sanitize_key( wp_unslash( (string) $_COOKIE[ self::COOKIE_NAME ] ) );
			if ( self::ALL_VALUE === $v || Languages::exists_and_active( $v ) ) {
				return $v;
			}
		}
		return Languages::default_code();
	}

	// ---- Query filter ----------------------------------------------------

	public static function filter_admin_terms_query( WP_Term_Query $query ): void {
		if ( ! is_admin() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-tags' !== $screen->base ) {
			return;
		}
		if ( ! in_array( $screen->taxonomy, TermTranslator::translatable_taxonomies(), true ) ) {
			return;
		}

		$lang = self::get_admin_language();
		if ( self::ALL_VALUE === $lang ) {
			return;
		}

		CurrentLanguage::set( $lang );
		$query->query_vars['cml_admin_lang_filter'] = true;
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
		$taxonomy = (string) $screen->taxonomy;

		$active = Languages::active();
		if ( count( $active ) <= 1 ) {
			return $views;
		}

		$current = self::get_admin_language();
		$counts  = self::language_counts( $taxonomy );

		$items = array();
		foreach ( $active as $code => $lang ) {
			$is_current = ( $current === $code );
			$items[]    = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( self::lang_url( $taxonomy, $code ) ),
				$is_current ? ' class="current" aria-current="page"' : '',
				esc_html( $lang->native ),
				(int) ( $counts[ $code ] ?? 0 )
			);
		}
		$items[] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( self::lang_url( $taxonomy, self::ALL_VALUE ) ),
			self::ALL_VALUE === $current ? ' class="current" aria-current="page"' : '',
			esc_html__( 'All languages', 'codeon-multilingual' ),
			(int) array_sum( $counts )
		);

		// Same HTML trick PostsListLanguage uses: close WP's <li>/<ul>,
		// open our own <ul>, leave the last <li> open so WP's trailing
		// markup closes our last item + ul cleanly.
		$html = '</li></ul><ul class="subsubsub cml-lang-views">';
		$last = count( $items ) - 1;
		foreach ( $items as $i => $item ) {
			if ( $i === $last ) {
				$html .= '<li>' . $item;
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
			if ( 'name' === $key ) {
				$new      = array_merge( $new, $lang_columns );
				$inserted = true;
			}
		}
		if ( ! $inserted ) {
			$new = array_merge( $new, $lang_columns );
		}
		return $new;
	}

	/**
	 * @param string|mixed $content   existing cell content (preserved)
	 * @param string       $column
	 * @param int          $term_id
	 * @return string
	 */
	public static function render_column( $content, $column = '', $term_id = 0 ): string {
		$content = (string) $content;
		$column  = (string) $column;
		if ( '' === $column || 0 !== strpos( $column, 'cml-lang-' ) ) {
			return $content;
		}
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return $content;
		}
		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$taxonomy = $screen ? (string) $screen->taxonomy : '';
		if ( '' === $taxonomy ) {
			return $content;
		}

		$col_lang = substr( $column, strlen( 'cml-lang-' ) );
		$lang     = Languages::get( $col_lang );
		if ( null === $lang ) {
			return $content;
		}

		$term_lang = TranslationGroups::get_term_language( $term_id ) ?? Languages::default_code();
		$group_id  = TranslationGroups::get_term_group_id( $term_id ) ?? $term_id;
		$siblings  = TranslationGroups::get_term_siblings( $group_id );

		// Source language for this row — render the flag itself.
		if ( $col_lang === $term_lang ) {
			$flag = self::flag_html( $lang );
			$title = sprintf(
				/* translators: %s: language native name */
				__( 'Source language: %s', 'codeon-multilingual' ),
				$lang->native
			);
			return sprintf(
				'<span class="cml-lang-source" title="%s" aria-label="%s">%s</span>',
				esc_attr( $title ),
				esc_attr( $title ),
				$flag
			);
		}

		$sibling_id = (int) ( array_search( $col_lang, $siblings, true ) ?: 0 );

		if ( $sibling_id > 0 && $sibling_id !== $term_id ) {
			$url   = (string) ( get_edit_term_link( $sibling_id, $taxonomy ) ?: '' );
			$title = sprintf(
				/* translators: %s: language native name */
				__( 'Edit %s translation', 'codeon-multilingual' ),
				$lang->native
			);
			return sprintf(
				'<a class="cml-lang-link" href="%s" title="%s" aria-label="%s"><span class="dashicons dashicons-yes-alt cml-icon-translated" aria-hidden="true"></span></a>',
				esc_url( $url ),
				esc_attr( $title ),
				esc_attr( $title )
			);
		}

		$url   = TermTranslator::add_translation_url( $term_id, $taxonomy, (string) $col_lang );
		$title = sprintf(
			/* translators: %s: language native name */
			__( 'Add %s translation', 'codeon-multilingual' ),
			$lang->native
		);
		return sprintf(
			'<a class="cml-lang-link" href="%s" title="%s" aria-label="%s"><span class="dashicons dashicons-plus-alt2 cml-icon-add" aria-hidden="true"></span></a>',
			esc_url( $url ),
			esc_attr( $title ),
			esc_attr( $title )
		);
	}

	/**
	 * Column header: flag + uppercase code. Uses bundled SVG when available,
	 * regional-indicator emoji as fallback. Empty flag string means no flag.
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
	 * Render a flag image (SVG preferred, emoji fallback, empty string when
	 * no country code on the language row).
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

	// ---- Helpers ---------------------------------------------------------

	/**
	 * @return array<string, int> language => count
	 */
	private static function language_counts( string $taxonomy ): array {
		if ( isset( self::$counts_cache[ $taxonomy ] ) ) {
			return self::$counts_cache[ $taxonomy ];
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tl.language, COUNT(*) AS c
				 FROM {$wpdb->prefix}cml_term_language tl
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tl.term_id
				 WHERE tt.taxonomy = %s
				 GROUP BY tl.language",
				$taxonomy
			)
		);

		$counts = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$counts[ (string) $row->language ] = (int) $row->c;
			}
		}
		self::$counts_cache[ $taxonomy ] = $counts;
		return $counts;
	}

	private static function lang_url( string $taxonomy, string $code ): string {
		return add_query_arg(
			array(
				'taxonomy'      => $taxonomy,
				self::QUERY_VAR => $code,
			),
			admin_url( 'edit-tags.php' )
		);
	}

	public static function render_styles(): void {
		// Per-language columns — one narrow column per active language.
		echo '<style>
			.cml-lang-views { margin-top: 4px !important; clear: both; }
			.cml-lang-views li { margin: 0 !important; }
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
		</style>';
	}
}
