<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Migration;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Imports Polylang's taxonomy-backed language relationships into CodeOn.
 *
 * Polylang stores language assignment and translation groups through normal
 * WordPress taxonomy tables. That maps cleanly to CodeOn's post/term companion
 * tables, but its registered string store is a separate MO-like system and is
 * intentionally reported instead of silently guessed.
 */
final class PolylangImporter {

	private const TAX_LANGUAGE          = 'language';
	private const TAX_TERM_LANGUAGE     = 'term_language';
	private const TAX_POST_TRANSLATIONS = 'post_translations';
	private const TAX_TERM_TRANSLATIONS = 'term_translations';

	/**
	 * @return array{language_settings:int,post_mappings:int,term_mappings:int,string_translations:int}
	 */
	private static function empty_conflicts(): array {
		return array(
			'language_settings'   => 0,
			'post_mappings'       => 0,
			'term_mappings'       => 0,
			'string_translations' => 0,
		);
	}

	public static function is_available(): bool {
		return self::taxonomy_has_rows( self::TAX_LANGUAGE );
	}

	public static function is_detected(): bool {
		return self::taxonomy_has_rows( self::TAX_LANGUAGE )
			|| function_exists( 'pll_languages_list' );
	}

	/**
	 * @return array{languages:int,posts:int,terms:int,strings:int,translated_strings:int,default_language:?string,conflicts:array{language_settings:int,post_mappings:int,term_mappings:int,string_translations:int},warnings:array<int,string>}
	 */
	public static function summary(): array {
		if ( ! self::is_available() ) {
			return array(
				'languages'          => 0,
				'posts'              => 0,
				'terms'              => 0,
				'strings'            => 0,
				'translated_strings' => 0,
				'default_language'   => null,
				'conflicts'          => self::empty_conflicts(),
				'warnings'           => array(),
			);
		}

		return array(
			'languages'          => count( self::language_rows() ),
			'posts'              => self::count_source_rows( self::post_mapping_select_sql() ),
			'terms'              => self::count_source_rows( self::term_mapping_select_sql() ),
			'strings'            => self::polylang_string_posts_count(),
			'translated_strings' => 0,
			'default_language'   => self::default_language(),
			'conflicts'          => self::conflict_summary(),
			'warnings'           => self::warnings(),
		);
	}

	/**
	 * @return array{language_settings:int,post_mappings:int,term_mappings:int,string_translations:int}
	 */
	public static function conflict_summary(): array {
		if ( ! self::is_available() ) {
			return self::empty_conflicts();
		}

		return array(
			'language_settings'   => self::language_conflicts_count(),
			'post_mappings'       => self::mapping_conflicts_count(
				self::post_mapping_select_sql(),
				'cml_post_language',
				'post_id'
			),
			'term_mappings'       => self::mapping_conflicts_count(
				self::term_mapping_select_sql(),
				'cml_term_language',
				'term_id'
			),
			'string_translations' => 0,
		);
	}

	/**
	 * @return array{languages:int,posts:int,terms:int,strings:int,translated_strings:int,default_set:bool,conflicts:array{language_settings:int,post_mappings:int,term_mappings:int,string_translations:int},warnings:array<int,string>,errors:array<int,string>}
	 */
	public static function import_all( bool $allow_conflicts = false ): array {
		$result = array(
			'languages'          => 0,
			'posts'              => 0,
			'terms'              => 0,
			'strings'            => 0,
			'translated_strings' => 0,
			'default_set'        => false,
			'conflicts'          => self::conflict_summary(),
			'warnings'           => self::warnings(),
			'errors'             => array(),
		);

		if ( ! self::is_available() ) {
			$result['errors'][] = 'polylang: no Polylang language taxonomy data found.';
			return $result;
		}

		if ( ! $allow_conflicts && self::has_conflicts( $result['conflicts'] ) ) {
			$result['errors'][] = 'conflicts: existing CodeOn data differs from Polylang data. Review the preflight report before importing.';
			return $result;
		}

		self::begin_transaction();
		try {
			$result['languages'] = self::import_languages();
			Languages::flush_cache();
			if ( ! $allow_conflicts || 0 === $result['conflicts']['language_settings'] ) {
				$result['default_set'] = self::set_default_language();
			}
			$result['posts'] = self::import_post_translations();
			$result['terms'] = self::import_term_translations();
			self::commit_transaction();
		} catch ( \Throwable $e ) {
			self::rollback_transaction();
			$result['errors'][] = $e->getMessage();
		}

		if ( empty( $result['errors'] ) ) {
			Languages::flush_cache();
			StringTranslator::flush_cache();
			TranslationGroups::flush();
			self::notify_imported( $result );
		}

		return $result;
	}

	public static function import_languages(): int {
		global $wpdb;

		$count = 0;
		foreach ( self::language_rows() as $row ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}cml_languages WHERE code = %s",
					$row['code']
				)
			);
			if ( $exists > 0 ) {
				continue;
			}

			$inserted = $wpdb->insert(
				$wpdb->prefix . 'cml_languages',
				$row,
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
			);
			if ( false === $inserted ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is returned to escaped admin/CLI callers.
				throw new \RuntimeException( 'languages: ' . ( $wpdb->last_error ?: 'database insert failed' ) );
			}
			++$count;
		}

		return $count;
	}

	public static function set_default_language(): bool {
		global $wpdb;

		$code = self::default_language();
		if ( null === $code || ! Languages::exists( $code ) ) {
			return false;
		}

		$wpdb->update(
			$wpdb->prefix . 'cml_languages',
			array( 'is_default' => 0 ),
			array( 'is_default' => 1 ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $wpdb->update(
			$wpdb->prefix . 'cml_languages',
			array( 'is_default' => 1 ),
			array( 'code' => $code ),
			array( '%d' ),
			array( '%s' )
		);
	}

	public static function import_post_translations(): int {
		global $wpdb;

		if ( ! self::taxonomy_has_rows( self::TAX_LANGUAGE ) ) {
			return 0;
		}

		$count = self::replace_placeholder_mappings(
			self::post_mapping_select_sql(),
			'cml_post_language',
			'post_id',
			'post translations'
		);

		$sql = "INSERT IGNORE INTO {$wpdb->prefix}cml_post_language (post_id, group_id, language)
			" . self::post_mapping_select_sql();
		self::query_or_throw( $sql, 'post translations' );

		return $count + (int) $wpdb->rows_affected;
	}

	public static function import_term_translations(): int {
		global $wpdb;

		if ( ! self::taxonomy_has_rows( self::TAX_TERM_LANGUAGE ) ) {
			return 0;
		}

		$count = self::replace_placeholder_mappings(
			self::term_mapping_select_sql(),
			'cml_term_language',
			'term_id',
			'term translations'
		);

		$sql = "INSERT IGNORE INTO {$wpdb->prefix}cml_term_language (term_id, group_id, language)
			" . self::term_mapping_select_sql();
		self::query_or_throw( $sql, 'term translations' );

		return $count + (int) $wpdb->rows_affected;
	}

	/**
	 * @return array<int,array{code:string,locale:string,name:string,native:string,flag:string,rtl:int,active:int,is_default:int,position:int}>
	 */
	private static function language_rows(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT t.slug, t.name, tt.description
			 FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE tt.taxonomy = 'language'
			 ORDER BY t.term_group ASC, t.term_id ASC"
		);

		$default  = self::default_language();
		$position = 0;
		$result   = array();

		foreach ( $rows as $row ) {
			$description = is_string( $row->description ?? null ) ? (string) $row->description : '';
			$props       = self::maybe_unserialize_array( $description );
			$code        = self::normalize_code( (string) ( $row->slug ?? '' ) );
			if ( '' === $code ) {
				continue;
			}

			$name = (string) ( $props['name'] ?? $props['english_name'] ?? $row->name ?? strtoupper( $code ) );

			$result[] = array(
				'code'       => $code,
				'locale'     => (string) ( $props['locale'] ?? $code ),
				'name'       => $name,
				'native'     => (string) ( $row->name ?? $props['native_name'] ?? $name ),
				'flag'       => self::normalize_code( (string) ( $props['flag_code'] ?? $code ) ),
				'rtl'        => empty( $props['rtl'] ) ? 0 : 1,
				'active'     => 1,
				'is_default' => $default === $code ? 1 : 0,
				'position'   => $position,
			);
			++$position;
		}

		return $result;
	}

	private static function post_mapping_select_sql(): string {
		global $wpdb;

		$tax_language = self::TAX_LANGUAGE;
		$tax_group    = self::TAX_POST_TRANSLATIONS;

		return "SELECT
				p.ID AS post_id,
				COALESCE(MIN(tt_group.term_taxonomy_id), p.ID) AS group_id,
				lang.slug AS language
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr_lang ON tr_lang.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt_lang
				ON tt_lang.term_taxonomy_id = tr_lang.term_taxonomy_id
				AND tt_lang.taxonomy = '{$tax_language}'
			INNER JOIN {$wpdb->terms} lang ON lang.term_id = tt_lang.term_id
			LEFT JOIN {$wpdb->term_relationships} tr_group ON tr_group.object_id = p.ID
			LEFT JOIN {$wpdb->term_taxonomy} tt_group
				ON tt_group.term_taxonomy_id = tr_group.term_taxonomy_id
				AND tt_group.taxonomy = '{$tax_group}'
			WHERE p.post_type != 'revision'
				AND p.post_status NOT IN ('trash', 'auto-draft')
			GROUP BY p.ID, lang.slug";
	}

	private static function term_mapping_select_sql(): string {
		global $wpdb;

		$tax_language = self::TAX_TERM_LANGUAGE;
		$tax_group    = self::TAX_TERM_TRANSLATIONS;

		return "SELECT
				real_term.term_id AS term_id,
				COALESCE(MIN(tt_group.term_taxonomy_id), real_term.term_id) AS group_id,
				lang.slug AS language
			FROM {$wpdb->terms} real_term
			INNER JOIN {$wpdb->term_taxonomy} real_tt
				ON real_tt.term_id = real_term.term_id
				AND real_tt.taxonomy NOT IN ('language', 'term_language', 'post_translations', 'term_translations')
			INNER JOIN {$wpdb->term_relationships} tr_lang ON tr_lang.object_id = real_term.term_id
			INNER JOIN {$wpdb->term_taxonomy} tt_lang
				ON tt_lang.term_taxonomy_id = tr_lang.term_taxonomy_id
				AND tt_lang.taxonomy = '{$tax_language}'
			INNER JOIN {$wpdb->terms} lang ON lang.term_id = tt_lang.term_id
			LEFT JOIN {$wpdb->term_relationships} tr_group ON tr_group.object_id = real_term.term_id
			LEFT JOIN {$wpdb->term_taxonomy} tt_group
				ON tt_group.term_taxonomy_id = tr_group.term_taxonomy_id
				AND tt_group.taxonomy = '{$tax_group}'
			GROUP BY real_term.term_id, lang.slug";
	}

	private static function count_source_rows( string $select_sql ): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM ({$select_sql}) cml_polylang_source" );
	}

	private static function language_conflicts_count(): int {
		global $wpdb;

		$count = 0;
		foreach ( self::language_rows() as $row ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT locale, name, native, active, is_default
					 FROM {$wpdb->prefix}cml_languages
					 WHERE code = %s",
					$row['code']
				)
			);

			if ( ! is_object( $existing ) ) {
				continue;
			}

			if (
				(string) $existing->locale !== $row['locale']
				|| (string) $existing->name !== $row['name']
				|| (string) $existing->native !== $row['native']
				|| (int) $existing->active !== $row['active']
				|| (int) $existing->is_default !== $row['is_default']
			) {
				++$count;
			}
		}

		return $count;
	}

	private static function mapping_conflicts_count( string $select_sql, string $suffix, string $id_column ): int {
		global $wpdb;

		$table = $wpdb->prefix . $suffix;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL fragments are generated internally from fixed importer queries and validated column names.
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM ({$select_sql}) source_rows
			 INNER JOIN {$table} cml ON cml.{$id_column} = source_rows.{$id_column}
			 WHERE (cml.group_id != source_rows.group_id
				OR cml.language != source_rows.language)
			   AND NOT (" . self::placeholder_mapping_predicate( 'cml', $id_column ) . ')'
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return $count;
	}

	private static function replace_placeholder_mappings( string $select_sql, string $suffix, string $id_column, string $label ): int {
		global $wpdb;

		$table = $wpdb->prefix . $suffix;
		$sql   = "UPDATE {$table} cml
			INNER JOIN ({$select_sql}) source_rows ON source_rows.{$id_column} = cml.{$id_column}
			SET cml.group_id = source_rows.group_id,
				cml.language = source_rows.language
			WHERE " . self::placeholder_mapping_predicate( 'cml', $id_column ) . '
			  AND (cml.group_id != source_rows.group_id OR cml.language != source_rows.language)';

		self::query_or_throw( $sql, $label . ' placeholder replacements' );
		return (int) $wpdb->rows_affected;
	}

	private static function placeholder_mapping_predicate( string $alias, string $id_column ): string {
		return "{$alias}.group_id = {$alias}.{$id_column}
			AND {$alias}.language = COALESCE((" . self::default_language_subquery() . "), '')";
	}

	private static function default_language_subquery(): string {
		global $wpdb;
		return "SELECT code FROM {$wpdb->prefix}cml_languages WHERE is_default = 1 ORDER BY position ASC, code ASC LIMIT 1";
	}

	private static function polylang_string_posts_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'polylang_mo'"
		);
	}

	/**
	 * @return array<int,string>
	 */
	private static function warnings(): array {
		$warnings = array();

		if ( self::polylang_string_posts_count() > 0 ) {
			$warnings[] = 'Polylang string translations are stored as polylang_mo data. Object relationships can be imported now; string import needs a dedicated MO adapter or PO export/import.';
		}

		return $warnings;
	}

	private static function default_language(): ?string {
		if ( function_exists( 'pll_default_language' ) ) {
			$default = pll_default_language( 'slug' );
			if ( is_string( $default ) && '' !== $default ) {
				return self::normalize_code( $default );
			}
		}

		$options = get_option( 'polylang' );
		if ( is_array( $options ) ) {
			$default = $options['default_lang'] ?? $options['default_language'] ?? null;
			if ( is_string( $default ) && '' !== $default ) {
				return self::normalize_code( $default );
			}
		}

		$rows = self::language_rows_without_default_lookup();
		if ( count( $rows ) > 0 ) {
			return $rows[0];
		}

		return null;
	}

	/**
	 * @return array<int,string>
	 */
	private static function language_rows_without_default_lookup(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT t.slug
			 FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE tt.taxonomy = 'language'
			 ORDER BY t.term_group ASC, t.term_id ASC"
		);

		return array_values(
			array_filter(
				array_map(
					static fn( $row ): string => self::normalize_code( (string) ( $row->slug ?? '' ) ),
					$rows
				)
			)
		);
	}

	private static function taxonomy_has_rows( string $taxonomy ): bool {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
				$taxonomy
			)
		) > 0;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function maybe_unserialize_array( string $value ): array {
		if ( '' === $value ) {
			return array();
		}

		if ( function_exists( 'maybe_unserialize' ) ) {
			$decoded = maybe_unserialize( $value );
		} else {
			$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	private static function normalize_code( string $code ): string {
		$code = strtolower( trim( $code ) );
		return (string) preg_replace( '/[^a-z0-9_-]/', '', $code );
	}

	/**
	 * @param array{language_settings:int,post_mappings:int,term_mappings:int,string_translations:int} $conflicts
	 */
	private static function has_conflicts( array $conflicts ): bool {
		return $conflicts['language_settings'] > 0
			|| $conflicts['post_mappings'] > 0
			|| $conflicts['term_mappings'] > 0
			|| $conflicts['string_translations'] > 0;
	}

	private static function begin_transaction(): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	private static function commit_transaction(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	private static function rollback_transaction(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	private static function query_or_throw( string $sql, string $label ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is fixed internally and contains no request data.
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is returned to escaped admin/CLI callers.
			throw new \RuntimeException( $label . ': ' . ( $wpdb->last_error ?: 'database query failed' ) );
		}
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private static function notify_imported( array $result ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( 'cml_migration_imported', 'polylang', $result );
		}
	}
}
