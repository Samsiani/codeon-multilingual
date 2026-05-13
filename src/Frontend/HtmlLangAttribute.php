<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * Overrides the <html lang="..."> attribute via the language_attributes filter
 * so it reflects the language of the current view rather than the site locale.
 *
 * The regex must NOT require whitespace before `lang=` — WP's typical output
 * is just `lang="xx-XX"` with no leading space when no other attributes are
 * present. \b (word boundary) handles both lead-of-string and mid-string.
 *
 * Admin context preserves WP's lang attribute (it follows admin locale, not
 * the current frontend view).
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

		// Replace any existing lang="..." attribute, regardless of leading whitespace.
		if ( preg_match( '/\blang="[^"]*"/', $output ) ) {
			$output = (string) preg_replace(
				'/\blang="[^"]*"/',
				'lang="' . esc_attr( $bcp ) . '"',
				$output,
				1
			);
		} else {
			$trimmed = trim( $output );
			$output  = ( '' === $trimmed ? '' : $trimmed . ' ' ) . 'lang="' . esc_attr( $bcp ) . '"';
		}

		// Mirror RTL via dir attribute. Same regex shape, same boundary handling.
		if ( 1 === (int) $lang->rtl ) {
			if ( preg_match( '/\bdir="[^"]*"/', $output ) ) {
				$output = (string) preg_replace(
					'/\bdir="[^"]*"/',
					'dir="rtl"',
					$output,
					1
				);
			} else {
				$output = trim( $output ) . ' dir="rtl"';
			}
		}

		return $output;
	}
}
