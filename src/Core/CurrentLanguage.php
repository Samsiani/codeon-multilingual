<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

use Samsiani\CodeonMultilingual\Url\AjaxLanguage;

/**
 * Request-scoped current language holder.
 *
 * Set once by Router on parse-request, read freely thereafter.
 * Falls back to the default language so callers never need null checks.
 *
 * In wp-admin the Router never runs — /wp-admin/ URLs carry no language
 * segment — so that fallback used to report the site default on every admin
 * screen. Anything asking us "which language are we in?" therefore got the
 * default (e.g. Georgian) even for an administrator whose WordPress profile
 * language is English, while WordPress itself rendered the rest of the admin
 * in English. Admin screens now follow the user's own profile language, which
 * is WordPress's own answer to that question (get_user_locale()).
 *
 * AJAX has the same gap for the opposite reason: admin-ajax.php serves the
 * FRONT end too, so the answer is neither the URL (there is no prefix on
 * /wp-admin/admin-ajax.php) nor the user's admin profile — it is whatever the
 * calling page was reading. AjaxLanguage recovers that from the request.
 */
final class CurrentLanguage {

	private static ?string $code = null;

	public static function set( string $code ): void {
		self::$code = $code;
	}

	public static function code(): string {
		if ( null !== self::$code ) {
			return self::$code;
		}

		if ( self::is_ajax_request() ) {
			$ajax_code = AjaxLanguage::detect();
			if ( null !== $ajax_code ) {
				self::$code = $ajax_code;
				return self::$code;
			}
		}

		if ( self::is_admin_screen() ) {
			$admin_code = self::admin_user_code();
			if ( '' !== $admin_code ) {
				self::$code = $admin_code;
				return self::$code;
			}
			// The current user isn't resolvable yet (a call this early in
			// bootstrap can't read user meta). Answer with the default but do
			// NOT memoise it, so the real preference still wins once WordPress
			// has finished loading.
			if ( ! did_action( 'plugins_loaded' ) ) {
				return Languages::default_code();
			}
		}

		self::$code = Languages::default_code();
		return self::$code;
	}

	public static function is_default(): bool {
		return Languages::is_default( self::code() );
	}

	public static function reset(): void {
		self::$code = null;
	}

	/**
	 * True only for real admin screens.
	 *
	 * AJAX and cron are deliberately excluded. admin-ajax.php also serves the
	 * front end — a visitor reading /en/ while a dashboard widget pages itself
	 * over AJAX — and there the request's own language is correct, not the
	 * logged-in user's admin preference. Mirrors LocaleOverride's guards.
	 */
	private static function is_admin_screen(): bool {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return false;
		}
		if ( self::is_ajax_request() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		return true;
	}

	private static function is_ajax_request(): bool {
		return function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
	}

	/**
	 * Map the current admin user's WordPress locale onto an active language.
	 *
	 * Exact locale match first (en_US → en); failing that the bare subtag, so a
	 * user set to en_GB still resolves to an English configured as en_US.
	 * Returns '' when the user isn't known yet or no active language matches —
	 * the caller then keeps the default.
	 */
	private static function admin_user_code(): string {
		if ( ! did_action( 'plugins_loaded' ) ) {
			return '';
		}
		if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'get_user_locale' ) ) {
			return '';
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}

		$locale = trim( (string) get_user_locale( $user_id ) );
		if ( '' === $locale ) {
			return '';
		}

		$subtag   = self::locale_subtag( $locale );
		$fallback = '';

		foreach ( Languages::active() as $lang ) {
			$lang_locale = trim( (string) $lang->locale );
			if ( '' === $lang_locale ) {
				continue;
			}
			if ( $lang_locale === $locale ) {
				return (string) $lang->code;
			}
			if ( '' === $fallback && '' !== $subtag && self::locale_subtag( $lang_locale ) === $subtag ) {
				$fallback = (string) $lang->code;
			}
		}

		return $fallback;
	}

	/**
	 * "en_US" → "en". Lowercased so comparisons are case-insensitive.
	 */
	private static function locale_subtag( string $locale ): string {
		$pos = strpos( $locale, '_' );
		return strtolower( false === $pos ? $locale : substr( $locale, 0, $pos ) );
	}
}
