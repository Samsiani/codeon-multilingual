<?php
/**
 * Uninstall hook.
 *
 * Runs only when the plugin is removed via WP admin (Plugins → Delete), not on
 * deactivation. Production safety default: preserve CodeOn tables/options unless
 * the admin explicitly enabled "Remove all data on uninstall" or a deployment
 * defines CML_DELETE_DATA_ON_UNINSTALL as true.
 *
 * @package Samsiani\CodeonMultilingual
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'CML_PATH' ) ) {
	define( 'CML_PATH', plugin_dir_path( __FILE__ ) );
}

$cml_composer_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $cml_composer_autoload ) ) {
	require_once $cml_composer_autoload;

	$cml_package_autoload = __DIR__ . '/vendor/autoload_packages.php';
	if ( file_exists( $cml_package_autoload ) ) {
		require_once $cml_package_autoload;
	}

	$delete_data = defined( 'CML_DELETE_DATA_ON_UNINSTALL' )
		? (bool) CML_DELETE_DATA_ON_UNINSTALL
		: \Samsiani\CodeonMultilingual\Core\Settings::delete_data_on_uninstall();

	if ( $delete_data ) {
		\Samsiani\CodeonMultilingual\Core\Schema::uninstall();
	}
}
