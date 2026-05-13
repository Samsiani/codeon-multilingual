<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

/**
 * Deactivation hook target.
 *
 * Tables and settings are intentionally preserved — full teardown is done by uninstall.php
 * so users don't lose translations when toggling the plugin off.
 */
final class Deactivator {

	public static function deactivate(): void {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'cml' );
		}
	}
}
