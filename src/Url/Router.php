<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Url;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * Front-end URL routing.
 *
 * Detection runs at plugins_loaded p1 so the language is known before WP_Rewrite
 * parses the request.
 *
 * Output filters here handle URLs whose language is the CURRENT request's:
 * home_url, archive links, feed, search. Post-specific permalinks (post_link,
 * page_link, post_type_link, attachment_link) are handled by PostLinkFilter
 * because they must use the POST's language, not the current one.
 *
 * All filtered URLs are memoized per request — pages with many home_url() calls
 * pay the regex cost once per unique URL.
 */
final class Router {

	private static ?RoutingStrategy $strategy = null;
	private static bool $registered           = false;
	private static bool $filtering            = false;

	/** @var array<string, string> */
	private static array $build_cache = array();

	/** @var array<string, string> */
	private static array $strip_cache = array();

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		self::$strategy = new SubdirectoryStrategy();

		add_action( 'plugins_loaded', array( self::class, 'on_request' ), 1 );

		add_filter( 'home_url', array( self::class, 'filter_url' ), 10, 1 );
		add_filter( 'day_link', array( self::class, 'filter_url' ), 10, 1 );
		add_filter( 'month_link', array( self::class, 'filter_url' ), 10, 1 );
		add_filter( 'year_link', array( self::class, 'filter_url' ), 10, 1 );
		add_filter( 'feed_link', array( self::class, 'filter_url' ), 10, 1 );
		add_filter( 'search_link', array( self::class, 'filter_url' ), 10, 1 );
	}

	public static function on_request(): void {
		if ( self::is_non_frontend_request() ) {
			return;
		}
		if ( null === self::$strategy ) {
			return;
		}

		$lang = self::$strategy->detect();
		if ( null === $lang ) {
			return;
		}

		CurrentLanguage::set( $lang );
		self::$strategy->strip_from_request();

		// We've virtually stripped the /code/ prefix from REQUEST_URI; WP's
		// canonical-redirect logic compares the (modified) REQUEST_URI against
		// the URL it thinks is canonical (built via home_url() which our filter
		// prepends with /code/), sees a mismatch, and 301s back to the prefixed
		// form — creating an infinite redirect loop. Suppress canonical entirely
		// on language-prefixed requests.
		add_filter( 'redirect_canonical', '__return_false' );
	}

	public static function filter_url( string $url ): string {
		if ( self::$filtering || is_admin() ) {
			return $url;
		}
		$code = CurrentLanguage::code();
		if ( Languages::is_default( $code ) ) {
			return $url;
		}
		return self::with_lang( $url, $code );
	}

	public static function with_lang( string $url, string $lang ): string {
		if ( null === self::$strategy ) {
			return $url;
		}
		$key = $url . '|' . $lang;
		if ( isset( self::$build_cache[ $key ] ) ) {
			return self::$build_cache[ $key ];
		}

		self::$filtering = true;
		$out             = self::$strategy->build_url( $url, $lang );
		self::$filtering = false;

		self::$build_cache[ $key ] = $out;
		return $out;
	}

	public static function strip_lang_prefix( string $url ): string {
		if ( null === self::$strategy ) {
			return $url;
		}
		if ( isset( self::$strip_cache[ $url ] ) ) {
			return self::$strip_cache[ $url ];
		}

		self::$filtering = true;
		$out             = self::$strategy->strip_lang_prefix( $url );
		self::$filtering = false;

		self::$strip_cache[ $url ] = $out;
		return $out;
	}

	private static function is_non_frontend_request(): bool {
		if ( is_admin() ) {
			return true;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		if ( str_contains( $uri, '/wp-json/' ) || str_contains( $uri, 'rest_route=' ) ) {
			return true;
		}
		return false;
	}
}
