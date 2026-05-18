<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

/**
 * Read-only production health report over CodeOn Multilingual data stores.
 *
 * The report deliberately avoids mutation. Admin actions that repair state live
 * on the Health page and are limited to safe cache flushing for the first pass.
 */
final class HealthReport {

	public const STATUS_OK       = 'ok';
	public const STATUS_INFO     = 'info';
	public const STATUS_WARNING  = 'warning';
	public const STATUS_CRITICAL = 'critical';

	private const SAMPLE_LIMIT = 10;

	/**
	 * @return array{
	 *   generated_at:int,
	 *   status:string,
	 *   summary:array{critical:int,warning:int,info:int,ok:int},
	 *   sections:array<string, array{title:string,status:string,checks:array<int,array<string,mixed>>,meta:array<string,mixed>}>
	 * }
	 */
	public static function generate(): array {
		$languages = self::language_section();
		$posts     = self::post_mapping_section( (string) $languages['meta']['default_code'] );
		$terms     = self::term_mapping_section( (string) $languages['meta']['default_code'] );
		$strings   = self::strings_section();
		$cache     = self::cache_section( $languages['meta'] );

		$sections = array(
			'languages' => $languages,
			'posts'     => $posts,
			'terms'     => $terms,
			'strings'   => $strings,
			'cache'     => $cache,
		);
		$summary  = self::summary_for_sections( $sections );
		$status   = self::status_from_counts( $summary['critical'], $summary['warning'] );

		Diagnostics::debug(
			'Health report generated',
			array(
				'status'   => $status,
				'critical' => $summary['critical'],
				'warning'  => $summary['warning'],
			)
		);

		return array(
			'generated_at' => time(),
			'status'       => $status,
			'summary'      => $summary,
			'sections'     => $sections,
		);
	}

	/**
	 * Pure language-row inspection, split out for tests and CLI consumers.
	 *
	 * @param array<int, object|array<string,mixed>> $rows
	 * @return array{
	 *   total:int,
	 *   invalid_rows:array<int,array{code:string,locale:string,reasons:array<int,string>}>,
	 *   default_rows:array<int,string>,
	 *   missing_default:bool,
	 *   multiple_defaults:bool,
	 *   inactive_defaults:array<int,string>,
	 *   duplicate_codes:array<string,int>,
	 *   duplicate_locales:array<string,int>
	 * }
	 */
	public static function inspect_language_rows( array $rows ): array {
		$invalid           = array();
		$default_rows      = array();
		$inactive_defaults = array();
		$codes             = array();
		$locales           = array();

		foreach ( $rows as $row ) {
			$code       = trim( (string) self::row_value( $row, 'code' ) );
			$locale     = trim( (string) self::row_value( $row, 'locale' ) );
			$name       = trim( (string) self::row_value( $row, 'name' ) );
			$native     = trim( (string) self::row_value( $row, 'native' ) );
			$active     = self::row_value( $row, 'active' );
			$is_default = self::row_value( $row, 'is_default' );
			$reasons    = array();

			if ( ! self::is_valid_language_code( $code ) ) {
				$reasons[] = 'invalid_code';
			}
			if ( ! self::is_valid_locale( $locale ) ) {
				$reasons[] = 'invalid_locale';
			}
			if ( '' === $name ) {
				$reasons[] = 'missing_name';
			}
			if ( '' === $native ) {
				$reasons[] = 'missing_native';
			}
			if ( ! self::is_binary_flag( $active ) ) {
				$reasons[] = 'invalid_active_flag';
			}
			if ( ! self::is_binary_flag( $is_default ) ) {
				$reasons[] = 'invalid_default_flag';
			}

			if ( '1' === (string) $is_default ) {
				$default_rows[] = $code;
				if ( '1' !== (string) $active ) {
					$inactive_defaults[] = $code;
				}
			}

			if ( '' !== $code ) {
				$key           = strtolower( $code );
				$codes[ $key ] = ( $codes[ $key ] ?? 0 ) + 1;
			}
			if ( '' !== $locale ) {
				$key             = strtolower( $locale );
				$locales[ $key ] = ( $locales[ $key ] ?? 0 ) + 1;
			}

			if ( array() !== $reasons ) {
				$invalid[] = array(
					'code'    => $code,
					'locale'  => $locale,
					'reasons' => $reasons,
				);
			}
		}

		return array(
			'total'             => count( $rows ),
			'invalid_rows'      => $invalid,
			'default_rows'      => $default_rows,
			'missing_default'   => array() === $default_rows,
			'multiple_defaults' => count( $default_rows ) > 1,
			'inactive_defaults' => $inactive_defaults,
			'duplicate_codes'   => self::only_duplicates( $codes ),
			'duplicate_locales' => self::only_duplicates( $locales ),
		);
	}

	public static function status_from_counts( int $critical, int $warning ): string {
		if ( $critical > 0 ) {
			return self::STATUS_CRITICAL;
		}
		if ( $warning > 0 ) {
			return self::STATUS_WARNING;
		}
		return self::STATUS_OK;
	}

	/**
	 * @return array{title:string,status:string,checks:array<int,array<string,mixed>>,meta:array<string,mixed>}
	 */
	private static function language_section(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'cml_languages';
		if ( ! self::table_exists( $table ) ) {
			return self::section(
				'Languages',
				array(
					self::check(
						'missing_languages_table',
						'Languages table exists',
						self::STATUS_CRITICAL,
						1,
						"Missing table {$table}."
					),
				),
				array(
					'default_code' => '',
					'rows'         => array(),
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT code, locale, name, native, active, is_default, position FROM {$table} ORDER BY position ASC, code ASC" );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$inspection   = self::inspect_language_rows( $rows );
		$default_code = '' !== ( $inspection['default_rows'][0] ?? '' ) ? (string) $inspection['default_rows'][0] : '';
		$checks       = array(
			self::count_check(
				'language_rows_present',
				'Language rows present',
				$inspection['total'] > 0 ? 0 : 1,
				self::STATUS_CRITICAL,
				'At least one language row exists.',
				'No languages are configured.'
			),
			self::issue_check(
				'broken_language_rows',
				'Broken language rows',
				count( $inspection['invalid_rows'] ),
				self::STATUS_CRITICAL,
				'All language rows have valid codes, locales, names, and flags.',
				'One or more language rows contain invalid or missing values.',
				$inspection['invalid_rows']
			),
			self::count_check(
				'missing_default_language',
				'Default language exists',
				$inspection['missing_default'] ? 1 : 0,
				self::STATUS_CRITICAL,
				'Exactly one default language is configured.',
				'No language row is marked as default.'
			),
			self::issue_check(
				'multiple_default_languages',
				'Only one default language',
				$inspection['multiple_defaults'] ? count( $inspection['default_rows'] ) : 0,
				self::STATUS_CRITICAL,
				'Only one language row is marked as default.',
				'Multiple language rows are marked as default.',
				$inspection['default_rows']
			),
			self::issue_check(
				'inactive_default_language',
				'Default language is active',
				count( $inspection['inactive_defaults'] ),
				self::STATUS_WARNING,
				'The default language is active.',
				'The default language is inactive, which can hide default-language content.',
				$inspection['inactive_defaults']
			),
			self::issue_check(
				'duplicate_language_codes',
				'Duplicate language slugs',
				count( $inspection['duplicate_codes'] ),
				self::STATUS_CRITICAL,
				'Language slugs are unique.',
				'Duplicate language slugs were found.',
				$inspection['duplicate_codes']
			),
			self::issue_check(
				'duplicate_language_locales',
				'Duplicate locales',
				count( $inspection['duplicate_locales'] ),
				self::STATUS_WARNING,
				'Locales are unique.',
				'Multiple languages share the same WordPress locale.',
				$inspection['duplicate_locales']
			),
		);

		return self::section(
			'Languages',
			$checks,
			array(
				'default_code' => $default_code,
				'rows'         => $rows,
			)
		);
	}

	/**
	 * @return array{title:string,status:string,checks:array<int,array<string,mixed>>,meta:array<string,mixed>}
	 */
	private static function post_mapping_section( string $default_code ): array {
		global $wpdb;

		$map_table = $wpdb->prefix . 'cml_post_language';
		if ( ! self::table_exists( $map_table ) ) {
			return self::missing_mapping_section( 'Post translation groups', $map_table );
		}

		$checks = array(
			self::count_check(
				'posts_missing_language_rows',
				'Posts missing language rows',
				self::count_posts_missing_language_rows(),
				self::STATUS_WARNING,
				'All non-trash posts have a language row.',
				'Some non-trash posts are missing cml_post_language rows.',
				self::sample_posts_missing_language_rows()
			),
			self::count_check(
				'orphaned_post_language_rows',
				'Orphaned post language rows',
				self::count_sql( "SELECT COUNT(*) FROM {$map_table} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL" ),
				self::STATUS_WARNING,
				'Every post language row points to an existing post.',
				'Some post language rows point to deleted posts.',
				self::rows_sql( "SELECT m.post_id, m.group_id, m.language FROM {$map_table} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL ORDER BY m.post_id ASC LIMIT " . self::SAMPLE_LIMIT )
			),
			self::count_check(
				'post_rows_unknown_language',
				'Post rows reference configured languages',
				self::count_unknown_language_rows( $map_table, 'language' ),
				self::STATUS_WARNING,
				'Every post language row references a configured language.',
				'Some post language rows reference missing languages.',
				self::sample_unknown_language_rows( $map_table, 'post_id' )
			),
			self::count_check(
				'invalid_post_translation_groups',
				'Post translation group ids are valid',
				self::count_sql( "SELECT COUNT(*) FROM {$map_table} WHERE post_id <= 0 OR group_id <= 0 OR language = ''" ),
				self::STATUS_WARNING,
				'Every post language row has a positive post_id, positive group_id, and non-empty language.',
				'Some post language rows contain invalid ids or empty languages.',
				self::rows_sql( "SELECT post_id, group_id, language FROM {$map_table} WHERE post_id <= 0 OR group_id <= 0 OR language = '' ORDER BY post_id ASC LIMIT " . self::SAMPLE_LIMIT )
			),
			self::count_check(
				'duplicate_post_group_languages',
				'Post groups have one row per language',
				self::count_duplicate_group_languages( $map_table ),
				self::STATUS_WARNING,
				'Each post translation group has at most one sibling per language.',
				'Some post translation groups contain duplicate siblings for the same language.',
				self::sample_duplicate_group_languages( $map_table )
			),
			self::default_sibling_check( $map_table, 'post_groups_missing_default_sibling', 'Post groups include the default-language sibling', $default_code ),
		);

		return self::section( 'Post translation groups', $checks );
	}

	/**
	 * @return array{title:string,status:string,checks:array<int,array<string,mixed>>,meta:array<string,mixed>}
	 */
	private static function term_mapping_section( string $default_code ): array {
		global $wpdb;

		$map_table = $wpdb->prefix . 'cml_term_language';
		if ( ! self::table_exists( $map_table ) ) {
			return self::missing_mapping_section( 'Term translation groups', $map_table );
		}

		$checks = array(
			self::count_check(
				'terms_missing_language_rows',
				'Terms missing language rows',
				self::count_terms_missing_language_rows(),
				self::STATUS_WARNING,
				'All terms have a language row.',
				'Some terms are missing cml_term_language rows.',
				self::sample_terms_missing_language_rows()
			),
			self::count_check(
				'orphaned_term_language_rows',
				'Orphaned term language rows',
				self::count_sql( "SELECT COUNT(*) FROM {$map_table} m LEFT JOIN {$wpdb->terms} t ON t.term_id = m.term_id WHERE t.term_id IS NULL" ),
				self::STATUS_WARNING,
				'Every term language row points to an existing term.',
				'Some term language rows point to deleted terms.',
				self::rows_sql( "SELECT m.term_id, m.group_id, m.language FROM {$map_table} m LEFT JOIN {$wpdb->terms} t ON t.term_id = m.term_id WHERE t.term_id IS NULL ORDER BY m.term_id ASC LIMIT " . self::SAMPLE_LIMIT )
			),
			self::count_check(
				'term_rows_unknown_language',
				'Term rows reference configured languages',
				self::count_unknown_language_rows( $map_table, 'language' ),
				self::STATUS_WARNING,
				'Every term language row references a configured language.',
				'Some term language rows reference missing languages.',
				self::sample_unknown_language_rows( $map_table, 'term_id' )
			),
			self::count_check(
				'invalid_term_translation_groups',
				'Term translation group ids are valid',
				self::count_sql( "SELECT COUNT(*) FROM {$map_table} WHERE term_id <= 0 OR group_id <= 0 OR language = ''" ),
				self::STATUS_WARNING,
				'Every term language row has a positive term_id, positive group_id, and non-empty language.',
				'Some term language rows contain invalid ids or empty languages.',
				self::rows_sql( "SELECT term_id, group_id, language FROM {$map_table} WHERE term_id <= 0 OR group_id <= 0 OR language = '' ORDER BY term_id ASC LIMIT " . self::SAMPLE_LIMIT )
			),
			self::count_check(
				'duplicate_term_group_languages',
				'Term groups have one row per language',
				self::count_duplicate_group_languages( $map_table ),
				self::STATUS_WARNING,
				'Each term translation group has at most one sibling per language.',
				'Some term translation groups contain duplicate siblings for the same language.',
				self::sample_duplicate_group_languages( $map_table )
			),
			self::default_sibling_check( $map_table, 'term_groups_missing_default_sibling', 'Term groups include the default-language sibling', $default_code ),
		);

		return self::section( 'Term translation groups', $checks );
	}

	/**
	 * @return array{title:string,status:string,checks:array<int,array<string,mixed>>,meta:array<string,mixed>}
	 */
	private static function strings_section(): array {
		global $wpdb;

		$strings_table      = $wpdb->prefix . 'cml_strings';
		$translations_table = $wpdb->prefix . 'cml_string_translations';
		if ( ! self::table_exists( $strings_table ) || ! self::table_exists( $translations_table ) ) {
			return self::section(
				'String catalog',
				array(
					self::check(
						'missing_string_tables',
						'String catalog tables exist',
						self::STATUS_CRITICAL,
						1,
						'One or more string catalog tables are missing.'
					),
				)
			);
		}

		$l10n_health = function_exists( 'get_option' ) ? get_option( 'cml_l10n_health' ) : false;
		$l10n_count  = is_array( $l10n_health ) && empty( $l10n_health['ok'] ) ? 1 : 0;

		$checks = array(
			self::count_check(
				'orphaned_string_translations',
				'String translations point to source strings',
				self::count_sql( "SELECT COUNT(*) FROM {$translations_table} st LEFT JOIN {$strings_table} s ON s.id = st.string_id WHERE s.id IS NULL" ),
				self::STATUS_WARNING,
				'Every string translation points to an existing source string.',
				'Some string translations point to missing source strings.',
				self::rows_sql( "SELECT st.string_id, st.language FROM {$translations_table} st LEFT JOIN {$strings_table} s ON s.id = st.string_id WHERE s.id IS NULL ORDER BY st.string_id ASC LIMIT " . self::SAMPLE_LIMIT )
			),
			self::count_check(
				'string_translations_unknown_language',
				'String translations reference configured languages',
				self::count_unknown_language_rows( $translations_table, 'language' ),
				self::STATUS_WARNING,
				'Every string translation references a configured language.',
				'Some string translations reference missing languages.',
				self::sample_unknown_language_rows( $translations_table, 'string_id' )
			),
			self::count_check(
				'strings_unknown_source_language',
				'Source strings reference configured source languages',
				self::count_unknown_language_rows( $strings_table, 'source_language' ),
				self::STATUS_INFO,
				'Every source string has a configured source language.',
				'Some source strings have source_language values that are not configured languages.',
				self::sample_unknown_language_rows( $strings_table, 'id', 'source_language' )
			),
			self::issue_check(
				'l10n_file_health',
				'Native .l10n.php writer health',
				$l10n_count,
				self::STATUS_WARNING,
				'No native .l10n.php writer failure is recorded.',
				'A previous native .l10n.php write failure is recorded.',
				is_array( $l10n_health ) ? $l10n_health : array()
			),
		);

		return self::section( 'String catalog', $checks );
	}

	/**
	 * @param array<string,mixed> $language_meta
	 * @return array{title:string,status:string,checks:array<int,array<string,mixed>>,meta:array<string,mixed>}
	 */
	private static function cache_section( array $language_meta ): array {
		global $wpdb;

		if ( ! function_exists( 'wp_cache_get' ) ) {
			return self::section(
				'Runtime caches',
				array(
					self::check(
						'object_cache_unavailable',
						'Object cache API is available',
						self::STATUS_INFO,
						1,
						'wp_cache_get() is unavailable in this runtime.'
					),
				)
			);
		}

		$checks = array();
		$rows   = isset( $language_meta['rows'] ) && is_array( $language_meta['rows'] ) ? $language_meta['rows'] : array();
		$codes  = array();
		foreach ( $rows as $row ) {
			$code = (string) self::row_value( $row, 'code' );
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}
		sort( $codes );

		$languages_cache = self::cache_probe( 'languages_all_v1', 'cml' );
		if ( $languages_cache['found'] && is_array( $languages_cache['value'] ) ) {
			$cached_codes = array_keys( $languages_cache['value'] );
			sort( $cached_codes );
			$checks[] = self::count_check(
				'languages_cache_stale',
				'Languages object cache matches the database',
				$cached_codes === $codes ? 0 : 1,
				self::STATUS_WARNING,
				'The languages object cache has the same language codes as the database.',
				'The languages object cache differs from the language table.',
				array(
					'database' => $codes,
					'cache'    => $cached_codes,
				)
			);
		} else {
			$checks[] = self::check(
				'languages_cache_present',
				'Languages object cache entry',
				self::STATUS_INFO,
				0,
				'No languages cache entry is currently stored.'
			);
		}

		$strings_table = $wpdb->prefix . 'cml_strings';
		if ( self::table_exists( $strings_table ) ) {
			$count       = self::count_sql( "SELECT COUNT(*) FROM {$strings_table}" );
			$known_cache = self::cache_probe( 'known_hashes', 'cml_strings' );
			if ( $known_cache['found'] && is_array( $known_cache['value'] ) ) {
				$checks[] = self::count_check(
					'known_strings_cache_stale',
					'Known string cache count matches the database',
					count( $known_cache['value'] ) === $count ? 0 : 1,
					self::STATUS_WARNING,
					'The known string cache count matches the source string table.',
					'The known string cache count differs from the source string table.',
					array(
						'database_count' => $count,
						'cache_count'    => count( $known_cache['value'] ),
					)
				);
			}
		}

		$translations_table = $wpdb->prefix . 'cml_string_translations';
		if ( self::table_exists( $translations_table ) ) {
			foreach ( $codes as $code ) {
				$compiled_cache = self::cache_probe( 'compiled_' . $code, 'cml_strings' );
				if ( ! $compiled_cache['found'] || ! is_array( $compiled_cache['value'] ) ) {
					continue;
				}
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$db_count = self::count_sql(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$translations_table} WHERE language = %s",
						$code
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$checks[] = self::count_check(
					'compiled_strings_cache_stale_' . $code,
					"Compiled string cache matches database ({$code})",
					count( $compiled_cache['value'] ) === $db_count ? 0 : 1,
					self::STATUS_WARNING,
					"The compiled string cache for {$code} matches the database.",
					"The compiled string cache for {$code} differs from the database.",
					array(
						'language'       => $code,
						'database_count' => $db_count,
						'cache_count'    => count( $compiled_cache['value'] ),
					)
				);
			}
		}

		return self::section( 'Runtime caches', $checks );
	}

	/**
	 * @return array{title:string,status:string,checks:array<int,array<string,mixed>>,meta:array<string,mixed>}
	 */
	private static function missing_mapping_section( string $title, string $table ): array {
		return self::section(
			$title,
			array(
				self::check(
					'missing_mapping_table',
					"{$title} table exists",
					self::STATUS_CRITICAL,
					1,
					"Missing table {$table}."
				),
			)
		);
	}

	private static function count_posts_missing_language_rows(): int {
		global $wpdb;
		$map_table = $wpdb->prefix . 'cml_post_language';

		return self::count_sql(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$map_table} m ON m.post_id = p.ID
			 WHERE p.post_type <> 'revision'
			   AND p.post_status NOT IN ('auto-draft', 'trash')
			   AND m.post_id IS NULL"
		);
	}

	/**
	 * @return array<int, object>
	 */
	private static function sample_posts_missing_language_rows(): array {
		global $wpdb;
		$map_table = $wpdb->prefix . 'cml_post_language';

		return self::rows_sql(
			"SELECT p.ID AS post_id, p.post_type, p.post_status, p.post_title
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$map_table} m ON m.post_id = p.ID
			 WHERE p.post_type <> 'revision'
			   AND p.post_status NOT IN ('auto-draft', 'trash')
			   AND m.post_id IS NULL
			 ORDER BY p.ID ASC
			 LIMIT " . self::SAMPLE_LIMIT
		);
	}

	private static function count_terms_missing_language_rows(): int {
		global $wpdb;
		$map_table = $wpdb->prefix . 'cml_term_language';

		return self::count_sql(
			"SELECT COUNT(*)
			 FROM {$wpdb->terms} t
			 LEFT JOIN {$map_table} m ON m.term_id = t.term_id
			 WHERE m.term_id IS NULL"
		);
	}

	/**
	 * @return array<int, object>
	 */
	private static function sample_terms_missing_language_rows(): array {
		global $wpdb;
		$map_table = $wpdb->prefix . 'cml_term_language';

		return self::rows_sql(
			"SELECT t.term_id, t.name, t.slug
			 FROM {$wpdb->terms} t
			 LEFT JOIN {$map_table} m ON m.term_id = t.term_id
			 WHERE m.term_id IS NULL
			 ORDER BY t.term_id ASC
			 LIMIT " . self::SAMPLE_LIMIT
		);
	}

	private static function count_unknown_language_rows( string $table, string $language_column ): int {
		global $wpdb;
		$languages = $wpdb->prefix . 'cml_languages';

		return self::count_sql(
			"SELECT COUNT(*)
			 FROM {$table} m
			 LEFT JOIN {$languages} l ON l.code = m.{$language_column}
			 WHERE m.{$language_column} <> '' AND l.code IS NULL"
		);
	}

	/**
	 * @return array<int, object>
	 */
	private static function sample_unknown_language_rows( string $table, string $id_column, string $language_column = 'language' ): array {
		global $wpdb;
		$languages = $wpdb->prefix . 'cml_languages';

		return self::rows_sql(
			"SELECT m.{$id_column}, m.{$language_column}
			 FROM {$table} m
			 LEFT JOIN {$languages} l ON l.code = m.{$language_column}
			 WHERE m.{$language_column} <> '' AND l.code IS NULL
			 ORDER BY m.{$id_column} ASC
			 LIMIT " . self::SAMPLE_LIMIT
		);
	}

	private static function count_duplicate_group_languages( string $table ): int {
		return self::count_sql(
			"SELECT COUNT(*) FROM (
				SELECT group_id, language
				FROM {$table}
				WHERE group_id > 0 AND language <> ''
				GROUP BY group_id, language
				HAVING COUNT(*) > 1
			) cml_duplicate_groups"
		);
	}

	/**
	 * @return array<int, object>
	 */
	private static function sample_duplicate_group_languages( string $table ): array {
		return self::rows_sql(
			"SELECT group_id, language, COUNT(*) AS row_count
			 FROM {$table}
			 WHERE group_id > 0 AND language <> ''
			 GROUP BY group_id, language
			 HAVING COUNT(*) > 1
			 ORDER BY row_count DESC, group_id ASC
			 LIMIT " . self::SAMPLE_LIMIT
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function default_sibling_check( string $table, string $key, string $title, string $default_code ): array {
		global $wpdb;

		if ( '' === $default_code ) {
			return self::check(
				$key,
				$title,
				self::STATUS_INFO,
				0,
				'Skipped because no default language is configured.'
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = self::count_sql(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT group_id
					FROM {$table}
					WHERE group_id > 0
					GROUP BY group_id
					HAVING SUM(CASE WHEN language = %s THEN 1 ELSE 0 END) = 0
				) cml_missing_default_groups",
				$default_code
			)
		);

		$samples = self::rows_sql(
			$wpdb->prepare(
				"SELECT group_id, COUNT(*) AS sibling_count
				 FROM {$table}
				 WHERE group_id > 0
				 GROUP BY group_id
				 HAVING SUM(CASE WHEN language = %s THEN 1 ELSE 0 END) = 0
				 ORDER BY sibling_count DESC, group_id ASC
				 LIMIT %d",
				$default_code,
				self::SAMPLE_LIMIT
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::count_check(
			$key,
			$title,
			$count,
			self::STATUS_WARNING,
			"Every translation group includes a {$default_code} sibling.",
			"Some translation groups are missing a {$default_code} sibling.",
			$samples
		);
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		$sql = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (string) $wpdb->get_var( $sql ) === $table;
	}

	private static function count_sql( string $sql ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @return array<int, object>
	 */
	private static function rows_sql( string $sql ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<int,array<string,mixed>> $checks
	 * @param array<string,mixed>           $meta
	 * @return array{title:string,status:string,checks:array<int,array<string,mixed>>,meta:array<string,mixed>}
	 */
	private static function section( string $title, array $checks, array $meta = array() ): array {
		$critical = 0;
		$warning  = 0;
		foreach ( $checks as $check ) {
			if ( self::STATUS_CRITICAL === $check['status'] ) {
				++$critical;
			} elseif ( self::STATUS_WARNING === $check['status'] ) {
				++$warning;
			}
		}

		return array(
			'title'  => $title,
			'status' => self::status_from_counts( $critical, $warning ),
			'checks' => $checks,
			'meta'   => $meta,
		);
	}

	/**
	 * @param array<string,array{checks:array<int,array<string,mixed>>}> $sections
	 * @return array{critical:int,warning:int,info:int,ok:int}
	 */
	private static function summary_for_sections( array $sections ): array {
		$summary = array(
			'critical' => 0,
			'warning'  => 0,
			'info'     => 0,
			'ok'       => 0,
		);

		foreach ( $sections as $section ) {
			foreach ( $section['checks'] as $check ) {
				$status = (string) $check['status'];
				if ( isset( $summary[ $status ] ) ) {
					++$summary[ $status ];
				}
			}
		}

		return $summary;
	}

	/**
	 * @param mixed $samples
	 * @return array<string,mixed>
	 */
	private static function issue_check( string $key, string $title, int $count, string $status, string $ok_message, string $issue_message, $samples = array() ): array {
		return self::check(
			$key,
			$title,
			$count > 0 ? $status : self::STATUS_OK,
			$count,
			$count > 0 ? $issue_message : $ok_message,
			$samples
		);
	}

	/**
	 * @param mixed $samples
	 * @return array<string,mixed>
	 */
	private static function count_check( string $key, string $title, int $count, string $status, string $ok_message, string $issue_message, $samples = array() ): array {
		return self::issue_check( $key, $title, $count, $status, $ok_message, $issue_message, $samples );
	}

	/**
	 * @param mixed $samples
	 * @return array<string,mixed>
	 */
	private static function check( string $key, string $title, string $status, int $count, string $message, $samples = array() ): array {
		return array(
			'key'     => $key,
			'title'   => $title,
			'status'  => $status,
			'count'   => $count,
			'message' => $message,
			'samples' => $samples,
		);
	}

	/**
	 * @param array<string,int> $counts
	 * @return array<string,int>
	 */
	private static function only_duplicates( array $counts ): array {
		return array_filter(
			$counts,
			static fn( int $count ): bool => $count > 1
		);
	}

	/**
	 * @param object|array<string,mixed> $row
	 * @return mixed
	 */
	private static function row_value( $row, string $key ) {
		if ( is_array( $row ) ) {
			return $row[ $key ] ?? null;
		}
		return is_object( $row ) ? ( $row->{$key} ?? null ) : null;
	}

	/**
	 * @return array{found:bool,value:mixed}
	 */
	private static function cache_probe( string $key, string $group ): array {
		$found = false;
		$value = wp_cache_get( $key, $group, false, $found );

		return array(
			'found' => (bool) $found,
			'value' => $value,
		);
	}

	private static function is_valid_language_code( string $code ): bool {
		return 1 === preg_match( '/^[a-z]{2,3}(-[a-z0-9]+)?$/', $code );
	}

	private static function is_valid_locale( string $locale ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_@.-]{2,32}$/', $locale )
			&& ! str_contains( $locale, '..' );
	}

	/**
	 * @param mixed $value
	 */
	private static function is_binary_flag( $value ): bool {
		return '0' === (string) $value || '1' === (string) $value;
	}
}
