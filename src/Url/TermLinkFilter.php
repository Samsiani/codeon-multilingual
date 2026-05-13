<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Url;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use WP_Term;

/**
 * Permalink filter for translatable terms.
 *
 * Same semantics as PostLinkFilter: a term's URL reflects the TERM's language,
 * not the current request's. A Georgian category linked from an English archive
 * still resolves to /ka/category/sashobao/.
 */
final class TermLinkFilter {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'term_link', [ self::class, 'filter' ], 10, 3 );
	}

	/**
	 * @param string|null         $link
	 * @param int|WP_Term|object  $term
	 * @param string              $taxonomy
	 */
	public static function filter( $link, $term, $taxonomy ): string {
		$link = (string) $link;
		if ( '' === $link || is_admin() ) {
			return $link;
		}

		$term_id = is_object( $term )
			? (int) ( $term->term_id ?? 0 )
			: (int) $term;
		if ( $term_id <= 0 ) {
			return $link;
		}

		$term_lang = TranslationGroups::get_term_language( $term_id ) ?? Languages::default_code();
		$stripped  = Router::strip_lang_prefix( $link );

		if ( Languages::is_default( $term_lang ) ) {
			return $stripped;
		}

		return Router::with_lang( $stripped, $term_lang );
	}
}
