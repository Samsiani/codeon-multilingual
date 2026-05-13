<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * Override WordPress's effective locale on every frontend request so .mo file
 * loading matches the URL's language.
 *
 * Without this, a Georgian-default site visited at /en/foo/ still loads ka_GE
 * .mo files for every textdomain — so themes/plugins render Georgian even
 * though our routing reports we're on the English version. Hooking both
 * 'locale' (used by get_locale + global $locale) and 'determine_locale' (used
 * by _load_textdomain_just_in_time + load_textdomain) covers every code path
 * WP uses to decide which .mo bundle to read.
 *
 * Admin keeps WP's own locale (user/site locale via get_user_locale). AJAX and
 * cron are skipped — they reuse whatever the caller already negotiated.
 */
final class LocaleOverride {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'determine_locale', array( self::class, 'filter' ) );
		add_filter( 'locale',           array( self::class, 'filter' ) );
	}

	/**
	 * @param mixed $locale
	 * @return mixed
	 */
	public static function filter( $locale ) {
		if ( is_admin() ) {
			return $locale;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return $locale;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return $locale;
		}

		$code = CurrentLanguage::code();
		$lang = Languages::get( $code );
		if ( ! $lang ) {
			return $locale;
		}

		$new_locale = trim( (string) $lang->locale );
		return '' !== $new_locale ? $new_locale : $locale;
	}
}
