<?php
/**
 * Uninstall hook.
 *
 * Drops all CML tables and options. Runs only when the plugin is removed via WP admin
 * (Plugins → Delete) — not on deactivation.
 *
 * @package Samsiani\CodeonMultilingual
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'CML_PATH' ) ) {
	define( 'CML_PATH', plugin_dir_path( __FILE__ ) );
}

$cml_autoload = __DIR__ . '/vendor/autoload_packages.php';
if ( ! file_exists( $cml_autoload ) ) {
	$cml_autoload = __DIR__ . '/vendor/autoload.php';
}

if ( file_exists( $cml_autoload ) ) {
	require_once $cml_autoload;
	\Samsiani\CodeonMultilingual\Core\Schema::uninstall();
}
