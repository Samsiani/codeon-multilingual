<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin;

use Samsiani\CodeonMultilingual\Content\PostTranslator;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use WP_Query;

/**
 * Per-post-type admin list (edit.php) integration: language filter dropdown +
 * "Languages" column showing the post's own language and clickable icons for
 * sibling translations.
 *
 * Performance: a single the_posts filter populates TranslationGroups for every
 * row on the page (one batch query for the group lookup + one query per unique
 * group for siblings — typically << 20 queries for a 20-row table, all cached
 * by the time the column renders).
 *
 * The query filter opts in via WP_Query arg cml_admin_lang_filter=true, which
 * PostsClauses honours specifically so admin filtering doesn't bleed into
 * front-end behaviour.
 */
final class PostsListLanguage {

	public const QUERY_VAR = 'cml_admin_lang';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_menu',           array( self::class, 'register_per_post_type' ), 99 );
		add_action( 'restrict_manage_posts', array( self::class, 'render_filter_dropdown' ), 10, 1 );
		add_action( 'pre_get_posts',         array( self::class, 'filter_admin_query' ) );
		add_filter( 'the_posts',             array( self::class, 'preload_translations' ), 10, 2 );
		add_action( 'admin_print_styles-edit.php', array( self::class, 'render_styles' ) );
	}

	public static function register_per_post_type(): void {
		foreach ( PostTranslator::translatable_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns",       array( self::class, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( self::class, 'render_column' ), 10, 2 );
		}
	}

	// ---- Filter dropdown -------------------------------------------------

	public static function render_filter_dropdown( string $post_type ): void {
		if ( ! in_array( $post_type, PostTranslator::translatable_post_types(), true ) ) {
			return;
		}

		$languages = Languages::active();
		if ( count( $languages ) <= 1 ) {
			return;
		}

		$current = isset( $_GET[ self::QUERY_VAR ] )
			? sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) )
			: '';

		echo '<select name="' . esc_attr( self::QUERY_VAR ) . '" id="cml-admin-lang-filter">';
		echo '<option value="">' . esc_html__( 'All languages', 'codeon-multilingual' ) . '</option>';
		foreach ( $languages as $code => $lang ) {
			printf(
				'<option value="%s"%s>%s (%s)</option>',
				esc_attr( $code ),
				selected( $code, $current, false ),
				esc_html( $lang->native ),
				esc_html( $code )
			);
		}
		echo '</select>';
	}

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

		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$lang = sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
		if ( '' === $lang || ! Languages::exists_and_active( $lang ) ) {
			return;
		}

		// CurrentLanguage drives the SQL fragments cached by PostsClauses; set it
		// to the requested admin language so the JOIN scopes correctly.
		CurrentLanguage::set( $lang );
		$query->set( 'cml_admin_lang_filter', true );
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
		// Fallback if 'title' wasn't in columns (custom CPTs sometimes omit it).
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

		// Warm up sibling cache for each group referenced on the page — one
		// query per unique group.
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
			#cml-admin-lang-filter { margin-right: 6px; }
		</style>
		<?php
	}
}
