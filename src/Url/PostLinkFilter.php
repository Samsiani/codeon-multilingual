<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Url;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use WP_Post;

/**
 * Permalink filter for translatable posts.
 *
 * Semantics differ from home_url: a post's URL must reflect that POST's language,
 * not the current request's. So a Georgian post linked from an English archive
 * page still resolves to /ka/about-us/.
 *
 * Router's home_url filter may have already prepended the CURRENT language; we
 * strip any registered-language prefix and re-add the post's own language.
 */
final class PostLinkFilter {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'post_link', array( self::class, 'filter' ), 10, 2 );
		add_filter( 'page_link', array( self::class, 'filter' ), 10, 2 );
		add_filter( 'post_type_link', array( self::class, 'filter' ), 10, 2 );
		add_filter( 'attachment_link', array( self::class, 'filter' ), 10, 2 );
	}

	/**
	 * @param string|null      $link
	 * @param int|WP_Post|null $post
	 */
	public static function filter( $link, $post ): string {
		$link = (string) $link;
		if ( '' === $link ) {
			return $link;
		}

		$post_id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;
		if ( $post_id <= 0 ) {
			return $link;
		}

		$post_lang = TranslationGroups::get_language( $post_id ) ?? Languages::default_code();

		$stripped = Router::strip_lang_prefix( $link );

		if ( Languages::is_default( $post_lang ) ) {
			return $stripped;
		}

		// Filter applies in admin too — the "Permalink:" preview on the edit
		// screen and any "View Post" link must always reflect the post's own
		// language, not the admin user's current request language.
		return Router::with_lang( $stripped, $post_lang );
	}
}
