<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

/**
 * Read-side service over wp_cml_languages.
 *
 * Two-layer cache: request-local static + persistent object cache (6h).
 * A single SELECT serves every lookup made during a request.
 *
 * Write-side (admin add/edit/delete) arrives with the languages settings form;
 * mutators MUST call self::flush_cache() to keep both layers consistent.
 */
final class Languages {

	private const CACHE_GROUP = 'cml';
	private const CACHE_KEY   = 'languages_all_v1';
	private const CACHE_TTL   = 6 * HOUR_IN_SECONDS;

	/** @var array<string, object>|null */
	private static ?array $request_cache = null;

	/**
	 * All configured languages keyed by code, ordered by position.
	 *
	 * @return array<string, object>
	 */
	public static function all(): array {
		if ( null !== self::$request_cache ) {
			return self::$request_cache;
		}

		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			self::$request_cache = $cached;
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cml_languages';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY position ASC, code ASC" );

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ $row->code ] = $row;
			}
		}

		wp_cache_set( self::CACHE_KEY, $map, self::CACHE_GROUP, self::CACHE_TTL );
		self::$request_cache = $map;
		return $map;
	}

	/**
	 * @return array<string, object>
	 */
	public static function active(): array {
		return array_filter( self::all(), static fn( $l ): bool => 1 === (int) $l->active );
	}

	public static function get( string $code ): ?object {
		$all = self::all();
		return $all[ $code ] ?? null;
	}

	public static function exists( string $code ): bool {
		return null !== self::get( $code );
	}

	public static function exists_and_active( string $code ): bool {
		$lang = self::get( $code );
		return null !== $lang && 1 === (int) $lang->active;
	}

	public static function get_default(): ?object {
		foreach ( self::all() as $lang ) {
			if ( 1 === (int) $lang->is_default ) {
				return $lang;
			}
		}
		$all = self::all();
		return array() === $all ? null : reset( $all );
	}

	public static function default_code(): string {
		$default = self::get_default();
		return $default ? $default->code : 'en';
	}

	public static function is_default( string $code ): bool {
		return $code === self::default_code();
	}

	/**
	 * @return array<int, string>
	 */
	public static function active_codes(): array {
		return array_keys( self::active() );
	}

	public static function flush_cache(): void {
		self::$request_cache = null;
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}
}
