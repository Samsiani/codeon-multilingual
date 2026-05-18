<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Migration;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Strings\L10nFileWriter;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * One-shot migration from WPML's icl_* tables into ours.
 *
 * Every step is one SQL statement using INSERT ... SELECT [ON DUPLICATE KEY UPDATE].
 * The database does the join + dedupe; no PHP iteration, no batching. WPML's
 * existing trid (translation group id) is reused as our group_id directly —
 * they have the same semantics.
 *
 * Idempotent: re-running imports rows that are still missing. Existing CodeOn
 * mappings/translations are never silently overwritten; conflicting rows are
 * reported by preflight and import_all() aborts unless the caller explicitly
 * allows importing around conflicts.
 *
 * Scope (v0.3): languages, post translations (incl. variations/attachments/menu
 * items), term translations, string sources, string translations. Out of scope:
 * icl_translation_status (workflow metadata), icl_string_packages (complex ACF/SEO
 * field blobs), per-CPT settings, currency configuration.
 */
final class WpmlImporter {

	private const STRING_STATUS_NEEDS_TRANSLATION = 10;

	// ---- Detection -------------------------------------------------------

	public static function is_available(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
	}

	/**
	 * @return array{languages:int, posts:int, terms:int, strings:int, translated_strings:int, default_language:?string, conflicts:array{language_settings:int,post_mappings:int,term_mappings:int,string_translations:int}}
	 */
	public static function summary(): array {
		global $wpdb;
		if ( ! self::is_available() ) {
			return array(
				'languages'          => 0,
				'posts'              => 0,
				'terms'              => 0,
				'strings'            => 0,
				'translated_strings' => 0,
				'default_language'   => null,
				'conflicts'          => self::empty_conflicts(),
			);
		}

		$prefix = $wpdb->prefix;

		$languages = self::table_exists( $prefix . 'icl_languages' )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}icl_languages" )
			: 0;

		$posts = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$prefix}icl_translations WHERE element_type LIKE 'post_%' AND element_id IS NOT NULL"
		);
		$terms = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$prefix}icl_translations WHERE element_type LIKE 'tax_%' AND element_id IS NOT NULL"
		);

		$strings = self::table_exists( $prefix . 'icl_strings' )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}icl_strings" )
			: 0;

		$translated_strings = self::table_exists( $prefix . 'icl_string_translations' )
			? (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$prefix}icl_string_translations WHERE status != %d",
					self::STRING_STATUS_NEEDS_TRANSLATION
				)
			)
			: 0;

		return array(
			'languages'          => $languages,
			'posts'              => $posts,
			'terms'              => $terms,
			'strings'            => $strings,
			'translated_strings' => $translated_strings,
			'default_language'   => self::default_language_from_options(),
			'conflicts'          => self::conflict_summary(),
		);
	}

	/**
	 * Count existing CodeOn rows that would disagree with WPML's source data.
	 *
	 * These are the rows that older importer versions overwrote with
	 * ON DUPLICATE KEY UPDATE. Production migration should surface them before
	 * writes so the operator can decide whether to reset CodeOn data, merge
	 * manually, or import only the missing rows.
	 *
	 * @return array{language_settings:int,post_mappings:int,term_mappings:int,string_translations:int}
	 */
	public static function conflict_summary(): array {
		global $wpdb;
		if ( ! self::is_available() ) {
			return self::empty_conflicts();
		}

		$prefix = $wpdb->prefix;

		$language_settings = 0;
		if ( self::table_exists( $prefix . 'icl_languages' ) ) {
			$native_select     = self::wpml_native_name_select();
			$language_settings = (int) $wpdb->get_var(
				"SELECT COUNT(*)
				 FROM {$prefix}icl_languages l
				 INNER JOIN {$prefix}cml_languages cl ON cl.code = l.code
				 WHERE cl.locale != COALESCE(l.default_locale, l.code)
				    OR cl.name != l.english_name
				    OR cl.native != {$native_select}
				    OR cl.active != COALESCE(l.active, 1)"
			);
		}

		$post_mappings = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$prefix}icl_translations t
			 INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id
			 INNER JOIN {$prefix}cml_post_language cpl ON cpl.post_id = t.element_id
			 WHERE t.element_type LIKE 'post_%'
			   AND t.element_id IS NOT NULL
			   AND (cpl.group_id != t.trid OR cpl.language != t.language_code)"
		);

		$term_mappings = 0;
		if ( self::table_exists( $prefix . 'icl_translations' ) ) {
			$term_mappings = (int) $wpdb->get_var(
				"SELECT COUNT(*)
				 FROM {$prefix}icl_translations t
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = t.element_id
				 INNER JOIN {$prefix}cml_term_language ctl ON ctl.term_id = tt.term_id
				 WHERE t.element_type LIKE 'tax_%'
				   AND t.element_id IS NOT NULL
				   AND (ctl.group_id != t.trid OR ctl.language != t.language_code)"
			);
		}

		$string_translations = 0;
		if (
			self::table_exists( $prefix . 'icl_strings' )
			&& self::table_exists( $prefix . 'icl_string_translations' )
		) {
			$string_translations = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM {$prefix}icl_string_translations t
					 INNER JOIN {$prefix}icl_strings src ON src.id = t.string_id
					 INNER JOIN {$prefix}cml_strings cs ON cs.hash = UNHEX(MD5(CONCAT(
						COALESCE(src.context, ''),
						'|',
						COALESCE(src.gettext_context, ''),
						'|',
						src.value
					 )))
					 INNER JOIN {$prefix}cml_string_translations st ON st.string_id = cs.id AND st.language = t.language
					 WHERE t.status != %d
					   AND t.value IS NOT NULL
					   AND t.value != ''
					   AND st.translation != t.value",
					self::STRING_STATUS_NEEDS_TRANSLATION
				)
			);
		}

		return array(
			'language_settings'   => $language_settings,
			'post_mappings'       => $post_mappings,
			'term_mappings'       => $term_mappings,
			'string_translations' => $string_translations,
		);
	}

	// ---- Imports ---------------------------------------------------------

	/**
	 * @return array{languages:int, posts:int, terms:int, strings:int, translated_strings:int, default_set:bool, conflicts:array{language_settings:int,post_mappings:int,term_mappings:int,string_translations:int}, errors:array<int,string>}
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
			'errors'             => array(),
		);

		if ( ! $allow_conflicts && self::has_conflicts( $result['conflicts'] ) ) {
			$result['errors'][] = 'conflicts: existing CodeOn data differs from WPML data. Review the preflight report before importing.';
			return $result;
		}

		self::begin_transaction();
		try {
			$result['languages'] = self::import_languages();
			Languages::flush_cache();
			$result['default_set']        = self::set_default_language();
			$result['posts']              = self::import_post_translations();
			$result['terms']              = self::import_term_translations();
			$result['strings']            = self::import_string_sources();
			$result['translated_strings'] = self::import_string_translations();
			self::commit_transaction();
		} catch ( \Throwable $e ) {
			self::rollback_transaction();
			$result['errors'][] = $e->getMessage();
		}

		if ( ! empty( $result['errors'] ) ) {
			return $result;
		}

		Languages::flush_cache();
		StringTranslator::flush_cache();
		TranslationGroups::flush();

		// If the admin has opted into the native .l10n.php path, bulk-regenerate
		// after import so the newly-imported translations are available via the
		// fast native lookup path immediately.
		if ( L10nFileWriter::is_enabled() ) {
			L10nFileWriter::regenerate_all();
		}

		return $result;
	}

	public static function import_languages(): int {
		global $wpdb;
		if ( ! self::table_exists( $wpdb->prefix . 'icl_languages' ) ) {
			return 0;
		}

		// Native name: WPML stores it in icl_languages_translations where display_language_code = language_code.
		$native_select = self::wpml_native_name_select();

		$sql = "INSERT IGNORE INTO {$wpdb->prefix}cml_languages (code, locale, name, native, flag, rtl, active, is_default, position)
			SELECT
				l.code,
				COALESCE(l.default_locale, l.code),
				l.english_name,
				{$native_select},
				'',
				0,
				COALESCE(l.active, 1),
				0,
				0
			FROM {$wpdb->prefix}icl_languages l";

		$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cml_languages" );
		self::query_or_throw( $sql, 'languages' );
		$count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cml_languages" );

		return $count_after - $count_before;
	}

	public static function set_default_language(): bool {
		global $wpdb;
		$code = self::default_language_from_options();
		if ( null === $code ) {
			return false;
		}
		if ( ! Languages::exists( $code ) ) {
			return false;
		}

		$wpdb->update(
			$wpdb->prefix . 'cml_languages',
			array( 'is_default' => 0 ),
			array( 'is_default' => 1 ),
			array( '%d' ),
			array( '%d' )
		);
		$updated = $wpdb->update(
			$wpdb->prefix . 'cml_languages',
			array( 'is_default' => 1 ),
			array( 'code' => $code ),
			array( '%d' ),
			array( '%s' )
		);
		return false !== $updated;
	}

	public static function import_post_translations(): int {
		global $wpdb;
		if ( ! self::table_exists( $wpdb->prefix . 'icl_translations' ) ) {
			return 0;
		}

		$sql = "INSERT IGNORE INTO {$wpdb->prefix}cml_post_language (post_id, group_id, language)
			SELECT t.element_id, t.trid, t.language_code
			FROM {$wpdb->prefix}icl_translations t
			INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id
			WHERE t.element_type LIKE 'post_%'
			  AND t.element_id IS NOT NULL";

		self::query_or_throw( $sql, 'post translations' );
		return (int) $wpdb->rows_affected;
	}

	public static function import_term_translations(): int {
		global $wpdb;
		if ( ! self::table_exists( $wpdb->prefix . 'icl_translations' ) ) {
			return 0;
		}

		// WPML's element_id for tax_* rows is term_taxonomy_id; we need term_id.
		$sql = "INSERT IGNORE INTO {$wpdb->prefix}cml_term_language (term_id, group_id, language)
			SELECT tt.term_id, t.trid, t.language_code
			FROM {$wpdb->prefix}icl_translations t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = t.element_id
			WHERE t.element_type LIKE 'tax_%'
			  AND t.element_id IS NOT NULL";

		self::query_or_throw( $sql, 'term translations' );
		return (int) $wpdb->rows_affected;
	}

	public static function import_string_sources(): int {
		global $wpdb;
		if ( ! self::table_exists( $wpdb->prefix . 'icl_strings' ) ) {
			return 0;
		}

		// Compute md5(domain|context|source) server-side via UNHEX(MD5(CONCAT())).
		// source_language uses WPML's authoritative s.language; ON DUPLICATE
		// updates it so reruns correct any prior detect-only values.
		$sql = "INSERT INTO {$wpdb->prefix}cml_strings (hash, domain, context, source, source_language, created_at)
			SELECT
				UNHEX(MD5(CONCAT(
					COALESCE(s.context, ''),
					'|',
					COALESCE(s.gettext_context, ''),
					'|',
					s.value
				))),
				COALESCE(s.context, ''),
				COALESCE(s.gettext_context, ''),
				s.value,
				COALESCE(s.language, 'en'),
				UNIX_TIMESTAMP()
			FROM {$wpdb->prefix}icl_strings s
			WHERE s.value IS NOT NULL AND s.value != ''
			ON DUPLICATE KEY UPDATE
				source_language = VALUES(source_language)";

		self::query_or_throw( $sql, 'string sources' );
		return (int) $wpdb->rows_affected;
	}

	public static function import_string_translations(): int {
		global $wpdb;
		if ( ! self::table_exists( $wpdb->prefix . 'icl_string_translations' ) ) {
			return 0;
		}
		if ( ! self::table_exists( $wpdb->prefix . 'icl_strings' ) ) {
			return 0;
		}

		// Join through hash to find our string ids in one statement.
		$sql = $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}cml_string_translations (string_id, language, translation, updated_at)
			SELECT cs.id, t.language, t.value, UNIX_TIMESTAMP()
			FROM {$wpdb->prefix}icl_string_translations t
			INNER JOIN {$wpdb->prefix}icl_strings src ON src.id = t.string_id
			INNER JOIN {$wpdb->prefix}cml_strings cs ON cs.hash = UNHEX(MD5(CONCAT(
				COALESCE(src.context, ''),
				'|',
				COALESCE(src.gettext_context, ''),
				'|',
				src.value
			)))
			WHERE t.status != %d
			  AND t.value IS NOT NULL
			  AND t.value != ''",
			self::STRING_STATUS_NEEDS_TRANSLATION
		);

		self::query_or_throw( $sql, 'string translations' );
		return (int) $wpdb->rows_affected;
	}

	// ---- Internals -------------------------------------------------------

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
	}

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

	/**
	 * @param array{language_settings:int,post_mappings:int,term_mappings:int,string_translations:int} $conflicts
	 */
	private static function has_conflicts( array $conflicts ): bool {
		return $conflicts['language_settings'] > 0
			|| $conflicts['post_mappings'] > 0
			|| $conflicts['term_mappings'] > 0
			|| $conflicts['string_translations'] > 0;
	}

	private static function wpml_native_name_select(): string {
		global $wpdb;
		return self::table_exists( $wpdb->prefix . 'icl_languages_translations' )
			? "COALESCE((SELECT name FROM {$wpdb->prefix}icl_languages_translations WHERE language_code = l.code AND display_language_code = l.code LIMIT 1), l.english_name)"
			: 'l.english_name';
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
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared or fixed internally by the import method before reaching this guard.
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not rendered directly; callers escape admin output.
			throw new \RuntimeException( $label . ': ' . ( $wpdb->last_error ?: 'database query failed' ) );
		}
	}

	private static function default_language_from_options(): ?string {
		$settings = get_option( 'icl_sitepress_settings' );
		if ( ! is_array( $settings ) ) {
			return null;
		}
		$code = $settings['default_language'] ?? null;
		return is_string( $code ) && '' !== $code ? $code : null;
	}
}
