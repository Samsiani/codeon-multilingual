<?php
/**
 * Integration-test bootstrap.
 *
 * Requires the WordPress PHPUnit test suite. Set WP_TESTS_DIR to the checkout
 * path that contains includes/bootstrap.php. The plugin is loaded from the
 * working tree before tests run.
 */
declare(strict_types=1);

/**
 * @return never
 */
function cml_integration_bootstrap_fail( string $message ): void {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

$plugin_root  = dirname( __DIR__, 2 );
$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $wp_tests_dir ) || '' === $wp_tests_dir ) {
	cml_integration_bootstrap_fail(
		'WP_TESTS_DIR is required for integration tests. Run `bash scripts/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 latest latest`, then retry with WP_TESTS_DIR=/tmp/wordpress-tests-lib.'
	);
}

$wp_tests_dir = rtrim( $wp_tests_dir, '/\\' );
$bootstrap    = $wp_tests_dir . '/includes/bootstrap.php';
if ( ! file_exists( $bootstrap ) ) {
	cml_integration_bootstrap_fail( "WordPress test bootstrap not found at {$bootstrap}." );
}

if ( ! file_exists( $plugin_root . '/vendor/autoload.php' ) ) {
	cml_integration_bootstrap_fail( 'Composer dependencies are missing. Run `composer install` before integration tests.' );
}

require_once $plugin_root . '/vendor/autoload.php';

if ( ! defined( 'CML_TEST_PLUGIN_FILE' ) ) {
	define( 'CML_TEST_PLUGIN_FILE', $plugin_root . '/codeon-multilingual.php' );
}

require_once $wp_tests_dir . '/includes/functions.php';

$require_woocommerce = filter_var( (string) getenv( 'CML_REQUIRE_WOOCOMMERCE' ), FILTER_VALIDATE_BOOLEAN );

tests_add_filter(
	'option_active_plugins',
	static function ( $plugins ) {
		$plugins = is_array( $plugins ) ? $plugins : array();
		$plugin  = 'woocommerce/woocommerce.php';
		if (
			defined( 'WP_PLUGIN_DIR' )
			&& file_exists( WP_PLUGIN_DIR . '/' . $plugin )
			&& ! in_array( $plugin, $plugins, true )
		) {
			$plugins[] = $plugin;
		}

		return $plugins;
	}
);

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $require_woocommerce ): void {
		$woocommerce_file = getenv( 'WC_PLUGIN_FILE' );
		$candidates       = array();
		if ( is_string( $woocommerce_file ) && '' !== $woocommerce_file ) {
			$candidates[] = $woocommerce_file;
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$candidates[] = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		}

		$loaded_woocommerce = false;
		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && file_exists( $candidate ) ) {
				require_once $candidate;
				$loaded_woocommerce = true;
				break;
			}
		}

		if ( ! defined( 'CML_TEST_HAS_WOOCOMMERCE' ) ) {
			define( 'CML_TEST_HAS_WOOCOMMERCE', $loaded_woocommerce );
		}

		if ( $require_woocommerce && ! $loaded_woocommerce ) {
			cml_integration_bootstrap_fail(
				'WooCommerce is required for this integration run, but woocommerce/woocommerce.php was not found. Run scripts/install-wp-tests.sh with a WooCommerce version or set WC_PLUGIN_FILE.'
			);
		}

		require CML_TEST_PLUGIN_FILE;
	}
);

require $bootstrap;

if ( class_exists( '\Samsiani\CodeonMultilingual\Core\Schema' ) ) {
	\Samsiani\CodeonMultilingual\Core\Schema::install();
}

if ( defined( 'CML_TEST_HAS_WOOCOMMERCE' ) && CML_TEST_HAS_WOOCOMMERCE && class_exists( '\WC_Install' ) ) {
	update_option( 'woocommerce_allow_tracking', 'no' );
	\WC_Install::install();
}
