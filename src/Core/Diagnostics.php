<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

/**
 * Production diagnostics switches and helpers.
 *
 * Kept intentionally small: the setting lives in the existing cml_settings
 * option, while callers opt in to logging explicitly through this class.
 */
final class Diagnostics {

	private const SETTING_DEBUG_LOGGING = 'debug_logging_enabled';

	public static function debug_logging_enabled(): bool {
		return (bool) Settings::get( self::SETTING_DEBUG_LOGGING, false );
	}

	public static function set_debug_logging_enabled( bool $enabled ): void {
		Settings::save(
			array(
				self::SETTING_DEBUG_LOGGING => $enabled,
			)
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( ! self::debug_logging_enabled() ) {
			return;
		}

		$line = '[CodeOn Multilingual] ' . $message;
		if ( array() !== $context ) {
			$encoded = function_exists( 'wp_json_encode' )
				? wp_json_encode( $context )
				: json_encode( $context ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

			if ( is_string( $encoded ) && '' !== $encoded ) {
				$line .= ' ' . $encoded;
			}
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
