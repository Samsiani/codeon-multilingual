<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests use Brain Monkey to stub WP globals/functions. Integration tests
 * (when added) will load the WP test scaffold instead — they live in a separate
 * testsuite and bootstrap so the two flows don't tread on each other.
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Constants the plugin reads at load time.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/abspath/' );
}
if ( ! defined( 'CML_VERSION' ) ) {
	define( 'CML_VERSION', '0.1.0-test' );
}
if ( ! defined( 'CML_FILE' ) ) {
	define( 'CML_FILE', __DIR__ . '/../codeon-multilingual.php' );
}
if ( ! defined( 'CML_PATH' ) ) {
	define( 'CML_PATH', __DIR__ . '/../' );
}
if ( ! defined( 'CML_BASENAME' ) ) {
	define( 'CML_BASENAME', 'codeon-multilingual/codeon-multilingual.php' );
}
if ( ! defined( 'CML_BUILD_ID' ) ) {
	define( 'CML_BUILD_ID', 'test' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// WP-CLI stubs so the autoloader can resolve src/Cli/*.php classes during
// unit tests. The CLI runtime itself is not exercised here — only the pure
// helper methods on the command classes (row_for_display, detect_format,
// PoExporter/PoImporter). The stubs intentionally do nothing.
if ( ! class_exists( '\\WP_CLI_Command' ) ) {
	class WP_CLI_Command { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	}
}
if ( ! class_exists( '\\WP_CLI' ) ) {
	final class WP_CLI { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		public static function add_command( string $name, $class ): void {}
		public static function log( string $msg ): void {}
		public static function success( string $msg ): void {}
		public static function warning( string $msg ): void {}
		public static function error( string $msg ): void {
			throw new \RuntimeException( $msg );
		}
	}
}
