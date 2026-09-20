<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;

/**
 * Tags front-end admin-ajax.php calls with the page's language.
 *
 * AjaxLanguage can already recover the language from the Referer, and browsers
 * send the full path on same-origin requests by default. That falls apart when
 * a site ships a stricter Referrer-Policy (origin, no-referrer), so the page
 * states its language outright: a jQuery prefilter appends cml_lang to every
 * same-origin admin-ajax.php request.
 *
 * Deliberately narrow:
 *   - only the front end, so nothing in wp-admin changes;
 *   - only when the page is NOT in the default language, so a default-language
 *     site sends exactly the requests it sends today;
 *   - only admin-ajax.php URLs, because ?wc-ajax= calls already carry /en/ in
 *     their path and REST has its own lang parameter;
 *   - only if jQuery is already on the page — we never load it for this.
 *
 * The parameter goes in the query string rather than the request body because
 * a POST body may be a string, an object or FormData, and rewriting it risks
 * breaking the payload. AjaxLanguage reads $_GET and $_POST alike.
 *
 * Filter `cml_ajax_language_param_enabled` turns the whole thing off.
 */
final class AjaxLangParam {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
	}

	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		$code = CurrentLanguage::code();
		if ( Languages::is_default( $code ) ) {
			return;
		}
		if ( ! apply_filters( 'cml_ajax_language_param_enabled', true, $code ) ) {
			return;
		}
		if ( ! wp_script_is( 'jquery-core', 'enqueued' ) && ! wp_script_is( 'jquery', 'enqueued' ) ) {
			return;
		}

		wp_add_inline_script( 'jquery-core', self::script( $code ), 'after' );
	}

	private static function script( string $code ): string {
		return sprintf(
			'(function(j,l){if(!j||!j.ajaxPrefilter){return;}'
			. 'j.ajaxPrefilter(function(o){'
			. 'if(!o||o.crossDomain){return;}'
			. 'var u=String(o.url||"");'
			. 'if(u.indexOf("admin-ajax.php")===-1){return;}'
			. 'if(/^[a-z][a-z0-9+.-]*:\/\//i.test(u)&&u.indexOf(window.location.origin)!==0){return;}'
			. 'if(/[?&]cml_lang=/.test(u)){return;}'
			. 'o.url=u+(u.indexOf("?")===-1?"?":"&")+"cml_lang="+encodeURIComponent(l);'
			. '});})(window.jQuery,%s);',
			wp_json_encode( $code )
		);
	}
}
