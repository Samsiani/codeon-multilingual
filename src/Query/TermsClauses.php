<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Query;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * terms_clauses analog of PostsClauses — single LEFT JOIN on wp_cml_term_language
 * so WP_Term_Query results are language-scoped.
 *
 * Skipped when the caller explicitly addresses terms by id (include / parent /
 * child_of), in admin, or via the cml_skip_filter arg. SQL fragments are
 * memoized once per request.
 */
final class TermsClauses {

	private const JOIN_ALIAS = 'cml_tl';

	private static bool $registered = false;

	private static ?string $join_sql    = null;
	private static ?string $where_sql   = null;
	private static ?string $cached_code = null;

	/**
	 * Temporary scope for admin queries. When non-empty, the clauses filter
	 * applies the language WHERE even in admin context. Set by hooks that
	 * need a short window of language-scoped admin queries (e.g.
	 * `wp_update_term`'s duplicate-slug check during a translation save).
	 */
	private static string $admin_lang_scope = '';

	public static function set_admin_lang_scope( string $lang ): void {
		self::$admin_lang_scope = $lang;
		self::reset_cache();
	}

	public static function clear_admin_lang_scope(): void {
		self::$admin_lang_scope = '';
		self::reset_cache();
	}

	public static function admin_lang_scope(): string {
		return self::$admin_lang_scope;
	}

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'terms_clauses', array( self::class, 'filter_clauses' ), 10, 3 );
	}

	/**
	 * @param array<string, string> $clauses
	 * @param array<int, string>    $taxonomies
	 * @param array<string, mixed>  $args
	 * @return array<string, string>
	 */
	public static function filter_clauses( array $clauses, array $taxonomies, array $args ): array {
		if ( self::should_skip( $args ) ) {
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
		// The admin scope (set during wp_update_term on a translation) takes
		// priority over the request's CurrentLanguage so the duplicate-slug
		// check sees only siblings of the term being saved.
		$code = '' !== self::$admin_lang_scope ? self::$admin_lang_scope : CurrentLanguage::code();
		if (
			self::$cached_code === $code
			&& null !== self::$join_sql
			&& null !== self::$where_sql
		) {
			return array( self::$join_sql, self::$where_sql );
		}

		global $wpdb;
		$alias = self::JOIN_ALIAS;

		self::$join_sql = " LEFT JOIN {$wpdb->prefix}cml_term_language {$alias} ON {$alias}.term_id = t.term_id";

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

	/**
	 * @param array<string, mixed> $args
	 */
	private static function should_skip( array $args ): bool {
		if ( ! empty( $args['cml_skip_filter'] ) ) {
			return true;
		}
		if ( ! empty( $args['include'] ) ) {
			return true;
		}
		if ( ! empty( $args['child_of'] ) ) {
			return true;
		}
		if ( ! empty( $args['name__in'] ) ) {
			return true;
		}
		// Admin defaults to "show all languages", except when our
		// TermsListLanguage screen opts in explicitly via this query arg
		// OR `admin_lang_scope` was set (e.g. during wp_update_term's
		// duplicate-slug check on a translation save).
		if ( is_admin() && empty( $args['cml_admin_lang_filter'] ) && '' === self::$admin_lang_scope ) {
			return true;
		}
		return false;
	}

	public static function reset_cache(): void {
		self::$join_sql    = null;
		self::$where_sql   = null;
		self::$cached_code = null;
	}
}
