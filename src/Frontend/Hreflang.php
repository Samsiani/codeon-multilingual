<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * Emits hreflang link tags in <head> for every active language plus x-default.
 *
 * URL resolution piggybacks on LanguageSwitcher::url_for_language — same logic
 * (current view in target lang) so the switcher and hreflang stay aligned.
 * Single query per page (sibling lookup) thanks to TranslationGroups memo.
 */
final class Hreflang {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'wp_head', array( self::class, 'render' ), 1 );
	}

	public static function render(): void {
		// Only emit on pages with a meaningful alternate (skip admin, feeds, etc).
		if ( is_admin() || is_feed() || is_robots() || is_trackback() ) {
			return;
		}

		$languages = Languages::active();
		if ( count( $languages ) <= 1 ) {
			return;
		}

		$urls    = array();
		$default = Languages::default_code();

		foreach ( $languages as $code => $lang ) {
			$urls[ $code ] = array(
				'url'      => LanguageSwitcher::url_for_language( $code ),
				'hreflang' => self::locale_to_hreflang( (string) $lang->locale, $code ),
			);
		}

		echo "\n<!-- CodeOn Multilingual: hreflang -->\n";
		foreach ( $urls as $entry ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $entry['hreflang'] ),
				esc_url( $entry['url'] )
			);
		}
		if ( isset( $urls[ $default ] ) ) {
			printf(
				'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
				esc_url( $urls[ $default ]['url'] )
			);
		}
		echo "<!-- /CodeOn Multilingual -->\n";
	}

	/**
	 * Convert a WordPress locale (en_US, pt_BR) to a BCP 47 hreflang tag (en-US, pt-BR).
	 * Falls back to the bare language code when the locale is empty.
	 */
	public static function locale_to_hreflang( string $locale, string $fallback ): string {
		if ( '' === $locale ) {
			return $fallback;
		}
		return str_replace( '_', '-', $locale );
	}
}
