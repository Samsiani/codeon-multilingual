<?php
/**
 * PHPStan stub file — declares constants the plugin defines at runtime so
 * static analysis of src/ doesn't see them as "undefined". Never autoloaded;
 * referenced by phpstan.neon.dist via bootstrapFiles.
 */

namespace {
	define( 'CML_VERSION',  '0.0.0-stub' );
	define( 'CML_BUILD_ID', 'stub' );
	define( 'CML_FILE',     '/stub/codeon-multilingual.php' );
	define( 'CML_PATH',     '/stub/' );
	define( 'CML_URL',      'https://stub/' );
	define( 'CML_BASENAME', 'codeon-multilingual/codeon-multilingual.php' );
	define( 'CML_MIN_PHP',  '8.1' );
	define( 'CML_MIN_WP',   '6.5' );

	// WP-CLI stubs so PHPStan can resolve symbols when analysing src/Cli/.
	// szepeviktor/phpstan-wordpress does not ship WP-CLI stubs; we declare
	// just the surface the plugin actually calls. Never loaded at runtime.
	if ( ! class_exists( 'WP_CLI_Command' ) ) {
		class WP_CLI_Command {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	}
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			/** @param class-string|callable $callable */
			public static function add_command( string $name, $callable, array $args = array() ): bool {
				return true;
			}
			public static function log( string $message ): void {}
			public static function success( string $message ): void {}
			public static function warning( string $message ): void {}
			/** @return never */
			public static function error( string $message ): void {
				throw new \RuntimeException( $message );
			}
		}
	}
}

namespace WP_CLI {
	if ( ! class_exists( '\\WP_CLI\\Utils' ) ) {
		class Utils {
			/** @param array<int,array<string,mixed>> $items */
			public static function format_items( string $format, array $items, mixed $fields ): void {}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( '\\WP_CLI\\Utils\\format_items' ) ) {
		/** @param array<int,array<string,mixed>> $items */
		function format_items( string $format, array $items, mixed $fields ): void {}
	}
}
