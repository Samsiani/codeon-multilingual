<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Rest;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoints for the inline strings editor.
 *
 *   POST /wp-json/cml/v1/strings/<id>/translations
 *     body: { language: "ka", translation: "..." }
 *     - Empty translation deletes the row.
 *     - REPLACE INTO upserts, flushes the compiled string cache so the new
 *       value is live on the next frontend hit.
 *
 * manage_options-only. Uses WP's built-in REST nonce (X-WP-Nonce header) for
 * CSRF protection — same flow as any block-editor save.
 */
final class StringsApi {

	public const NAMESPACE = 'cml/v1';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/strings/(?P<id>\d+)/translations',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'save_translation' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'id'          => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'language'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'translation' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public static function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_translation( WP_REST_Request $request ) {
		$id          = (int) $request['id'];
		$language    = (string) $request->get_param( 'language' );
		$translation = (string) $request->get_param( 'translation' );

		if ( $id <= 0 ) {
			return new WP_Error( 'cml_invalid_id', __( 'Invalid string id.', 'codeon-multilingual' ), array( 'status' => 400 ) );
		}
		if ( ! Languages::exists_and_active( $language ) ) {
			return new WP_Error( 'cml_invalid_language', __( 'Language not active.', 'codeon-multilingual' ), array( 'status' => 400 ) );
		}
		if ( Languages::is_default( $language ) ) {
			return new WP_Error( 'cml_default_lang', __( 'Cannot translate into the default language.', 'codeon-multilingual' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}cml_strings WHERE id = %d", $id )
		);
		if ( ! $exists ) {
			return new WP_Error( 'cml_not_found', __( 'String not found.', 'codeon-multilingual' ), array( 'status' => 404 ) );
		}

		if ( '' === trim( $translation ) ) {
			$wpdb->delete(
				$wpdb->prefix . 'cml_string_translations',
				array( 'string_id' => $id, 'language' => $language ),
				array( '%d', '%s' )
			);
			$action = 'deleted';
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"REPLACE INTO {$wpdb->prefix}cml_string_translations (string_id, language, translation, updated_at) VALUES (%d, %s, %s, %d)",
					$id,
					$language,
					$translation,
					time()
				)
			);
			$action = 'saved';
		}

		StringTranslator::flush_cache();

		$translated_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}cml_string_translations WHERE string_id = %d",
				$id
			)
		);

		return rest_ensure_response(
			array(
				'action'           => $action,
				'string_id'        => $id,
				'language'         => $language,
				'translation'      => 'deleted' === $action ? '' : $translation,
				'translated_count' => $translated_count,
			)
		);
	}
}
