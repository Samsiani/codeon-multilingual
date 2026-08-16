<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Url;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\Settings;
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

	/** @var array<string, bool> */
	private static array $fallback_cache = array();

	private static ?bool $fallback_setting = null;

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

		// A post with no translation in the language being browsed is still
		// served under that prefix (see PostsClauses' untranslated-content
		// fallback), so its links must carry that prefix too. Otherwise every
		// link out of an /en/ page — a product in a shared catalogue, an
		// invoice, a dashboard row — silently drops the visitor back into the
		// default language.
		//
		// Admin is excluded on purpose: the "Permalink:" preview and "View
		// Post" link must always show the post's own language, not whatever
		// language the admin happens to be browsing in.
		if ( ! is_admin() || wp_doing_ajax() ) {
			$current = CurrentLanguage::code();
			if ( '' !== $current && $current !== $post_lang && self::renders_under( $post_id, $current ) ) {
				return Languages::is_default( $current )
					? $stripped
					: Router::with_lang( $stripped, $current );
			}
		}

		if ( Languages::is_default( $post_lang ) ) {
			return $stripped;
		}

		return Router::with_lang( $stripped, $post_lang );
	}

	/**
	 * Whether $post_id is the record that answers for $lang — i.e. it has no
	 * sibling of its own in that language, so the fallback serves this one.
	 *
	 * When a real translation exists the caller asked for THIS post's link on
	 * purpose, and it keeps its own language.
	 */
	private static function renders_under( int $post_id, string $lang ): bool {
		if ( ! self::fallback_enabled() ) {
			return false;
		}

		$key = $post_id . '|' . $lang;
		if ( isset( self::$fallback_cache[ $key ] ) ) {
			return self::$fallback_cache[ $key ];
		}

		$group_id = TranslationGroups::get_group_id( $post_id );
		if ( null === $group_id ) {
			// Untracked content belongs to the default pool and is shown
			// everywhere, so it follows the request language.
			self::$fallback_cache[ $key ] = true;
			return true;
		}

		$siblings = TranslationGroups::get_siblings( $group_id );
		$has      = in_array( $lang, $siblings, true );

		self::$fallback_cache[ $key ] = ! $has;
		return ! $has;
	}

	private static function fallback_enabled(): bool {
		if ( null === self::$fallback_setting ) {
			self::$fallback_setting = (bool) apply_filters(
				'cml_untranslated_content_fallback',
				(bool) Settings::get( 'untranslated_content_fallback', true )
			);
		}
		return self::$fallback_setting;
	}
}
