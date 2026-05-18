<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Compat;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Url\Router;

/**
 * Small shared helper for compatibility adapters that need sibling IDs/URLs.
 */
final class ValueLocalizer {

	public static function post_id( int $post_id, string $language, bool $fallback = true ): int {
		if ( $post_id <= 0 || '' === $language ) {
			return $fallback ? $post_id : 0;
		}

		$group_id = TranslationGroups::get_group_id( $post_id );
		if ( null === $group_id ) {
			return $fallback ? $post_id : 0;
		}

		foreach ( TranslationGroups::get_siblings( $group_id ) as $sibling_id => $sibling_language ) {
			if ( $language === $sibling_language ) {
				return (int) $sibling_id;
			}
		}

		return $fallback ? $post_id : 0;
	}

	public static function term_id( int $term_id, string $language, bool $fallback = true ): int {
		if ( $term_id <= 0 || '' === $language ) {
			return $fallback ? $term_id : 0;
		}

		$group_id = TranslationGroups::get_term_group_id( $term_id );
		if ( null === $group_id ) {
			return $fallback ? $term_id : 0;
		}

		foreach ( TranslationGroups::get_term_siblings( $group_id ) as $sibling_id => $sibling_language ) {
			if ( $language === $sibling_language ) {
				return (int) $sibling_id;
			}
		}

		return $fallback ? $term_id : 0;
	}

	public static function url( string $url, string $language ): string {
		if ( '' === $url || '' === $language || ! Languages::exists( $language ) ) {
			return $url;
		}

		$home = home_url( '/' );
		if ( 0 !== strpos( $url, $home ) ) {
			return $url;
		}

		$stripped = Router::strip_lang_prefix( $url );
		return Languages::is_default( $language )
			? $stripped
			: Router::with_lang( $stripped, $language );
	}

	/**
	 * @param mixed               $value
	 * @param array<string,mixed> $policy
	 * @return mixed
	 */
	public static function walk( $value, string $language, array $policy = array() ) {
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $key => $item ) {
				$key_string       = is_string( $key ) ? $key : '';
				$result[ $key ] = self::walk_keyed_value( $key_string, $item, $language, $policy );
			}
			return $result;
		}

		return $value;
	}

	/**
	 * @param mixed               $value
	 * @param array<string,mixed> $policy
	 * @return mixed
	 */
	private static function walk_keyed_value( string $key, $value, string $language, array $policy ) {
		$post_keys = isset( $policy['post_keys'] ) && is_array( $policy['post_keys'] )
			? $policy['post_keys']
			: array( 'post_id', 'page_id', 'template_id', 'popup_id', 'selected_posts', 'posts_include', 'query_include' );
		$term_keys = isset( $policy['term_keys'] ) && is_array( $policy['term_keys'] )
			? $policy['term_keys']
			: array( 'term_id', 'category_id', 'tag_id', 'product_cat', 'product_tag' );
		$url_keys  = isset( $policy['url_keys'] ) && is_array( $policy['url_keys'] )
			? $policy['url_keys']
			: array( 'url', 'href', 'link', 'button_link', 'permalink' );

		if ( in_array( $key, $post_keys, true ) ) {
			return self::localize_id_value( $value, $language, 'post' );
		}
		if ( in_array( $key, $term_keys, true ) ) {
			return self::localize_id_value( $value, $language, 'term' );
		}
		if ( in_array( $key, $url_keys, true ) && is_string( $value ) ) {
			return self::url( $value, $language );
		}

		return self::walk( $value, $language, $policy );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function localize_id_value( $value, string $language, string $kind ) {
		if ( is_numeric( $value ) ) {
			$localized = 'post' === $kind
				? self::post_id( (int) $value, $language )
				: self::term_id( (int) $value, $language );
			return is_string( $value ) ? (string) $localized : $localized;
		}

		if ( is_array( $value ) ) {
			return array_map(
				static fn( $item ) => self::localize_id_value( $item, $language, $kind ),
				$value
			);
		}

		return $value;
	}
}
