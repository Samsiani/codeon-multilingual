<?php
/**
 * Plugin Name:       CodeOn Multilingual
 * Plugin URI:        https://codeon.ge/plugins/codeon-multilingual
 * Description:       Lightweight multilingual plugin for WordPress and WooCommerce. WPML-compatible, fraction of the weight.
 * Version:           0.7.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Samsiani / CodeOn
 * Author URI:        https://codeon.ge
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       codeon-multilingual
 * Domain Path:       /languages
 *
 * @package Samsiani\CodeonMultilingual
 */

defined( 'ABSPATH' ) || exit;

define( 'CML_VERSION', '0.7.1' );
define( 'CML_FILE', __FILE__ );
define( 'CML_PATH', plugin_dir_path( __FILE__ ) );
define( 'CML_URL', plugin_dir_url( __FILE__ ) );
define( 'CML_BASENAME', plugin_basename( __FILE__ ) );
define( 'CML_MIN_PHP', '8.1' );
define( 'CML_MIN_WP', '6.5' );

require_once __DIR__ . '/src/Core/BuildId.php';

if ( version_compare( PHP_VERSION, CML_MIN_PHP, '<' )
	|| version_compare( get_bloginfo( 'version' ), CML_MIN_WP, '<' ) ) {

	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: required WP version */
						'CodeOn Multilingual requires PHP %1$s+ and WordPress %2$s+. Plugin not loaded.',
						CML_MIN_PHP,
						CML_MIN_WP
					)
				)
			);
		}
	);
	return;
}

$cml_autoload = __DIR__ . '/vendor/autoload_packages.php';
if ( ! file_exists( $cml_autoload ) ) {
	$cml_autoload = __DIR__ . '/vendor/autoload.php';
}

if ( ! file_exists( $cml_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>CodeOn Multilingual: composer dependencies are missing. Run <code>composer install</code> in the plugin directory.</p></div>';
		}
	);
	return;
}

require_once $cml_autoload;

register_activation_hook( __FILE__, array( \Samsiani\CodeonMultilingual\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Samsiani\CodeonMultilingual\Core\Deactivator::class, 'deactivate' ) );

\Samsiani\CodeonMultilingual\Core\Plugin::instance()->boot();

\Samsiani\CodeonMultilingual\Cli\CommandLoader::register();

if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
	$cml_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/Samsiani/codeon-multilingual/',
		__FILE__,
		'codeon-multilingual'
	);
	$cml_vcs_api        = $cml_update_checker->getVcsApi();
	if ( method_exists( $cml_vcs_api, 'enableReleaseAssets' ) ) {
		$cml_vcs_api->enableReleaseAssets();
	}
	if ( defined( 'CML_GITHUB_TOKEN' ) && '' !== CML_GITHUB_TOKEN ) {
		$cml_update_checker->setAuthentication( CML_GITHUB_TOKEN );
	}
	unset( $cml_update_checker, $cml_vcs_api );
}
