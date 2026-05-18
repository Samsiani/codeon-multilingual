<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Compat;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;

/**
 * Purges local and popular third-party caches after translation mutations.
 */
final class CachePurge {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'cml_post_translation_created', array( self::class, 'post_translation_created' ), 20, 4 );
		add_action( 'cml_term_translation_created', array( self::class, 'term_translation_created' ), 20, 5 );
		add_action( 'cml_menu_translation_created', array( self::class, 'purge_all' ) );
		add_action( 'cml_menu_translation_synced', array( self::class, 'purge_all' ) );
		add_action( 'cml_language_changed', array( self::class, 'language_changed' ), 20, 2 );
		add_action( 'cml_string_catalog_changed', array( self::class, 'string_catalog_changed' ), 20, 2 );
		add_action( 'cml_migration_imported', array( self::class, 'migration_imported' ), 20, 2 );
		add_action( 'cml_activated', array( self::class, 'purge_all' ) );
		add_action( 'cml_upgraded', array( self::class, 'purge_all' ) );
	}

	public static function post_translation_created( int $target_id, int $source_id, string $language, int $group_id ): void {
		TranslationGroups::invalidate( $target_id );
		TranslationGroups::invalidate( $source_id );

		self::purge_post( $source_id );
		self::purge_post( $target_id );
		self::purge_urls(
			array_filter(
				array(
					get_permalink( $source_id ),
					get_permalink( $target_id ),
				)
			)
		);
	}

	public static function term_translation_created( int $target_id, int $source_id, string $taxonomy, string $language, int $group_id ): void {
		TranslationGroups::invalidate_term( $target_id );
		TranslationGroups::invalidate_term( $source_id );
		self::purge_all();
	}

	public static function language_changed( string $action, string $code ): void {
		unset( $action, $code );

		self::purge_all();
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public static function string_catalog_changed( string $reason, array $context ): void {
		unset( $reason, $context );

		self::purge_all();
	}

	/**
	 * @param array<string,mixed> $result
	 */
	public static function migration_imported( string $source, array $result ): void {
		unset( $source, $result );

		self::purge_all();
	}

	public static function purge_all(): void {
		Languages::flush_cache();
		StringTranslator::flush_cache();
		TranslationGroups::flush();

		do_action( 'litespeed_purge_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	/**
	 * @param array<int,string> $urls
	 */
	private static function purge_urls( array $urls ): void {
		$urls = array_values( array_unique( array_filter( $urls ) ) );
		foreach ( $urls as $url ) {
			do_action( 'litespeed_purge_url', $url );
		}

		if ( function_exists( 'rocket_clean_files' ) && array() !== $urls ) {
			rocket_clean_files( $urls );
		}
	}

	private static function purge_post( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		do_action( 'litespeed_purge_post', $post_id );
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
	}
}
