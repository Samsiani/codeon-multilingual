<?php
/**
 * Integration-test bootstrap.
 *
 * Requires the WordPress PHPUnit test suite. Set WP_TESTS_DIR to the checkout
 * path that contains includes/bootstrap.php. The plugin is loaded from the
 * working tree before tests run.
 */
declare(strict_types=1);

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $wp_tests_dir ) || '' === $wp_tests_dir ) {
	echo "WP_TESTS_DIR is required for integration tests.\n";
	exit( 1 );
}

$wp_tests_dir = rtrim( $wp_tests_dir, '/\\' );
$bootstrap    = $wp_tests_dir . '/includes/bootstrap.php';
if ( ! file_exists( $bootstrap ) ) {
	echo "WordPress test bootstrap not found at {$bootstrap}.\n";
	exit( 1 );
}

if ( ! defined( 'CML_TEST_PLUGIN_FILE' ) ) {
	define( 'CML_TEST_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/codeon-multilingual.php' );
}

require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require CML_TEST_PLUGIN_FILE;
	}
);

require $bootstrap;
