<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

/**
 * Request-scoped current language holder.
 *
 * Set once by Router on parse-request, read freely thereafter.
 * Falls back to the default language so callers never need null checks.
 */
final class CurrentLanguage {

	private static ?string $code = null;

	public static function set( string $code ): void {
		self::$code = $code;
	}

	public static function code(): string {
		if ( null !== self::$code ) {
			return self::$code;
		}
		self::$code = Languages::default_code();
		return self::$code;
	}

	public static function is_default(): bool {
		return Languages::is_default( self::code() );
	}

	public static function reset(): void {
		self::$code = null;
	}
}
