<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

/**
 * Translation-group lookups for posts AND terms, request-memoised.
 *
 * Data is cheap to read (PRIMARY KEY on object_id, KEY on group_id) but read often:
 * permalink filters, hreflang output, switchers and sibling resolution all need
 * (object_id -> group + language) or (group -> sibling map).
 *
 * Two parallel caches keep post and term lookups isolated — group_id 42 in
 * post_language is unrelated to group_id 42 in term_language.
 *
 * No persistent layer — group membership changes whenever a translation is
 * created/deleted, so request scope is the right invalidation boundary.
 */
final class TranslationGroups {

	/** @var array<int, array{group_id:?int, language:?string}> */
	private static array $post_cache = [];

	/** @var array<int, array<int, string>> */
	private static array $post_sibling_cache = [];

	/** @var array<int, array{group_id:?int, language:?string}> */
	private static array $term_cache = [];

	/** @var array<int, array<int, string>> */
	private static array $term_sibling_cache = [];

	// ---- Posts ------------------------------------------------------------

	public static function get_group_id( int $post_id ): ?int {
		return self::get_post_row( $post_id )['group_id'];
	}

	public static function get_language( int $post_id ): ?string {
		return self::get_post_row( $post_id )['language'];
	}

	/**
	 * @return array{group_id:?int, language:?string}
	 */
	private static function get_post_row( int $post_id ): array {
		if ( isset( self::$post_cache[ $post_id ] ) ) {
			return self::$post_cache[ $post_id ];
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT group_id, language FROM {$wpdb->prefix}cml_post_language WHERE post_id = %d LIMIT 1",
				$post_id
			)
		);

		$result = null === $row
			? [ 'group_id' => null, 'language' => null ]
			: [
				'group_id' => (int) $row->group_id,
				'language' => (string) $row->language,
			];

		self::$post_cache[ $post_id ] = $result;
		return $result;
	}

	/**
	 * @param array<int> $post_ids
	 */
	public static function preload( array $post_ids ): void {
		$unknown = [];
		foreach ( $post_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! isset( self::$post_cache[ $id ] ) ) {
				$unknown[] = $id;
			}
		}
		if ( [] === $unknown ) {
			return;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $unknown ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, group_id, language FROM {$wpdb->prefix}cml_post_language WHERE post_id IN ({$placeholders})",
				...$unknown
			)
		);

		foreach ( $unknown as $id ) {
			self::$post_cache[ $id ] = [ 'group_id' => null, 'language' => null ];
		}
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				self::$post_cache[ (int) $row->post_id ] = [
					'group_id' => (int) $row->group_id,
					'language' => (string) $row->language,
				];
			}
		}
	}

	/**
	 * @return array<int, string> post_id => language
	 */
	public static function get_siblings( int $group_id ): array {
		if ( isset( self::$post_sibling_cache[ $group_id ] ) ) {
			return self::$post_sibling_cache[ $group_id ];
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, language FROM {$wpdb->prefix}cml_post_language WHERE group_id = %d",
				$group_id
			)
		);

		$map = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (int) $row->post_id ] = (string) $row->language;
			}
		}

		self::$post_sibling_cache[ $group_id ] = $map;
		return $map;
	}

	public static function invalidate( int $post_id ): void {
		if ( ! isset( self::$post_cache[ $post_id ] ) ) {
			return;
		}
		$group = self::$post_cache[ $post_id ]['group_id'];
		unset( self::$post_cache[ $post_id ] );
		if ( null !== $group ) {
			unset( self::$post_sibling_cache[ $group ] );
		}
	}

	// ---- Terms -----------------------------------------------------------

	public static function get_term_group_id( int $term_id ): ?int {
		return self::get_term_row( $term_id )['group_id'];
	}

	public static function get_term_language( int $term_id ): ?string {
		return self::get_term_row( $term_id )['language'];
	}

	/**
	 * @return array{group_id:?int, language:?string}
	 */
	private static function get_term_row( int $term_id ): array {
		if ( isset( self::$term_cache[ $term_id ] ) ) {
			return self::$term_cache[ $term_id ];
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT group_id, language FROM {$wpdb->prefix}cml_term_language WHERE term_id = %d LIMIT 1",
				$term_id
			)
		);

		$result = null === $row
			? [ 'group_id' => null, 'language' => null ]
			: [
				'group_id' => (int) $row->group_id,
				'language' => (string) $row->language,
			];

		self::$term_cache[ $term_id ] = $result;
		return $result;
	}

	/**
	 * @return array<int, string> term_id => language
	 */
	public static function get_term_siblings( int $group_id ): array {
		if ( isset( self::$term_sibling_cache[ $group_id ] ) ) {
			return self::$term_sibling_cache[ $group_id ];
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, language FROM {$wpdb->prefix}cml_term_language WHERE group_id = %d",
				$group_id
			)
		);

		$map = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (int) $row->term_id ] = (string) $row->language;
			}
		}

		self::$term_sibling_cache[ $group_id ] = $map;
		return $map;
	}

	/**
	 * @param array<int> $term_ids
	 */
	public static function preload_terms( array $term_ids ): void {
		$unknown = [];
		foreach ( $term_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! isset( self::$term_cache[ $id ] ) ) {
				$unknown[] = $id;
			}
		}
		if ( [] === $unknown ) {
			return;
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $unknown ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, group_id, language FROM {$wpdb->prefix}cml_term_language WHERE term_id IN ({$placeholders})",
				...$unknown
			)
		);

		foreach ( $unknown as $id ) {
			self::$term_cache[ $id ] = [ 'group_id' => null, 'language' => null ];
		}
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				self::$term_cache[ (int) $row->term_id ] = [
					'group_id' => (int) $row->group_id,
					'language' => (string) $row->language,
				];
			}
		}
	}

	public static function invalidate_term( int $term_id ): void {
		if ( ! isset( self::$term_cache[ $term_id ] ) ) {
			return;
		}
		$group = self::$term_cache[ $term_id ]['group_id'];
		unset( self::$term_cache[ $term_id ] );
		if ( null !== $group ) {
			unset( self::$term_sibling_cache[ $group ] );
		}
	}

	// ---- Maintenance -----------------------------------------------------

	public static function flush(): void {
		self::$post_cache         = [];
		self::$post_sibling_cache = [];
		self::$term_cache         = [];
		self::$term_sibling_cache = [];
	}
}
