<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Safe repair operations for issues reported by HealthReport.
 *
 * Every repair is idempotent and limited to CodeOn companion tables. Source
 * WordPress posts/terms are never deleted or modified.
 */
final class HealthRepair {

	private const SCOPE_ALL                       = 'all';
	private const SCOPE_ORPHANED_POST_ROWS       = 'orphaned-post-rows';
	private const SCOPE_ORPHANED_TERM_ROWS       = 'orphaned-term-rows';
	private const SCOPE_ORPHANED_STRING_ROWS     = 'orphaned-string-rows';
	private const SCOPE_MISSING_POST_ROWS        = 'missing-post-rows';
	private const SCOPE_MISSING_TERM_ROWS        = 'missing-term-rows';
	private const SCOPE_UNKNOWN_SOURCE_LANGUAGES = 'unknown-source-languages';

	/**
	 * @return array<int,string>
	 */
	public static function scopes(): array {
		return array(
			self::SCOPE_ALL,
			self::SCOPE_ORPHANED_POST_ROWS,
			self::SCOPE_ORPHANED_TERM_ROWS,
			self::SCOPE_ORPHANED_STRING_ROWS,
			self::SCOPE_MISSING_POST_ROWS,
			self::SCOPE_MISSING_TERM_ROWS,
			self::SCOPE_UNKNOWN_SOURCE_LANGUAGES,
		);
	}

	/**
	 * @return array{
	 *   dry_run:bool,
	 *   scope:string,
	 *   default_language:string,
	 *   actions:array<string,array{label:string,count:int,applied:bool}>,
	 *   total:int,
	 *   errors:array<int,string>
	 * }
	 */
	public static function run( string $scope = self::SCOPE_ALL, bool $dry_run = true ): array {
		$scope = self::normalize_scope( $scope );

		$result = array(
			'dry_run'          => $dry_run,
			'scope'            => $scope,
			'default_language' => Languages::default_code(),
			'actions'          => array(),
			'total'            => 0,
			'errors'           => array(),
		);

		foreach ( self::action_map() as $key => $action ) {
			if ( ! self::scope_matches( $scope, $key ) ) {
				continue;
			}

			$count = (int) $action['count']();
			$entry = array(
				'label'   => (string) $action['label'],
				'count'   => $count,
				'applied' => false,
			);

			if ( ! $dry_run && $count > 0 ) {
				$applied_count = (int) $action['apply']();
				$entry['count'] = $applied_count;
				$entry['applied'] = true;
			}

			$result['actions'][ $key ] = $entry;
			$result['total'] += $entry['count'];
		}

		if ( ! $dry_run && array() === $result['errors'] ) {
			Languages::flush_cache();
			StringTranslator::flush_cache();
			TranslationGroups::flush();
		}

		return $result;
	}

	public static function is_system_term_taxonomy( string $taxonomy ): bool {
		return in_array( $taxonomy, self::system_term_taxonomies(), true );
	}

	/**
	 * @return array<int,string>
	 */
	public static function system_term_taxonomies(): array {
		return array(
			'language',
			'term_language',
			'post_translations',
			'term_translations',
			'nav_menu',
			'link_category',
			'post_format',
			'wp_theme',
			'wp_template_part_area',
			'product_type',
			'product_visibility',
			'elementor_library_type',
			'elementor_library_category',
		);
	}

	public static function normalize_scope( string $scope ): string {
		$scope = strtolower( trim( $scope ) );
		$scope = (string) preg_replace( '/[^a-z0-9_-]/', '', $scope );
		return in_array( $scope, self::scopes(), true ) ? $scope : self::SCOPE_ALL;
	}

	/**
	 * @return array<string,array{label:string,count:callable():int,apply:callable():int}>
	 */
	private static function action_map(): array {
		return array(
			self::SCOPE_ORPHANED_POST_ROWS       => array(
				'label' => 'Delete orphaned post language rows',
				'count' => array( self::class, 'count_orphaned_post_rows' ),
				'apply' => array( self::class, 'delete_orphaned_post_rows' ),
			),
			self::SCOPE_ORPHANED_TERM_ROWS       => array(
				'label' => 'Delete orphaned term language rows',
				'count' => array( self::class, 'count_orphaned_term_rows' ),
				'apply' => array( self::class, 'delete_orphaned_term_rows' ),
			),
			self::SCOPE_ORPHANED_STRING_ROWS     => array(
				'label' => 'Delete orphaned string translation rows',
				'count' => array( self::class, 'count_orphaned_string_rows' ),
				'apply' => array( self::class, 'delete_orphaned_string_rows' ),
			),
			self::SCOPE_MISSING_POST_ROWS        => array(
				'label' => 'Backfill missing post language rows',
				'count' => array( self::class, 'count_missing_post_rows' ),
				'apply' => array( self::class, 'backfill_missing_post_rows' ),
			),
			self::SCOPE_MISSING_TERM_ROWS        => array(
				'label' => 'Backfill missing public term language rows',
				'count' => array( self::class, 'count_missing_term_rows' ),
				'apply' => array( self::class, 'backfill_missing_term_rows' ),
			),
			self::SCOPE_UNKNOWN_SOURCE_LANGUAGES => array(
				'label' => 'Normalize unknown source-string languages',
				'count' => array( self::class, 'count_unknown_source_languages' ),
				'apply' => array( self::class, 'normalize_unknown_source_languages' ),
			),
		);
	}

	private static function scope_matches( string $scope, string $action ): bool {
		return self::SCOPE_ALL === $scope || $scope === $action;
	}

	private static function count_orphaned_post_rows(): int {
		global $wpdb;
		return self::count_sql(
			"SELECT COUNT(*)
			 FROM {$wpdb->prefix}cml_post_language m
			 LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE p.ID IS NULL"
		);
	}

	private static function delete_orphaned_post_rows(): int {
		global $wpdb;
		return self::query_sql(
			"DELETE m
			 FROM {$wpdb->prefix}cml_post_language m
			 LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE p.ID IS NULL"
		);
	}

	private static function count_orphaned_term_rows(): int {
		global $wpdb;
		return self::count_sql(
			"SELECT COUNT(*)
			 FROM {$wpdb->prefix}cml_term_language m
			 LEFT JOIN {$wpdb->terms} t ON t.term_id = m.term_id
			 WHERE t.term_id IS NULL"
		);
	}

	private static function delete_orphaned_term_rows(): int {
		global $wpdb;
		return self::query_sql(
			"DELETE m
			 FROM {$wpdb->prefix}cml_term_language m
			 LEFT JOIN {$wpdb->terms} t ON t.term_id = m.term_id
			 WHERE t.term_id IS NULL"
		);
	}

	private static function count_orphaned_string_rows(): int {
		global $wpdb;
		return self::count_sql(
			"SELECT COUNT(*)
			 FROM {$wpdb->prefix}cml_string_translations st
			 LEFT JOIN {$wpdb->prefix}cml_strings s ON s.id = st.string_id
			 WHERE s.id IS NULL"
		);
	}

	private static function delete_orphaned_string_rows(): int {
		global $wpdb;
		return self::query_sql(
			"DELETE st
			 FROM {$wpdb->prefix}cml_string_translations st
			 LEFT JOIN {$wpdb->prefix}cml_strings s ON s.id = st.string_id
			 WHERE s.id IS NULL"
		);
	}

	private static function count_missing_post_rows(): int {
		global $wpdb;
		return self::count_sql(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->prefix}cml_post_language m ON m.post_id = p.ID
			 WHERE p.post_type <> 'revision'
			   AND p.post_status NOT IN ('auto-draft', 'trash')
			   AND m.post_id IS NULL"
		);
	}

	private static function backfill_missing_post_rows(): int {
		global $wpdb;
		$default = Languages::default_code();

		return self::query_sql(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}cml_post_language (post_id, group_id, language)
				 SELECT p.ID, p.ID, %s
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->prefix}cml_post_language m ON m.post_id = p.ID
				 WHERE p.post_type <> 'revision'
				   AND p.post_status NOT IN ('auto-draft', 'trash')
				   AND m.post_id IS NULL",
				$default
			)
		);
	}

	private static function count_missing_term_rows(): int {
		global $wpdb;
		$taxonomy_where = self::public_term_taxonomy_where();

		$sql = "SELECT COUNT(*) FROM (
			SELECT t.term_id
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			LEFT JOIN {$wpdb->prefix}cml_term_language m ON m.term_id = t.term_id
			WHERE m.term_id IS NULL
			  AND {$taxonomy_where}
			GROUP BY t.term_id
		) cml_missing_terms";

		return self::count_sql( $sql );
	}

	private static function backfill_missing_term_rows(): int {
		global $wpdb;
		$default        = Languages::default_code();
		$taxonomy_where = self::public_term_taxonomy_where();

		$sql = "INSERT IGNORE INTO {$wpdb->prefix}cml_term_language (term_id, group_id, language)
			SELECT t.term_id, t.term_id, %s
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			LEFT JOIN {$wpdb->prefix}cml_term_language m ON m.term_id = t.term_id
			WHERE m.term_id IS NULL
			  AND {$taxonomy_where}
			GROUP BY t.term_id";

		return self::query_sql(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Taxonomy predicate is prepared by public_term_taxonomy_where(); default language remains bound here.
			$wpdb->prepare( $sql, $default )
		);
	}

	private static function count_unknown_source_languages(): int {
		global $wpdb;
		return self::count_sql(
			"SELECT COUNT(*)
			 FROM {$wpdb->prefix}cml_strings s
			 LEFT JOIN {$wpdb->prefix}cml_languages l ON l.code = s.source_language
			 WHERE s.source_language <> '' AND l.code IS NULL"
		);
	}

	private static function normalize_unknown_source_languages(): int {
		global $wpdb;
		$default = Languages::default_code();

		return self::query_sql(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}cml_strings s
				 LEFT JOIN {$wpdb->prefix}cml_languages l ON l.code = s.source_language
				 SET s.source_language = %s
				 WHERE s.source_language <> '' AND l.code IS NULL",
				$default
			)
		);
	}

	private static function public_term_taxonomy_where(): string {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( self::system_term_taxonomies() ), '%s' ) );
		return $wpdb->prepare(
			"tt.taxonomy NOT IN ({$placeholders})",
			...self::system_term_taxonomies()
		);
	}

	private static function count_sql( string $sql ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	private static function query_sql( string $sql ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $sql );
		return false === $result ? 0 : (int) $wpdb->rows_affected;
	}
}
