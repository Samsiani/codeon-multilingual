<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Migration;

/**
 * Read-only probes for migration sources CodeOn can report or import.
 */
final class MigrationSourceDetector {

	/**
	 * @return array{detected:string,evidence:array<int,string>}
	 */
	public static function wpml(): array {
		return self::result(
			WpmlImporter::is_available() ? array( 'table: icl_translations' ) : array()
		);
	}

	/**
	 * @return array{detected:string,evidence:array<int,string>}
	 */
	public static function polylang(): array {
		if ( PolylangImporter::is_available() ) {
			return self::result( array( 'taxonomy: language' ) );
		}

		if ( PolylangImporter::is_detected() ) {
			return array(
				'detected' => 'possible',
				'evidence' => array( 'function: pll_languages_list' ),
			);
		}

		return self::result( array() );
	}

	/**
	 * @return array{detected:string,evidence:array<int,string>}
	 */
	public static function translatepress(): array {
		$evidence = array();

		if ( self::table_prefix_exists( 'trp_' ) ) {
			$evidence[] = 'table prefix: trp_';
		}

		foreach ( self::present_options( array( 'trp_settings', 'trp_machine_translation_settings', 'trp_advanced_settings' ) ) as $option ) {
			$evidence[] = "option: {$option}";
		}

		return self::result( $evidence );
	}

	/**
	 * @return array{detected:string,evidence:array<int,string>}
	 */
	public static function weglot(): array {
		$evidence = array();

		foreach ( self::present_options( array( 'weglot_settings', 'weglot_api_key', 'weglot_version' ) ) as $option ) {
			$evidence[] = "option: {$option}";
		}

		foreach ( self::present_plugins( array( 'weglot/weglot.php' ) ) as $plugin ) {
			$evidence[] = "plugin: {$plugin}";
		}

		return self::result( $evidence );
	}

	/**
	 * @return array{detected:string,evidence:array<int,string>}
	 */
	public static function gtranslate(): array {
		$evidence = array();

		foreach ( self::present_options( array( 'GTranslate', 'gtranslate', 'gtranslate_options', 'gtranslate_settings' ) ) as $option ) {
			$evidence[] = "option: {$option}";
		}

		foreach ( self::present_plugins( array( 'gtranslate/gtranslate.php' ) ) as $plugin ) {
			$evidence[] = "plugin: {$plugin}";
		}

		return self::result( $evidence );
	}

	/**
	 * @return array{detected:string,evidence:array<int,string>}
	 */
	public static function multilingualpress(): array {
		$evidence = array();

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$evidence[] = 'multisite enabled';
		}

		foreach ( self::present_plugins( array( 'multilingualpress/multilingualpress.php', 'multilingualpress-pro/multilingualpress.php' ) ) as $plugin ) {
			$evidence[] = "plugin: {$plugin}";
		}

		if ( count( $evidence ) === 1 && 'multisite enabled' === $evidence[0] ) {
			return array(
				'detected' => 'possible',
				'evidence' => $evidence,
			);
		}

		return self::result( $evidence );
	}

	/**
	 * @return array{detected:string,evidence:array<int,string>}
	 */
	public static function inline_multilingual_fields(): array {
		$evidence = array();

		foreach (
			self::present_options(
				array(
					'qtranslate_options',
					'qtranslate_enabled_languages',
					'qtranslate_default_language',
					'wpglobus_option',
					'wpglobus_language_edit',
				)
			) as $option
		) {
			$evidence[] = "option: {$option}";
		}

		foreach (
			self::present_plugins(
				array(
					'qtranslate-x/qtranslate.php',
					'qtranslate-xt/qtranslate.php',
					'wpglobus/wpglobus.php',
				)
			) as $plugin
		) {
			$evidence[] = "plugin: {$plugin}";
		}

		return self::result( $evidence );
	}

	private static function table_prefix_exists( string $needle ): bool {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}

		$prefix = is_string( $wpdb->prefix ?? null ) ? $wpdb->prefix : '';
		$like   = self::esc_like( $prefix . $needle ) . '%';

		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $like )
		);
	}

	private static function esc_like( string $text ): string {
		global $wpdb;

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'esc_like' ) ) {
			return $wpdb->esc_like( $text );
		}

		return addcslashes( $text, '_%\\' );
	}

	/**
	 * @param array<int,string> $names
	 * @return array<int,string>
	 */
	private static function present_options( array $names ): array {
		$present = array();

		foreach ( $names as $name ) {
			if ( self::option_exists( $name ) ) {
				$present[] = $name;
			}
		}

		return $present;
	}

	private static function option_exists( string $name ): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		$sentinel = new \stdClass();
		return get_option( $name, $sentinel ) !== $sentinel;
	}

	/**
	 * @param array<int,string> $basenames
	 * @return array<int,string>
	 */
	private static function present_plugins( array $basenames ): array {
		$active  = self::active_plugin_basenames();
		$present = array();

		foreach ( $basenames as $basename ) {
			if ( in_array( $basename, $active, true ) || self::plugin_file_exists( $basename ) ) {
				$present[] = $basename;
			}
		}

		return $present;
	}

	/**
	 * @return array<int,string>
	 */
	private static function active_plugin_basenames(): array {
		$plugins = array();

		if ( function_exists( 'get_option' ) ) {
			$active = get_option( 'active_plugins', array() );
			if ( is_array( $active ) ) {
				$plugins = array_merge( $plugins, array_values( array_filter( $active, 'is_string' ) ) );
			}
		}

		if ( function_exists( 'get_site_option' ) ) {
			$sitewide = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $sitewide ) ) {
				$plugins = array_merge( $plugins, array_values( array_filter( array_keys( $sitewide ), 'is_string' ) ) );
			}
		}

		return array_values( array_unique( $plugins ) );
	}

	private static function plugin_file_exists( string $basename ): bool {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return false;
		}

		return is_readable( rtrim( WP_PLUGIN_DIR, '/\\' ) . '/' . ltrim( $basename, '/\\' ) );
	}

	/**
	 * @param array<int,string> $evidence
	 * @return array{detected:string,evidence:array<int,string>}
	 */
	private static function result( array $evidence ): array {
		return array(
			'detected' => count( $evidence ) > 0 ? 'yes' : 'no',
			'evidence' => array_values( array_unique( $evidence ) ),
		);
	}
}
