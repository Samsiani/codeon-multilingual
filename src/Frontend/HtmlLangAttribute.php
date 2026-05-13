<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * Overrides the <html lang="..."> attribute via the language_attributes filter
 * so it reflects the language of the current view rather than the site locale.
 *
 * Skipped in admin — admin always uses the user's WP locale.
 */
final class HtmlLangAttribute {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'language_attributes', array( self::class, 'filter' ), 10, 1 );
	}

	public static function filter( string $output ): string {
		if ( is_admin() ) {
			return $output;
		}

		$code = CurrentLanguage::code();
		$lang = Languages::get( $code );
		if ( ! $lang ) {
			return $output;
		}

		$bcp = Hreflang::locale_to_hreflang( (string) $lang->locale, $code );

		$replaced = preg_replace(
			'/(\s)lang="[^"]*"/',
			'$1lang="' . esc_attr( $bcp ) . '"',
			$output,
			1
		);

		if ( null === $replaced ) {
			return $output;
		}

		// If there was no lang attribute at all (rare), append one.
		if ( false === strpos( $replaced, 'lang=' ) ) {
			$replaced = trim( $replaced ) . ' lang="' . esc_attr( $bcp ) . '"';
		}

		// Update the dir attribute to honour RTL languages.
		if ( 1 === (int) $lang->rtl ) {
			if ( false !== strpos( $replaced, 'dir=' ) ) {
				$replaced = preg_replace( '/(\s)dir="[^"]*"/', '$1dir="rtl"', $replaced );
			} else {
				$replaced = trim( (string) $replaced ) . ' dir="rtl"';
			}
		}

		return (string) $replaced;
	}
}
