<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * Language bridge for WooCommerce Store API requests.
 *
 * Store API calls are usually made against /wp-json/wc/store/... while the
 * customer's visible page carries the language context. This bridge keeps the
 * request language aligned for query/string filters without requiring any
 * WooCommerce classes at load or test time.
 */
final class StoreApiLanguage {

	private const HEADER_LANGUAGE = 'X-CodeOn-Language';
	private const HEADER_REFERER  = 'referer';
	private const LANG_REGEX      = '#^[a-z]{2,3}(?:-[a-z0-9]+)?$#i';

	private static bool $registered = false;
	private static bool $store_api_request = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'rest_pre_dispatch', array( self::class, 'detect_language' ), 10, 3 );
	}

	/**
	 * @param mixed $result
	 * @param mixed $server
	 * @param mixed $request WP_REST_Request-like object.
	 * @return mixed
	 */
	public static function detect_language( $result, $server, $request ) {
		unset( $server );

		self::$store_api_request = self::is_store_api_request( $request );
		if ( ! self::$store_api_request ) {
			return $result;
		}

		$language = self::request_language( $request );
		if ( null !== $language ) {
			CurrentLanguage::set( $language );
		}

		return $result;
	}

	public static function is_current_request(): bool {
		return self::$store_api_request;
	}

	/**
	 * @param mixed $request
	 */
	private static function is_store_api_request( $request ): bool {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return false;
		}

		try {
			$route = $request->get_route();
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( ! is_string( $route ) || '' === $route ) {
			return false;
		}

		$route = ltrim( $route, '/' );
		return 'wc/store' === $route || str_starts_with( $route, 'wc/store/' );
	}

	/**
	 * @param mixed $request
	 */
	private static function request_language( $request ): ?string {
		foreach ( self::language_candidates( $request ) as $candidate ) {
			$language = self::active_language_code( $candidate );
			if ( null !== $language ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * @param mixed $request
	 * @return array<int, string|null>
	 */
	private static function language_candidates( $request ): array {
		return array(
			self::request_param( $request, 'lang' ),
			self::request_header( $request, self::HEADER_LANGUAGE ),
			self::referer_language( self::request_header( $request, self::HEADER_REFERER ) ),
		);
	}

	private static function active_language_code( ?string $candidate ): ?string {
		if ( null === $candidate ) {
			return null;
		}

		$code = strtolower( str_replace( '_', '-', trim( $candidate ) ) );
		if ( '' === $code || ! preg_match( self::LANG_REGEX, $code ) ) {
			return null;
		}

		return Languages::exists_and_active( $code ) ? $code : null;
	}

	/**
	 * @param mixed $request
	 */
	private static function request_param( $request, string $name ): ?string {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
			return null;
		}

		try {
			$value = $request->get_param( $name );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_string( $value ) ? $value : null;
	}

	/**
	 * @param mixed $request
	 */
	private static function request_header( $request, string $name ): ?string {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_header' ) ) {
			return null;
		}

		try {
			$value = $request->get_header( $name );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_string( $value ) ? $value : null;
	}

	private static function referer_language( ?string $referer ): ?string {
		if ( null === $referer || '' === trim( $referer ) ) {
			return null;
		}

		$path = self::parse_url_part( $referer, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}

		$path = self::strip_home_path( $path );
		$path = '/' . ltrim( $path, '/' );

		$segment = strtok( trim( $path, '/' ), '/' );
		return is_string( $segment ) ? $segment : null;
	}

	private static function strip_home_path( string $path ): string {
		$home_path = self::home_path();
		if ( '' === $home_path ) {
			return $path;
		}

		if ( $path === $home_path ) {
			return '/';
		}

		if ( str_starts_with( $path, $home_path . '/' ) ) {
			return substr( $path, strlen( $home_path ) ) ?: '/';
		}

		return $path;
	}

	private static function home_path(): string {
		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}

		$home = (string) get_option( 'home', '' );
		if ( '' === $home ) {
			return '';
		}

		$path = self::parse_url_part( $home, PHP_URL_PATH );
		return is_string( $path ) ? rtrim( $path, '/' ) : '';
	}

	/**
	 * @return array<string, mixed>|string|int|false|null
	 */
	private static function parse_url_part( string $url, int $component = -1 ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url, $component );
		}

		return parse_url( $url, $component );
	}
}
