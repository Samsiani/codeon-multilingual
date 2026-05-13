<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Query;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
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

		add_filter( 'posts_clauses', [ self::class, 'filter_clauses' ], 10, 2 );
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
			return [ self::$join_sql, self::$where_sql ];
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
		return [ self::$join_sql, self::$where_sql ];
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

		// Admin sees all languages mixed for now — the admin lang filter ships in a later iteration.
		if ( is_admin() ) {
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
