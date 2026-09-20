<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Url;

use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * Language detection for AJAX requests.
 *
 * Router deliberately skips AJAX: /wp-admin/admin-ajax.php carries no language
 * segment, so there is nothing for SubdirectoryStrategy to parse and stripping
 * the URI would be meaningless. The consequence was that CurrentLanguage fell
 * through to the site default for every AJAX call — a visitor reading /en/ and
 * paging a listing over AJAX got the response rendered in the default language.
 *
 * admin-ajax.php also serves the front end, so the answer cannot come from the
 * logged-in user's admin profile (that is is_admin_screen()'s job). It has to
 * come from the request itself, in order of how explicit it is:
 *
 *   1. an explicit parameter — cml_lang, or lang as REST already uses;
 *   2. the X-CodeOn-Language header, mirroring StoreApiLanguage;
 *   3. the request's own URL, which is how ?wc-ajax= calls carry /en/;
 *   4. the Referer's language prefix — the page the call was made from.
 *
 * $_GET and $_POST are read directly rather than $_REQUEST: the language is
 * resolved during plugins_loaded and WordPress only rebuilds $_REQUEST after
 * that, so a parameter read from $_REQUEST here is silently missing.
 *
 * A Referer is only trusted when it points at this site's front end. Another
 * host has no say in our language, and a wp-admin Referer means a genuine
 * back-end call, which keeps the previous behaviour (the default language).
 */
final class AjaxLanguage {

	private const HEADER = 'HTTP_X_CODEON_LANGUAGE';

	/** Checked in order; the first active language wins. */
	private const PARAM_KEYS = array( 'cml_lang', 'lang' );

	private const CODE_REGEX = '#^[a-z]{2,3}(?:-[a-z0-9]+)?$#';

	/**
	 * The language this AJAX request belongs to, or null when the request says
	 * nothing about it and the caller should keep its own fallback.
	 */
	public static function detect(): ?string {
		if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
			return null;
		}

		$explicit = self::explicit_language();
		if ( null !== $explicit ) {
			return $explicit;
		}

		// ?wc-ajax= calls are made against the front-end URL, prefix included.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is parsed for a language prefix only.
		$own = self::url_language( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( null !== $own ) {
			return $own;
		}

		return self::referer_language();
	}

	/**
	 * A parameter or header the caller set on purpose.
	 */
	private static function explicit_language(): ?string {
		foreach ( self::PARAM_KEYS as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a language hint is not a state change, so it needs no nonce; read directly because $_REQUEST is not rebuilt yet, and validated against active languages below.
			$value = $_GET[ $key ] ?? $_POST[ $key ] ?? null;
			$code  = self::active_code( is_string( $value ) ? $value : null );
			if ( null !== $code ) {
				return $code;
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against active languages below.
		$header = $_SERVER[ self::HEADER ] ?? null;
		return self::active_code( is_string( $header ) ? $header : null );
	}

	/**
	 * The language prefix of the page this AJAX call was made from.
	 */
	private static function referer_language(): ?string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as a URL only; the language it yields is validated.
		$referer = (string) ( $_SERVER['HTTP_REFERER'] ?? '' );
		if ( '' === trim( $referer ) ) {
			return null;
		}
		if ( ! self::is_same_host( $referer ) ) {
			return null;
		}
		if ( self::is_admin_url( $referer ) ) {
			return null;
		}

		return self::url_language( $referer );
	}

	private static function url_language( string $url ): ?string {
		if ( '' === trim( $url ) ) {
			return null;
		}
		return ( new SubdirectoryStrategy() )->detect_in_url( $url );
	}

	/**
	 * Only this site's own pages get to name the language.
	 */
	private static function is_same_host( string $url ): bool {
		$host = self::url_host( $url );
		if ( '' === $host ) {
			// A Referer is always absolute; anything else is not one we can trust.
			return false;
		}
		return $host === self::home_host();
	}

	/**
	 * True for /wp-admin/… and /wp-login.php — a genuine back-end caller, whose
	 * language is not the front end's to decide.
	 */
	private static function is_admin_url( string $url ): bool {
		$path = self::url_path( $url );
		if ( '' === $path ) {
			return false;
		}

		$home_path = self::home_path();
		if ( '' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = '/' . ltrim( $path, '/' );

		return str_starts_with( $path, '/wp-admin/' )
			|| '/wp-admin' === rtrim( $path, '/' )
			|| str_starts_with( $path, '/wp-login.php' );
	}

	/**
	 * Normalise a candidate to an active language code, or null.
	 */
	private static function active_code( ?string $candidate ): ?string {
		if ( null === $candidate ) {
			return null;
		}

		$code = strtolower( str_replace( '_', '-', trim( $candidate ) ) );
		if ( '' === $code || ! preg_match( self::CODE_REGEX, $code ) ) {
			return null;
		}

		return Languages::exists_and_active( $code ) ? $code : null;
	}

	private static function home_host(): string {
		return self::url_host( (string) get_option( 'home', '' ) );
	}

	private static function home_path(): string {
		return rtrim( self::url_path( (string) get_option( 'home', '' ) ), '/' );
	}

	private static function url_host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	private static function url_path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}
}
