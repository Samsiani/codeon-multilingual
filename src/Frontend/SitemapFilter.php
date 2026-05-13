<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

/**
 * Makes WordPress's native sitemaps language-aware.
 *
 * Without intervention, PostsClauses/TermsClauses scope the sitemap query to
 * the request's current language — but the sitemap is meant to enumerate the
 * entire site so search engines can index every language. We pass our
 * `cml_skip_filter` arg so the JOIN no-ops; each emitted permalink is then
 * filtered by PostLinkFilter/TermLinkFilter to its own language URL.
 *
 * Third-party SEO sitemaps (Yoast, RankMath, AIOSEO) have their own filters
 * — those integrations land in later iterations.
 */
final class SitemapFilter {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'wp_sitemaps_posts_query_args',      array( self::class, 'skip_lang_filter' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( self::class, 'skip_lang_filter' ) );
		add_filter( 'wp_sitemaps_users_query_args',      array( self::class, 'skip_lang_filter' ) );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function skip_lang_filter( array $args ): array {
		$args['cml_skip_filter'] = true;
		return $args;
	}
}
