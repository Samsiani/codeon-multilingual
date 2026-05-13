<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Rest;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use WP_REST_Request;
use WP_REST_Server;

/**
 * REST API language detection.
 *
 * Frontend routing is skipped for REST (URLs like /wp-json/wc/v3/products don't
 * carry a /ka/ prefix). Instead, REST consumers pass ?lang=ka — we read it on
 * rest_pre_dispatch, set CurrentLanguage, and PostsClauses + TermsClauses do
 * the rest automatically because they share the same CurrentLanguage source.
 *
 * lang is also registered as a collection_param on every show_in_rest post-type
 * endpoint so documentation/discovery clients see it.
 */
final class LangParam {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'rest_pre_dispatch', array( self::class, 'detect_language' ), 10, 3 );
		add_action( 'rest_api_init', array( self::class, 'register_collection_params' ), 99 );
	}

	/**
	 * @param mixed $result
	 * @return mixed
	 */
	public static function detect_language( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		$lang = $request->get_param( 'lang' );
		if ( is_string( $lang ) && '' !== $lang ) {
			$code = strtolower( $lang );
			if ( Languages::exists_and_active( $code ) ) {
				CurrentLanguage::set( $code );
			}
		}
		return $result;
	}

	public static function register_collection_params(): void {
		$post_types = get_post_types( array( 'show_in_rest' => true ), 'objects' );
		foreach ( $post_types as $pt ) {
			$rest_base = is_string( $pt->rest_base ) && '' !== $pt->rest_base ? $pt->rest_base : $pt->name;
			add_filter( "rest_{$pt->name}_collection_params", array( self::class, 'add_lang_param' ) );
			unset( $rest_base );
		}

		$taxonomies = get_taxonomies( array( 'show_in_rest' => true ), 'objects' );
		foreach ( $taxonomies as $tax ) {
			add_filter( "rest_{$tax->name}_collection_params", array( self::class, 'add_lang_param' ) );
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $params
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_lang_param( array $params ): array {
		$params['lang'] = array(
			'description' => __( 'Filter results by language code (e.g. en, ka). Defaults to the site default language.', 'codeon-multilingual' ),
			'type'        => 'string',
			'required'    => false,
		);
		return $params;
	}
}
