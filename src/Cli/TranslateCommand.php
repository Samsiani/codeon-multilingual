<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Cli;

use Samsiani\CodeonMultilingual\Content\PostTranslator;
use Samsiani\CodeonMultilingual\Content\TermTranslator;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use WP_CLI;
use WP_CLI_Command;

/**
 * Duplicate posts and taxonomy terms into a target language.
 *
 * Delegates to PostTranslator::duplicate / TermTranslator::duplicate, which
 * already handle idempotency, group resolution, slug uniqueness scoping, and
 * deep cloning of meta and term relationships. CLI is a thin wrapper that
 * validates the target language is active and reports the new ID + edit URL.
 *
 * Examples
 *
 *     wp cml translate post 123 --to=ka
 *     wp cml translate term 45 --tax=product_cat --to=en
 */
final class TranslateCommand extends WP_CLI_Command {

	/**
	 * Clones a post into the target language. Idempotent.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Source post ID.
	 *
	 * --to=<lang>
	 * : Target language code.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function post( array $args, array $assoc_args ): void {
		$source = isset( $args[0] ) ? (int) $args[0] : 0;
		$target = (string) ( $assoc_args['to'] ?? '' );

		if ( $source <= 0 ) {
			WP_CLI::error( 'Source post ID must be a positive integer.' );
		}
		self::require_active_language( $target );

		$post = get_post( $source );
		if ( ! $post ) {
			WP_CLI::error( "Source post #{$source} does not exist." );
		}

		$existing = TranslationGroups::get_language( $source );
		if ( $existing === $target ) {
			WP_CLI::warning( "Post #{$source} is already in '{$target}'." );
			return;
		}

		$new_id = PostTranslator::duplicate( $source, $target );
		if ( 0 === $new_id ) {
			WP_CLI::error( "Failed to duplicate post #{$source} into '{$target}'." );
		}

		WP_CLI::success(
			"Translation ready: post #{$new_id} ({$target}). Edit: " . get_edit_post_link( $new_id, 'raw' )
		);
	}

	/**
	 * Clones a taxonomy term into the target language. Idempotent.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Source term ID.
	 *
	 * --tax=<taxonomy>
	 * : Taxonomy slug (category, post_tag, product_cat, …).
	 *
	 * --to=<lang>
	 * : Target language code.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function term( array $args, array $assoc_args ): void {
		$source   = isset( $args[0] ) ? (int) $args[0] : 0;
		$taxonomy = (string) ( $assoc_args['tax'] ?? '' );
		$target   = (string) ( $assoc_args['to'] ?? '' );

		if ( $source <= 0 ) {
			WP_CLI::error( 'Source term ID must be a positive integer.' );
		}
		if ( '' === $taxonomy ) {
			WP_CLI::error( '--tax=<taxonomy> is required.' );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			WP_CLI::error( "Unknown taxonomy '{$taxonomy}'." );
		}
		self::require_active_language( $target );

		$term = get_term( $source, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			WP_CLI::error( "Source term #{$source} in '{$taxonomy}' does not exist." );
		}

		$new_id = TermTranslator::duplicate( $source, $taxonomy, $target );
		if ( 0 === $new_id ) {
			WP_CLI::error( "Failed to duplicate term #{$source} ({$taxonomy}) into '{$target}'." );
		}

		WP_CLI::success(
			"Translation ready: term #{$new_id} ({$taxonomy}, {$target}). Edit: " . (string) ( get_edit_term_link( $new_id, $taxonomy ) ?: '(no edit URL)' )
		);
	}

	private static function require_active_language( string $code ): void {
		if ( '' === $code ) {
			WP_CLI::error( '--to=<lang> is required.' );
		}
		if ( ! Languages::exists( $code ) ) {
			WP_CLI::error( "Unknown language '{$code}'. Run `wp cml language list` to see configured codes." );
		}
		if ( ! Languages::exists_and_active( $code ) ) {
			WP_CLI::error( "Language '{$code}' is configured but inactive. Activate it first: wp cml language activate {$code}" );
		}
	}
}
