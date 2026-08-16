<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Migration;

use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Import gettext translations from TranslatePress.
 *
 * TranslatePress keeps one table per locale, `{prefix}trp_gettext_{locale}`,
 * holding (original, translated, domain) triples harvested from the `gettext`
 * filter. That maps almost one-to-one onto our own strings catalog: their
 * `domain` is our `domain`, their `original` is our `source`, and they have no
 * gettext context, so our `context` is always ''.
 *
 * The import is read-only with respect to TranslatePress — nothing in its
 * tables is modified, so a site can run both plugins during a transition and
 * roll back simply by not activating ours.
 *
 * Sources and translations are written in two SQL statements per locale
 * (INSERT … SELECT joined on the MD5 hash), so a 4,000-string catalogue
 * imports in well under a second rather than 8,000 round-trips.
 */
// Table names cannot be bound as prepared-statement placeholders. Every table
// interpolated below comes from self::table_for(), which only returns a name
// after matching it against the live SHOW TABLES list — caller input is never
// concatenated into SQL. Values are still bound via $wpdb->prepare().
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
final class TranslatePressImporter {

	/**
	 * Domains skipped by default. `carspace-dashboard` ships its own
	 * translation engine which runs at a later filter priority, so importing
	 * its strings here would only create confusing duplicates in the editor.
	 *
	 * @var string[]
	 */
	private const DEFAULT_EXCLUDED_DOMAINS = array( 'carspace-dashboard' );

	public static function is_available(): bool {
		return ! empty( self::locales() );
	}

	/**
	 * Locales TranslatePress holds gettext tables for.
	 *
	 * @return string[] e.g. ['en_us', 'ka_ge']
	 */
	public static function locales(): array {
		global $wpdb;

		$prefix = $wpdb->prefix . 'trp_gettext_';
		$tables = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' )
		);

		$locales = array();
		foreach ( (array) $tables as $table ) {
			$suffix = substr( (string) $table, strlen( $prefix ) );
			// Skip TranslatePress's own bookkeeping tables.
			if ( '' === $suffix || 0 === strpos( $suffix, 'original' ) ) {
				continue;
			}
			$locales[] = $suffix;
		}

		sort( $locales );
		return $locales;
	}

	/**
	 * Table name for a locale, or null when TranslatePress has none.
	 *
	 * The locale is validated against the discovered table list rather than
	 * interpolated from caller input, so the name can be used unescaped in the
	 * FROM clause below.
	 */
	public static function table_for( string $locale ): ?string {
		global $wpdb;

		$locale = strtolower( trim( $locale ) );
		if ( ! in_array( $locale, self::locales(), true ) ) {
			return null;
		}
		return $wpdb->prefix . 'trp_gettext_' . $locale;
	}

	/**
	 * What an import would bring in, without writing anything.
	 *
	 * @param string[]|null $excluded_domains Defaults to self::DEFAULT_EXCLUDED_DOMAINS.
	 * @return array{available:bool,locales:array<string,array{translated:int,domains:int,already_known:int}>}
	 */
	public static function summary( ?array $excluded_domains = null ): array {
		global $wpdb;

		$excluded = self::excluded( $excluded_domains );
		$out      = array(
			'available' => false,
			'locales'   => array(),
		);

		foreach ( self::locales() as $locale ) {
			$table = self::table_for( $locale );
			if ( null === $table ) {
				continue;
			}
			$out['available'] = true;

			$where = self::translated_where( $excluded );

			$translated = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` tp {$where}" );
			$domains    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT tp.domain) FROM `{$table}` tp {$where}" );

			// How many already exist as sources in our catalog — i.e. how much
			// of this import lands on strings the site has actually rendered.
			$known = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$table}` tp
				 INNER JOIN {$wpdb->prefix}cml_strings cs
				         ON cs.hash = UNHEX(MD5(CONCAT(COALESCE(tp.domain, ''), '|', '', '|', tp.original)))
				 {$where}"
			);

			$out['locales'][ $locale ] = array(
				'translated'    => $translated,
				'domains'       => $domains,
				'already_known' => $known,
			);
		}

		return $out;
	}

	/**
	 * Import one locale into one of our languages.
	 *
	 * @param string        $language          Our language code, e.g. 'ka'.
	 * @param string        $locale            TranslatePress locale, e.g. 'ka_ge'.
	 * @param bool          $create_sources    Also add unseen strings to the catalog.
	 * @param bool          $overwrite         Replace translations already entered here.
	 * @param string[]|null $excluded_domains  Defaults to self::DEFAULT_EXCLUDED_DOMAINS.
	 * @return array{sources:int,translations:int}|\WP_Error
	 */
	public static function import(
		string $language,
		string $locale,
		bool $create_sources = true,
		bool $overwrite = false,
		?array $excluded_domains = null
	) {
		global $wpdb;

		$table = self::table_for( $locale );
		if ( null === $table ) {
			return new \WP_Error(
				'cml_trp_missing_table',
				sprintf( 'No TranslatePress table found for locale "%s".', $locale )
			);
		}

		$language = sanitize_key( $language );
		if ( '' === $language ) {
			return new \WP_Error( 'cml_trp_bad_language', 'A target language code is required.' );
		}

		$where   = self::translated_where( self::excluded( $excluded_domains ) );
		$sources = 0;

		if ( $create_sources ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->prefix}cml_strings (hash, domain, context, source, source_language, created_at)
					 SELECT UNHEX(MD5(CONCAT(COALESCE(tp.domain, ''), '|', '', '|', tp.original))),
					        COALESCE(tp.domain, ''),
					        '',
					        tp.original,
					        'en',
					        %d
					 FROM `{$table}` tp
					 {$where}
					   AND CHAR_LENGTH(tp.original) <= 2048
					 GROUP BY tp.domain, tp.original",
					time()
				)
			);
			$sources = (int) $wpdb->rows_affected;
		}

		// Join through the hash so we only ever write translations for sources
		// that exist in our catalog.
		$verb = $overwrite ? 'REPLACE' : 'INSERT IGNORE';

		$wpdb->query(
			$wpdb->prepare(
				"{$verb} INTO {$wpdb->prefix}cml_string_translations (string_id, language, translation, updated_at)
				 SELECT cs.id, %s, tp.translated, UNIX_TIMESTAMP()
				 FROM `{$table}` tp
				 INNER JOIN {$wpdb->prefix}cml_strings cs
				         ON cs.hash = UNHEX(MD5(CONCAT(COALESCE(tp.domain, ''), '|', '', '|', tp.original)))
				 {$where}
				 GROUP BY cs.id",
				$language
			)
		);
		$translations = (int) $wpdb->rows_affected;

		StringTranslator::flush_cache();

		return array(
			'sources'      => $sources,
			'translations' => $translations,
		);
	}

	// ---- Helpers -----------------------------------------------------------

	/**
	 * @param string[]|null $excluded_domains
	 * @return string[]
	 */
	private static function excluded( ?array $excluded_domains ): array {
		$list = ( null === $excluded_domains ) ? self::DEFAULT_EXCLUDED_DOMAINS : $excluded_domains;

		/**
		 * Filter the text domains skipped when importing from TranslatePress.
		 *
		 * @param string[] $list
		 */
		$list = (array) apply_filters( 'cml_trp_import_excluded_domains', $list );

		return array_values( array_filter( array_map( 'strval', $list ) ) );
	}

	/**
	 * Shared WHERE clause: only rows with an actual translation, minus the
	 * excluded domains.
	 *
	 * @param string[] $excluded
	 */
	private static function translated_where( array $excluded ): string {
		global $wpdb;

		$sql = "WHERE tp.translated IS NOT NULL AND tp.translated <> '' AND tp.original <> ''";

		if ( ! empty( $excluded ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $excluded ), '%s' ) );
			$sql         .= $wpdb->prepare( " AND COALESCE(tp.domain, '') NOT IN ({$placeholders})", $excluded );
		}

		return $sql;
	}
}
