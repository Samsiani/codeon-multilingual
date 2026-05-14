<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Query;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use WP_Query;

/**
 * Single LEFT JOIN injected into every WP_Query via posts_clauses.
 *
 * Default-language queries:   (language = $default OR language IS NULL)
 *   — the IS NULL branch lets legacy / mid-backfill untagged posts surface in the
 *   default pool, never in non-default pools.
 *
 * Non-default queries:        language = $code
 *   — strict; untagged posts only belong to default.
 *
 * SQL fragments are memoised once per request (current language is stable for
 * the request lifetime). Each additional WP_Query pays only one string concat,
 * keeping per-query overhead in the low microseconds.
 */
final class PostsClauses {

	private const JOIN_ALIAS = 'cml_pl';

	private static bool $registered = false;

	private static ?string $join_sql    = null;
	private static ?string $where_sql   = null;
	private static ?string $cached_code = null;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'posts_clauses', array( self::class, 'filter_clauses' ), 10, 2 );
		// WP's `get_page_by_path()` is a raw SQL lookup with no filter hook, so
		// WP_Query for `?pagename=cart` finds the wrong-language cart page as
		// `queried_object`, then runs the main query (which we DO filter via
		// posts_clauses) and returns the right-language page. queried_object_id
		// no longer matches posts[0]->ID, and WP::handle_404() flips to 404.
		//
		// Intercepting the `request` filter is the cleanest fix: when WP has
		// parsed the URL into `pagename=cart`, look up the sibling in the
		// current language and rewrite the query to `page_id=<sibling>`. WP
		// then queries by ID directly, no get_page_by_path call, no mismatch.
		add_filter( 'request', array( self::class, 'route_pagename_to_translation' ), 10 );
	}

	/**
	 * @param mixed $query_vars
	 * @return mixed
	 */
	public static function route_pagename_to_translation( $query_vars ) {
		if ( ! is_array( $query_vars ) ) {
			return $query_vars;
		}
		$pagename = isset( $query_vars['pagename'] ) ? (string) $query_vars['pagename'] : '';
		if ( '' === $pagename ) {
			return $query_vars;
		}
		$current = CurrentLanguage::code();

		// Slug uniqueness in our schema is scoped per language, so a slug
		// may legitimately live in any language. Resolve to the row that
		// matches the current request language; fall back to default-language
		// row when no translation exists.
		global $wpdb;
		$leaf = $pagename;
		if ( false !== strpos( $pagename, '/' ) ) {
			$parts = array_filter( explode( '/', $pagename ) );
			$leaf  = (string) end( $parts );
		}

		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->prefix}cml_post_language pl ON pl.post_id = p.ID
				 WHERE p.post_name = %s
				   AND p.post_type = 'page'
				   AND p.post_status IN ('publish', 'private')
				   AND pl.language = %s
				 LIMIT 1",
				$leaf,
				$current
			)
		);

		if ( null === $row ) {
			// No translation in the current language. Fall back to the
			// default-language sibling so the URL renders the source page
			// instead of 404'ing — consistent with the cart-items fallback
			// rule (untranslated items show in original language). Admins
			// can create a proper translation later.
			$row = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.ID
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->prefix}cml_post_language pl ON pl.post_id = p.ID
					 WHERE p.post_name = %s
					   AND p.post_type = 'page'
					   AND p.post_status IN ('publish', 'private')
					   AND pl.language = %s
					 LIMIT 1",
					$leaf,
					Languages::default_code()
				)
			);
			if ( null === $row ) {
				return $query_vars;
			}
		}

		$sibling_id = (int) $row;
		if ( $sibling_id <= 0 ) {
			return $query_vars;
		}

		// Always rewrite to an ID query — idempotent if the slug already
		// resolves to this same post, decisive if a different-language sibling
		// is the SQL "first match" returned by get_page_by_path().
		unset( $query_vars['pagename'], $query_vars['name'] );
		$query_vars['page_id'] = $sibling_id;
		return $query_vars;
	}

	/**
	 * @param array<string, string> $clauses
	 * @return array<string, string>
	 */
	public static function filter_clauses( array $clauses, WP_Query $query ): array {
		if ( self::should_skip( $query ) ) {
			return $clauses;
		}

		$join_existing = $clauses['join'] ?? '';
		if ( str_contains( $join_existing, self::JOIN_ALIAS ) ) {
			return $clauses;
		}

		[ $join, $where ] = self::sql_fragments();

		$clauses['join']  = $join_existing . $join;
		$clauses['where'] = ( $clauses['where'] ?? '' ) . $where;

		return $clauses;
	}

	/**
	 * @return array{0:string,1:string}
	 */
	private static function sql_fragments(): array {
		$code = CurrentLanguage::code();
		if (
			self::$cached_code === $code
			&& null !== self::$join_sql
			&& null !== self::$where_sql
		) {
			return array( self::$join_sql, self::$where_sql );
		}

		global $wpdb;
		$alias = self::JOIN_ALIAS;

		self::$join_sql = " LEFT JOIN {$wpdb->prefix}cml_post_language {$alias} ON {$alias}.post_id = {$wpdb->posts}.ID";

		if ( Languages::is_default( $code ) ) {
			self::$where_sql = $wpdb->prepare(
				" AND ({$alias}.language = %s OR {$alias}.language IS NULL)",
				$code
			);
		} else {
			self::$where_sql = $wpdb->prepare(
				" AND {$alias}.language = %s",
				$code
			);
		}

		self::$cached_code = $code;
		return array( self::$join_sql, self::$where_sql );
	}

	private static function should_skip( WP_Query $query ): bool {
		if ( true === $query->get( 'cml_skip_filter' ) ) {
			return true;
		}

		// Specific-post lookups bypass language scoping — caller knows what it wants.
		if ( $query->get( 'p' ) || $query->get( 'page_id' ) || $query->get( 'attachment_id' ) ) {
			return true;
		}
		$post__in = $query->get( 'post__in' );
		if ( ! empty( $post__in ) ) {
			return true;
		}

		// Admin defaults to "show all languages", except when the admin lang
		// filter dropdown is engaged (PostsListLanguage sets this query var).
		if ( is_admin() && true !== $query->get( 'cml_admin_lang_filter' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Reset memoised SQL. For tests or callers that mutate CurrentLanguage mid-request.
	 */
	public static function reset_cache(): void {
		self::$join_sql    = null;
		self::$where_sql   = null;
		self::$cached_code = null;
	}
}
