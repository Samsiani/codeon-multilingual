<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Migration;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * One-shot migration from WPML's icl_* tables into ours.
 *
 * Every step is one SQL statement using INSERT ... SELECT [ON DUPLICATE KEY UPDATE].
 * The database does the join + dedupe; no PHP iteration, no batching. WPML's
 * existing trid (translation group id) is reused as our group_id directly —
 * they have the same semantics.
 *
 * Idempotent: re-running updates rows in place. Safe to run multiple times and
 * across staged content additions.
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
	 * @return array{languages:int, posts:int, terms:int, strings:int, translated_strings:int, default_language:?string}
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
		);
	}

	// ---- Imports ---------------------------------------------------------

	/**
	 * @return array{languages:int, posts:int, terms:int, strings:int, translated_strings:int, default_set:bool, errors:array<int,string>}
	 */
	public static function import_all(): array {
		$result = array(
			'languages'          => 0,
			'posts'              => 0,
			'terms'              => 0,
			'strings'            => 0,
			'translated_strings' => 0,
			'default_set'        => false,
			'errors'             => array(),
		);

		try {
			$result['languages'] = self::import_languages();
		} catch ( \Throwable $e ) {
			$result['errors'][] = 'languages: ' . $e->getMessage();
		}
		try {
			$result['default_set'] = self::set_default_language();
		} catch ( \Throwable $e ) {
			$result['errors'][] = 'default: ' . $e->getMessage();
		}
		try {
			$result['posts'] = self::import_post_translations();
		} catch ( \Throwable $e ) {
			$result['errors'][] = 'posts: ' . $e->getMessage();
		}
		try {
			$result['terms'] = self::import_term_translations();
		} catch ( \Throwable $e ) {
			$result['errors'][] = 'terms: ' . $e->getMessage();
		}
		try {
			$result['strings'] = self::import_string_sources();
		} catch ( \Throwable $e ) {
			$result['errors'][] = 'strings: ' . $e->getMessage();
		}
		try {
			$result['translated_strings'] = self::import_string_translations();
		} catch ( \Throwable $e ) {
			$result['errors'][] = 'string translations: ' . $e->getMessage();
		}

		Languages::flush_cache();
		StringTranslator::flush_cache();
		TranslationGroups::flush();

		return $result;
	}

	public static function import_languages(): int {
		global $wpdb;
		if ( ! self::table_exists( $wpdb->prefix . 'icl_languages' ) ) {
			return 0;
		}

		$has_translations_table = self::table_exists( $wpdb->prefix . 'icl_languages_translations' );

		// Native name: WPML stores it in icl_languages_translations where display_language_code = language_code.
		$native_select = $has_translations_table
			? "COALESCE((SELECT name FROM {$wpdb->prefix}icl_languages_translations WHERE language_code = l.code AND display_language_code = l.code LIMIT 1), l.english_name)"
			: 'l.english_name';

		$sql = "INSERT INTO {$wpdb->prefix}cml_languages (code, locale, name, native, flag, rtl, active, is_default, position)
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
			FROM {$wpdb->prefix}icl_languages l
			ON DUPLICATE KEY UPDATE
				locale = VALUES(locale),
				name = VALUES(name),
				native = VALUES(native),
				active = VALUES(active)";

		$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cml_languages" );
		$wpdb->query( $sql );
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

		$sql = "INSERT INTO {$wpdb->prefix}cml_post_language (post_id, group_id, language)
			SELECT t.element_id, t.trid, t.language_code
			FROM {$wpdb->prefix}icl_translations t
			INNER JOIN {$wpdb->posts} p ON p.ID = t.element_id
			WHERE t.element_type LIKE 'post_%'
			  AND t.element_id IS NOT NULL
			ON DUPLICATE KEY UPDATE
				group_id = VALUES(group_id),
				language = VALUES(language)";

		$wpdb->query( $sql );
		return (int) $wpdb->rows_affected;
	}

	public static function import_term_translations(): int {
		global $wpdb;
		if ( ! self::table_exists( $wpdb->prefix . 'icl_translations' ) ) {
			return 0;
		}

		// WPML's element_id for tax_* rows is term_taxonomy_id; we need term_id.
		$sql = "INSERT INTO {$wpdb->prefix}cml_term_language (term_id, group_id, language)
			SELECT tt.term_id, t.trid, t.language_code
			FROM {$wpdb->prefix}icl_translations t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = t.element_id
			WHERE t.element_type LIKE 'tax_%'
			  AND t.element_id IS NOT NULL
			ON DUPLICATE KEY UPDATE
				group_id = VALUES(group_id),
				language = VALUES(language)";

		$wpdb->query( $sql );
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

		$wpdb->query( $sql );
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
			"INSERT INTO {$wpdb->prefix}cml_string_translations (string_id, language, translation, updated_at)
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
			  AND t.value != ''
			ON DUPLICATE KEY UPDATE
				translation = VALUES(translation),
				updated_at  = VALUES(updated_at)",
			self::STRING_STATUS_NEEDS_TRANSLATION
		);

		$wpdb->query( $sql );
		return (int) $wpdb->rows_affected;
	}

	// ---- Internals -------------------------------------------------------

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
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
