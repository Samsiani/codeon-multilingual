<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Compat;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;

/**
 * Compatibility adapter for builder payloads that store object IDs in JSON,
 * block attributes, or ACF values.
 */
final class BuilderData {

	private static bool $registered = false;

	/** @return array<string,array<int,string>> */
	private static function policy(): array {
		return array(
			'post_keys' => array(
				'post_id',
				'page_id',
				'template_id',
				'popup_id',
				'product_id',
				'selected_posts',
				'selectedPosts',
				'posts_include',
				'postsInclude',
				'query_include',
				'queryInclude',
				'include_ids',
				'exclude_ids',
				'post__in',
				'post__not_in',
				'postId',
				'pageId',
				'templateId',
				'popupId',
				'productId',
			),
			'term_keys' => array(
				'term_id',
				'term_ids',
				'category_id',
				'category_ids',
				'categories',
				'categoryIds',
				'tag_id',
				'tag_ids',
				'tags',
				'product_cat',
				'product_tag',
				'termId',
				'termIds',
				'categoryId',
				'tagId',
			),
			'url_keys'  => array(
				'url',
				'href',
				'link',
				'button_link',
				'buttonUrl',
				'permalink',
				'page_link',
				'pageLink',
			),
		);
	}

	/** @return array<string,array<int,string>> */
	private static function block_policy(): array {
		$policy                = self::policy();
		$policy['post_keys'][] = 'ref';
		return $policy;
	}

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! function_exists( 'add_action' ) || ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_action( 'cml_post_translation_created', array( self::class, 'on_post_translation_created' ), 30, 4 );
		add_filter( 'acf/load_value', array( self::class, 'localize_acf_value' ), 20, 3 );
	}

	public static function on_post_translation_created( int $new_id, int $source_id, string $target_lang, int $group_id ): void {
		unset( $source_id, $group_id );

		self::localize_post_content_for_translation( $new_id, $target_lang );
		self::localize_builder_meta_for_translation( $new_id, $target_lang );
	}

	public static function localize_post_content_for_translation( int $post_id, string $language ): void {
		if ( ! function_exists( 'get_post_field' ) || ! function_exists( 'wp_update_post' ) ) {
			return;
		}

		$content = (string) get_post_field( 'post_content', $post_id );
		if ( '' === $content ) {
			return;
		}

		$localized = self::localize_block_content( $content, $language );
		if ( $localized === $content ) {
			return;
		}

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $localized,
			)
		);
	}

	public static function localize_builder_meta_for_translation( int $post_id, string $language ): void {
		if ( ! function_exists( 'get_post_meta' ) || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		foreach ( self::localized_meta_keys() as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' === $value || null === $value ) {
				continue;
			}

			$localized = self::localize_meta_value( $key, $value, $language );
			if ( $localized !== $value ) {
				update_post_meta( $post_id, $key, self::slash_meta_value( $localized ) );
			}
		}
	}

	public static function localize_block_content( string $content, string $language ): string {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $content;
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || array() === $blocks ) {
			return $content;
		}

		return serialize_blocks( self::localize_blocks( $blocks, $language ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $blocks
	 * @return array<int,array<string,mixed>>
	 */
	public static function localize_blocks( array $blocks, string $language ): array {
		foreach ( $blocks as $index => $block ) {
			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$blocks[ $index ]['attrs'] = ValueLocalizer::walk( $block['attrs'], $language, self::block_policy() );
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::localize_blocks( $block['innerBlocks'], $language );
			}
		}

		return $blocks;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function localize_meta_value( string $key, $value, string $language ) {
		if ( '_elementor_data' === $key && is_string( $value ) ) {
			return self::localize_json_value( $value, $language );
		}

		if ( is_string( $value ) ) {
			$decoded = self::maybe_unserialize_value( $value );
			if ( is_array( $decoded ) || is_object( $decoded ) ) {
				return self::maybe_serialize_value( self::localize_structured_meta_value( $key, $decoded, $language ) );
			}
		}

		return is_array( $value ) || is_object( $value )
			? self::localize_structured_meta_value( $key, $value, $language )
			: $value;
	}

	public static function localize_json_value( string $json, string $language ): string {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return $json;
		}

		$localized = ValueLocalizer::walk( $decoded, $language, self::policy() );
		$encoded   = function_exists( 'wp_json_encode' ) ? wp_json_encode( $localized ) : json_encode( $localized );

		return is_string( $encoded ) ? $encoded : $json;
	}

	/**
	 * @param mixed               $value
	 * @param int|string          $post_id
	 * @param mixed               $field
	 * @return mixed
	 */
	public static function localize_acf_value( $value, $post_id, $field ) {
		if ( ! is_array( $field ) ) {
			return $value;
		}

		$type     = (string) ( $field['type'] ?? '' );
		$language = self::language_for_post( $post_id );

		if ( '' === $language ) {
			return $value;
		}

		if ( in_array( $type, array( 'post_object', 'relationship' ), true ) ) {
			return ValueLocalizer::walk( array( 'post_id' => $value ), $language, self::policy() )['post_id'];
		}
		if ( 'taxonomy' === $type ) {
			return ValueLocalizer::walk( array( 'term_id' => $value ), $language, self::policy() )['term_id'];
		}
		if ( 'page_link' === $type ) {
			if ( is_string( $value ) ) {
				return ValueLocalizer::url( $value, $language );
			}
			return ValueLocalizer::walk( array( 'post_id' => $value ), $language, self::policy() )['post_id'];
		}

		return $value;
	}

	/** @return array<int,string> */
	private static function localized_meta_keys(): array {
		return array(
			'_elementor_data',
			'_elementor_page_settings',
			'_elementor_conditions',
			'_fl_builder_data',
			'_fl_builder_draft',
		);
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function localize_structured_meta_value( string $key, $value, string $language ) {
		$localized = ValueLocalizer::walk( $value, $language, self::policy() );

		return '_elementor_conditions' === $key
			? self::localize_elementor_conditions( $localized, $language )
			: $localized;
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function localize_elementor_conditions( $value, string $language ) {
		if ( is_object( $value ) ) {
			$result = clone $value;
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$result->{$key} = self::localize_elementor_conditions( $item, $language );
			}
			return $result;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$result = array();
		foreach ( $value as $key => $item ) {
			$result[ $key ] = self::localize_elementor_conditions( $item, $language );
		}

		$kind = self::elementor_condition_id_kind( $result );
		if ( null !== $kind && isset( $result['sub_id'] ) && is_numeric( $result['sub_id'] ) ) {
			$localized        = 'term' === $kind
				? ValueLocalizer::term_id( (int) $result['sub_id'], $language )
				: ValueLocalizer::post_id( (int) $result['sub_id'], $language );
			$result['sub_id'] = is_string( $result['sub_id'] ) ? (string) $localized : $localized;
		}

		if ( null !== $kind && self::is_list( $result ) ) {
			foreach ( $result as $index => $item ) {
				if ( ! is_numeric( $item ) ) {
					continue;
				}
				$localized        = 'term' === $kind
					? ValueLocalizer::term_id( (int) $item, $language )
					: ValueLocalizer::post_id( (int) $item, $language );
				$result[ $index ] = is_string( $item ) ? (string) $localized : $localized;
			}
		}

		return $result;
	}

	/** @param array<mixed> $condition */
	private static function elementor_condition_id_kind( array $condition ): ?string {
		$tokens = array();
		foreach ( array( 'name', 'sub_name', 'type', 'taxonomy', 'object' ) as $key ) {
			if ( isset( $condition[ $key ] ) && is_scalar( $condition[ $key ] ) ) {
				$tokens[] = strtolower( (string) $condition[ $key ] );
			}
		}

		if ( self::is_list( $condition ) ) {
			foreach ( $condition as $item ) {
				if ( is_scalar( $item ) && ! is_numeric( $item ) ) {
					$tokens[] = strtolower( (string) $item );
				}
			}
		}

		$text = implode( '|', $tokens );
		if ( preg_match( '/(^|\\|)(term|taxonomy|category|categories|tag|tags|product_cat|product_tag)(\\||$)/', $text ) ) {
			return 'term';
		}
		if ( preg_match( '/(^|\\|)(post|page|product|singular|template|popup)(\\||$)/', $text ) ) {
			return 'post';
		}

		return null;
	}

	/** @param array<mixed> $value */
	private static function is_list( array $value ): bool {
		return function_exists( 'array_is_list' ) ? array_is_list( $value ) : array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * @return mixed
	 */
	private static function maybe_unserialize_value( string $value ) {
		if ( function_exists( 'maybe_unserialize' ) ) {
			return maybe_unserialize( $value );
		}

		return @unserialize( $value, array( 'allowed_classes' => false ) );
	}

	/**
	 * @param mixed $value
	 */
	private static function maybe_serialize_value( $value ): string {
		return function_exists( 'maybe_serialize' ) ? maybe_serialize( $value ) : serialize( $value );
	}

	/**
	 * WordPress meta APIs unslash incoming values before storage, so structured
	 * strings such as JSON and serialized payloads must be slashed to round trip.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function slash_meta_value( $value ) {
		if ( is_string( $value ) ) {
			return function_exists( 'wp_slash' ) ? wp_slash( $value ) : addslashes( $value );
		}
		if ( is_array( $value ) ) {
			$slashed = array();
			foreach ( $value as $key => $item ) {
				$slashed[ $key ] = self::slash_meta_value( $item );
			}
			return $slashed;
		}
		if ( is_object( $value ) ) {
			$slashed = clone $value;
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$slashed->{$key} = self::slash_meta_value( $item );
			}
			return $slashed;
		}

		return $value;
	}

	/** @param int|string $post_id */
	private static function language_for_post( $post_id ): string {
		if ( is_numeric( $post_id ) ) {
			$language = TranslationGroups::get_language( (int) $post_id );
			if ( is_string( $language ) && '' !== $language ) {
				return $language;
			}
		}

		return CurrentLanguage::code();
	}
}
