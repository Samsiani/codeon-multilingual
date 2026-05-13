<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Url;

use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * URL strategy: /{code}/path style.
 *
 * Default language stays at root (configurable later). Sub-directory WP installs
 * are honored — the prefix is inserted *after* the install path, not before host.
 *
 * Detection uses a single regex with a lookahead boundary so paths like
 * /karate-class never get mis-parsed as Georgian + /rate-class.
 */
final class SubdirectoryStrategy implements RoutingStrategy {

	private const LANG_REGEX = '#^/([a-z]{2,3}(?:-[a-z0-9]+)?)(?=/|$)#i';

	private static ?string $home_path_cache = null;

	public function detect(): ?string {
		$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
		$path = $this->strip_install_path( $path );

		if ( ! preg_match( self::LANG_REGEX, $path, $m ) ) {
			return null;
		}

		$code = strtolower( $m[1] );

		if ( ! Languages::exists_and_active( $code ) ) {
			return null;
		}
		if ( Languages::is_default( $code ) ) {
			return null;
		}

		return $code;
	}

	public function strip_from_request(): void {
		$uri       = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$home_path = $this->home_path();

		$rest = $this->strip_install_path( $uri );
		$rest = (string) preg_replace( self::LANG_REGEX, '', $rest, 1 );

		if ( '' === $rest ) {
			$rest = '/';
		} elseif ( '/' !== $rest[0] ) {
			$rest = '/' . $rest;
		}

		$_SERVER['REQUEST_URI'] = $home_path . $rest;
	}

	public function build_url( string $url, string $lang ): string {
		if ( Languages::is_default( $lang ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}

		$home_path = $this->home_path();
		$path      = $parts['path'] ?? '/';

		$existing = '#^' . preg_quote( $home_path, '#' ) . '/[a-z]{2,3}(?:-[a-z0-9]+)?(?=/|$)#i';
		if ( preg_match( $existing, $path ) ) {
			return $url;
		}

		if ( '' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$rest      = substr( $path, strlen( $home_path ) );
			$new_path  = $home_path . '/' . $lang . $rest;
		} else {
			$new_path = '/' . $lang . $path;
		}

		$new_path     = (string) preg_replace( '#/+#', '/', $new_path );
		$parts['path'] = $new_path;

		return $this->unparse_url( $parts );
	}

	public function strip_lang_prefix( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}

		$home_path = $this->home_path();
		$path      = $parts['path'] ?? '/';

		$rest = $path;
		if ( '' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$rest = substr( $path, strlen( $home_path ) );
			if ( '' === $rest ) {
				$rest = '/';
			}
		}

		if ( ! preg_match( self::LANG_REGEX, $rest, $m ) ) {
			return $url;
		}

		$code = strtolower( $m[1] );
		if ( ! Languages::exists_and_active( $code ) ) {
			return $url;
		}

		$stripped = (string) preg_replace( self::LANG_REGEX, '', $rest, 1 );
		if ( '' === $stripped ) {
			$stripped = '/';
		} elseif ( '/' !== $stripped[0] ) {
			$stripped = '/' . $stripped;
		}

		$parts['path'] = $home_path . $stripped;
		return $this->unparse_url( $parts );
	}

	private function strip_install_path( string $path ): string {
		$home_path = $this->home_path();
		if ( '' !== $home_path && str_starts_with( $path, $home_path ) ) {
			return substr( $path, strlen( $home_path ) ) ?: '/';
		}
		return $path;
	}

	private function home_path(): string {
		if ( null !== self::$home_path_cache ) {
			return self::$home_path_cache;
		}
		$home = (string) get_option( 'home' );
		$path = (string) wp_parse_url( $home, PHP_URL_PATH );
		self::$home_path_cache = rtrim( $path, '/' );
		return self::$home_path_cache;
	}

	/**
	 * @param array<string,string|int> $parts
	 */
	private function unparse_url( array $parts ): string {
		$scheme   = isset( $parts['scheme'] )   ? $parts['scheme'] . '://' : '';
		$host     = $parts['host']   ?? '';
		$port     = isset( $parts['port'] )     ? ':' . $parts['port']     : '';
		$user     = $parts['user']   ?? '';
		$pass     = isset( $parts['pass'] )     ? ':' . $parts['pass']     : '';
		$auth     = ( '' !== $user || '' !== $pass ) ? $user . $pass . '@' : '';
		$path     = $parts['path']   ?? '';
		$query    = isset( $parts['query'] )    ? '?' . $parts['query']    : '';
		$fragment = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

		return $scheme . $auth . $host . $port . $path . $query . $fragment;
	}
}
