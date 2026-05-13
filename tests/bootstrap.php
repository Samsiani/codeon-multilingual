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
