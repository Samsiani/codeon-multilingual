<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

/**
 * Database schema installer and dropper.
 *
 * Five tables, each indexed for its specific access pattern:
 *   - languages              configured languages
 *   - post_language          post -> (group, language) — single-JOIN query injection
 *   - term_language          term -> (group, language)
 *   - strings                source strings, deduped by binary md5(domain|context|source)
 *   - string_translations    per-language translations of those strings
 *
 * No generic "translations" table (WPML's wp_icl_translations) — splitting by element
 * type lets MySQL pick optimal indexes instead of scanning a polymorphic column.
 */
final class Schema {

	public const VERSION = '1';

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		foreach ( self::statements( $prefix, $charset_collate ) as $sql ) {
			\dbDelta( $sql );
		}

		self::seed_default_language();
	}

	public static function uninstall(): void {
		global $wpdb;

		// Order matters: child tables first so any FK semantics stay sane on engines that enforce them.
		$tables = array(
			$wpdb->prefix . 'cml_string_translations',
			$wpdb->prefix . 'cml_strings',
			$wpdb->prefix . 'cml_term_language',
			$wpdb->prefix . 'cml_post_language',
			$wpdb->prefix . 'cml_languages',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( 'cml_db_version' );
		delete_option( 'cml_activated_at' );
		delete_option( 'cml_settings' );
		delete_option( Backfill::OPTION_STATUS );
		delete_option( Backfill::OPTION_LAST_ID );
		wp_clear_scheduled_hook( Backfill::HOOK );
	}

	/**
	 * @return array<int, string>
	 */
	private static function statements( string $prefix, string $charset_collate ): array {
		return array(
			"CREATE TABLE {$prefix}cml_languages (
				code VARCHAR(10) NOT NULL,
				locale VARCHAR(20) NOT NULL,
				name VARCHAR(100) NOT NULL,
				native VARCHAR(100) NOT NULL,
				flag VARCHAR(50) NOT NULL DEFAULT '',
				rtl TINYINT(1) NOT NULL DEFAULT 0,
				active TINYINT(1) NOT NULL DEFAULT 1,
				is_default TINYINT(1) NOT NULL DEFAULT 0,
				position INT NOT NULL DEFAULT 0,
				PRIMARY KEY  (code),
				KEY active_idx (active, position)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}cml_post_language (
				post_id BIGINT UNSIGNED NOT NULL,
				group_id BIGINT UNSIGNED NOT NULL,
				language VARCHAR(10) NOT NULL,
				PRIMARY KEY  (post_id),
				KEY language_idx (language, post_id),
				KEY group_idx (group_id)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}cml_term_language (
				term_id BIGINT UNSIGNED NOT NULL,
				group_id BIGINT UNSIGNED NOT NULL,
				language VARCHAR(10) NOT NULL,
				PRIMARY KEY  (term_id),
				KEY language_idx (language, term_id),
				KEY group_idx (group_id)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}cml_strings (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				hash BINARY(16) NOT NULL,
				domain VARCHAR(190) NOT NULL,
				context VARCHAR(190) NOT NULL DEFAULT '',
				source LONGTEXT NOT NULL,
				created_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY hash_unique (hash),
				KEY domain_idx (domain)
			) {$charset_collate};",

			"CREATE TABLE {$prefix}cml_string_translations (
				string_id BIGINT UNSIGNED NOT NULL,
				language VARCHAR(10) NOT NULL,
				translation LONGTEXT NOT NULL,
				updated_at INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (string_id, language),
				KEY language_idx (language)
			) {$charset_collate};",
		);
	}

	private static function seed_default_language(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cml_languages';

		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $existing > 0 ) {
			return;
		}

		$locale = get_locale();
		$info   = self::locale_to_info( $locale );

		$wpdb->insert(
			$table,
			array(
				'code'       => $info['code'],
				'locale'     => $locale,
				'name'       => $info['name'],
				'native'     => $info['native'],
				'flag'       => $info['code'],
				'rtl'        => $info['rtl'],
				'active'     => 1,
				'is_default' => 1,
				'position'   => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
		);
	}

	/**
	 * @return array{code:string,name:string,native:string,rtl:int}
	 */
	private static function locale_to_info( string $locale ): array {
		static $map = array(
			'en_US' => array(
				'code'   => 'en',
				'name'   => 'English',
				'native' => 'English',
				'rtl'    => 0,
			),
			'en_GB' => array(
				'code'   => 'en',
				'name'   => 'English',
				'native' => 'English',
				'rtl'    => 0,
			),
			'ka_GE' => array(
				'code'   => 'ka',
				'name'   => 'Georgian',
				'native' => 'ქართული',
				'rtl'    => 0,
			),
			'ru_RU' => array(
				'code'   => 'ru',
				'name'   => 'Russian',
				'native' => 'Русский',
				'rtl'    => 0,
			),
			'de_DE' => array(
				'code'   => 'de',
				'name'   => 'German',
				'native' => 'Deutsch',
				'rtl'    => 0,
			),
			'fr_FR' => array(
				'code'   => 'fr',
				'name'   => 'French',
				'native' => 'Français',
				'rtl'    => 0,
			),
			'es_ES' => array(
				'code'   => 'es',
				'name'   => 'Spanish',
				'native' => 'Español',
				'rtl'    => 0,
			),
			'it_IT' => array(
				'code'   => 'it',
				'name'   => 'Italian',
				'native' => 'Italiano',
				'rtl'    => 0,
			),
			'pt_BR' => array(
				'code'   => 'pt',
				'name'   => 'Portuguese',
				'native' => 'Português',
				'rtl'    => 0,
			),
			'tr_TR' => array(
				'code'   => 'tr',
				'name'   => 'Turkish',
				'native' => 'Türkçe',
				'rtl'    => 0,
			),
			'ar'    => array(
				'code'   => 'ar',
				'name'   => 'Arabic',
				'native' => 'العربية',
				'rtl'    => 1,
			),
			'he_IL' => array(
				'code'   => 'he',
				'name'   => 'Hebrew',
				'native' => 'עברית',
				'rtl'    => 1,
			),
		);

		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}

		$code = '' !== $locale ? strtolower( substr( $locale, 0, 2 ) ) : 'en';
		return array(
			'code'   => $code,
			'name'   => ucfirst( $code ),
			'native' => ucfirst( $code ),
			'rtl'    => 0,
		);
	}
}
