<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Compat;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;

/**
 * Localizes Rank Math SEO breadcrumb and JSON-LD arrays for the current language.
 */
final class RankMathSeo {

	private static bool $registered = false;

	/** @var array<string, array<int, string>> */
	private const BREADCRUMB_POLICY = array(
		'post_keys' => array( 'id', 'post_id', 'page_id' ),
		'term_keys' => array( 'term_id', 'category_id', 'tag_id' ),
		'url_keys'  => array( 'url', 'href', 'link', 'permalink', 'item', '@id' ),
	);

	/** @var array<string, array<int, string>> */
	private const SCHEMA_POLICY = array(
		'post_keys' => array( 'post_id', 'page_id' ),
		'term_keys' => array( 'term_id', 'category_id', 'tag_id' ),
		'url_keys'  => array(
			'@id',
			'contentUrl',
			'embedUrl',
			'href',
			'id',
			'image',
			'isPartOf',
			'item',
			'link',
			'logo',
			'mainEntityOfPage',
			'permalink',
			'primaryImageOfPage',
			'thumbnailUrl',
			'url',
		),
	);

	/** @var array<int, string> */
	private const URL_LIST_KEYS = array( 'sameAs' );

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'rank_math/frontend/breadcrumb/items', array( self::class, 'localize_breadcrumbs' ), 20, 1 );
		add_filter( 'rank_math/json_ld', array( self::class, 'localize_json_ld' ), 20, 1 );
	}

	/**
	 * @param mixed $breadcrumbs
	 * @return mixed
	 */
	public static function localize_breadcrumbs( $breadcrumbs, ?string $language = null ) {
		if ( ! is_array( $breadcrumbs ) ) {
			return $breadcrumbs;
		}

		$language = self::language( $language );
		if ( '' === $language ) {
			return $breadcrumbs;
		}

		return self::walk_breadcrumbs( $breadcrumbs, $language );
	}

	/**
	 * @param mixed $schema
	 * @return mixed
	 */
	public static function localize_schema( $schema, ?string $language = null ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		$language = self::language( $language );
		if ( '' === $language ) {
			return $schema;
		}

		return self::walk_array( $schema, $language, self::SCHEMA_POLICY );
	}

	/**
	 * @param mixed $json_ld
	 * @return mixed
	 */
	public static function localize_json_ld( $json_ld, ?string $language = null ) {
		return self::localize_schema( $json_ld, $language );
	}

	private static function language( ?string $language ): string {
		return null === $language ? CurrentLanguage::code() : $language;
	}

	/**
	 * Rank Math commonly stores breadcrumb URLs at key `url`, but older/custom
	 * integrations may use `[label, url]` tuples. Localize only that URL slot.
	 *
	 * @param array<mixed> $breadcrumbs
	 * @return array<mixed>
	 */
	private static function walk_breadcrumbs( array $breadcrumbs, string $language ): array {
		$result = array();
		foreach ( $breadcrumbs as $key => $crumb ) {
			if ( is_array( $crumb ) && isset( $crumb[1] ) && is_string( $crumb[1] ) ) {
				$crumb[1] = ValueLocalizer::url( $crumb[1], $language );
			}
			$result[ $key ] = is_array( $crumb )
				? self::walk_array( $crumb, $language, self::BREADCRUMB_POLICY )
				: $crumb;
		}
		return $result;
	}

	/**
	 * @param array<mixed>        $value
	 * @param array<string,mixed> $policy
	 * @return array<mixed>
	 */
	private static function walk_array( array $value, string $language, array $policy ): array {
		$result = array();
		foreach ( $value as $key => $item ) {
			$result[ $key ] = self::walk_keyed_value( is_string( $key ) ? $key : '', $item, $language, $policy );
		}
		return $result;
	}

	/**
	 * @param mixed               $value
	 * @param array<string,mixed> $policy
	 * @return mixed
	 */
	private static function walk_keyed_value( string $key, $value, string $language, array $policy ) {
		if ( in_array( $key, self::URL_LIST_KEYS, true ) ) {
			return self::localize_url_list_value( $value, $language );
		}

		if ( self::is_policy_key( $key, 'post_keys', $policy )
			|| self::is_policy_key( $key, 'term_keys', $policy )
			|| self::is_policy_key( $key, 'url_keys', $policy )
		) {
			return self::localize_with_value_localizer( $key, $value, $language, $policy );
		}

		if ( is_array( $value ) ) {
			return self::walk_array( $value, $language, $policy );
		}

		return $value;
	}

	/**
	 * @param array<string,mixed> $policy
	 */
	private static function is_policy_key( string $key, string $group, array $policy ): bool {
		return isset( $policy[ $group ] )
			&& is_array( $policy[ $group ] )
			&& in_array( $key, $policy[ $group ], true );
	}

	/**
	 * @param mixed               $value
	 * @param array<string,mixed> $policy
	 * @return mixed
	 */
	private static function localize_with_value_localizer( string $key, $value, string $language, array $policy ) {
		$wrapped = ValueLocalizer::walk( array( $key => $value ), $language, $policy );
		return $wrapped[ $key ] ?? $value;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function localize_url_list_value( $value, string $language ) {
		if ( is_string( $value ) ) {
			return ValueLocalizer::url( $value, $language );
		}

		if ( is_array( $value ) ) {
			return array_map(
				static fn( $item ) => self::localize_url_list_value( $item, $language ),
				$value
			);
		}

		return $value;
	}
}
