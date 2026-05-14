<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

/**
 * Thin wrapper over wp_options['cml_settings'].
 *
 * Single source of truth for plugin-wide settings. Defaults are returned for
 * any key the admin hasn't customised yet, so callers can always assume the
 * key exists. Saves are merged (caller passes only the keys it changes).
 */
final class Settings {

	private const OPTION = 'cml_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'frontend_switcher_enabled'   => true,
			'frontend_switcher_position'  => 'bottom-right',
			'frontend_switcher_style'     => 'dropdown',
			'frontend_switcher_show_flag' => true,
			'auto_discover_strings'       => false,
			'use_native_l10n_files'       => false,
			'wpml_compat_enabled'         => true,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * @param mixed $default
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return $all[ $key ] ?? $default;
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public static function save( array $changes ): void {
		$current = self::all();
		update_option( self::OPTION, array_merge( $current, $changes ), true );
	}
}
