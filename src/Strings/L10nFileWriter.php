<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Strings;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\Settings;

/**
 * Native WP 6.5+ translation-file writer + loader.
 *
 * Opt-in via Settings['use_native_l10n_files']. When enabled, every admin save
 * regenerates a single per-(domain, locale) file in
 * wp-content/uploads/cml-translations/, the load_textdomain action loads it
 * AFTER WP's official .mo so our keys win via WP_Translation_Controller's
 * merge, and opcache_invalidate ensures the next request reads fresh content.
 *
 * The gettext+wp_cache path (StringTranslator) stays active unconditionally,
 * acting as a defence-in-depth fallback for plugins that never call
 * load_textdomain and for cases where a file write fails.
 *
 * Failure handling: every write that fails records a health entry in the
 * cml_l10n_health option; SettingsPage surfaces this to the admin. A failure
 * never breaks string translation — callers just fall back to the wp_cache
 * path that already exists in v0.5.5.
 */
final class L10nFileWriter {

	private const SUBDIR        = 'cml-translations';
	private const OPTION_HEALTH = 'cml_l10n_health';

	private static bool $registered = false;
	private static ?string $base_dir_cache = null;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'load_textdomain', array( self::class, 'on_load_textdomain' ), 99, 2 );
	}

	public static function is_enabled(): bool {
		return (bool) Settings::get( 'use_native_l10n_files', false );
	}

	/**
	 * Hook: load_textdomain priority 99. When WP loads (or attempts to load)
	 * a .mo for a textdomain, we load our overrides right after so
	 * WP_Translation_Controller merges them with our keys winning.
	 *
	 * Recursion guard: load_textdomain() fires this action, and we call
	 * load_textdomain() ourselves from inside — without the guard we'd loop.
	 *
	 * @param string $domain
	 * @param string $mofile  (unused — we resolve our own path)
	 */
	public static function on_load_textdomain( $domain, $mofile ): void {
		static $recursing = array();

		$domain = (string) $domain;
		if ( '' === $domain || isset( $recursing[ $domain ] ) ) {
			return;
		}

		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( '' === (string) $locale ) {
			return;
		}

		$path = self::file_path( $domain, (string) $locale );
		if ( ! is_readable( $path ) ) {
			return;
		}

		$recursing[ $domain ] = true;
		load_textdomain( $domain, $path, (string) $locale );
		unset( $recursing[ $domain ] );
	}

	// ---- Writers ---------------------------------------------------------

	/**
	 * Write the .l10n.php file for one (domain, language) pair.
	 *
	 * Returns false on any failure (uploads dir not writable, tmp+rename
	 * failed, etc.) AND records the failure in OPTION_HEALTH so the admin
	 * notice surfaces.
	 */
	public static function write_domain( string $domain, string $language_code ): bool {
		$lang = Languages::get( $language_code );
		if ( ! $lang ) {
			return false;
		}
		$locale = trim( (string) $lang->locale );
		if ( '' === $locale ) {
			return false;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.source, s.context, st.translation
				 FROM {$wpdb->prefix}cml_strings s
				 INNER JOIN {$wpdb->prefix}cml_string_translations st ON st.string_id = s.id
				 WHERE s.domain = %s AND st.language = %s",
				$domain,
				$language_code
			)
		);

		$messages = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$source = (string) $r->source;
				$ctx    = (string) $r->context;
				$key    = '' !== $ctx ? ( $ctx . "\x04" . $source ) : $source;
				$messages[ $key ] = (string) $r->translation;
			}
		}

		return self::write_file( $domain, $locale, $messages );
	}

	/**
	 * Bulk-regenerate every (domain, language) combination that has translations.
	 *
	 * @return array{count:int, errors:array<int,string>}
	 */
	public static function regenerate_all(): array {
		global $wpdb;
		$pairs = $wpdb->get_results(
			"SELECT DISTINCT s.domain, st.language
			 FROM {$wpdb->prefix}cml_strings s
			 INNER JOIN {$wpdb->prefix}cml_string_translations st ON st.string_id = s.id"
		);

		$count  = 0;
		$errors = array();
		if ( is_array( $pairs ) ) {
			foreach ( $pairs as $p ) {
				$domain = (string) $p->domain;
				$lang   = (string) $p->language;
				if ( self::write_domain( $domain, $lang ) ) {
					++$count;
				} else {
					$errors[] = $domain . '/' . $lang;
				}
			}
		}

		if ( empty( $errors ) ) {
			delete_option( self::OPTION_HEALTH );
		}

		return array( 'count' => $count, 'errors' => $errors );
	}

	public static function delete_for( string $domain, string $language_code ): bool {
		$lang = Languages::get( $language_code );
		if ( ! $lang ) {
			return false;
		}
		$path = self::file_path( $domain, (string) $lang->locale );
		if ( ! file_exists( $path ) ) {
			return true;
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true );
		}
		return @unlink( $path );
	}

	/**
	 * Remove every .l10n.php file we ever wrote and the parent directory.
	 * Called on disable-toggle and on uninstall.
	 */
	public static function delete_all(): void {
		$dir = self::base_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = (array) glob( $dir . '/*.l10n.php' );
		foreach ( $files as $file ) {
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( (string) $file, true );
			}
			@unlink( (string) $file );
		}
		@rmdir( $dir );
		delete_option( self::OPTION_HEALTH );
	}

	// ---- Health check ----------------------------------------------------

	/**
	 * @return array{ok:bool, reason:string, path:string, time:int}
	 */
	public static function health_check(): array {
		$dir = self::base_dir();

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return array(
					'ok'     => false,
					'reason' => 'mkdir_failed',
					'path'   => $dir,
					'time'   => time(),
				);
			}
		}
		if ( ! is_writable( $dir ) ) {
			return array(
				'ok'     => false,
				'reason' => 'not_writable',
				'path'   => $dir,
				'time'   => time(),
			);
		}

		$stored = get_option( self::OPTION_HEALTH );
		if ( is_array( $stored ) && empty( $stored['ok'] ) ) {
			return array(
				'ok'     => false,
				'reason' => (string) ( $stored['reason'] ?? 'previous_failure' ),
				'path'   => (string) ( $stored['path'] ?? $dir ),
				'time'   => (int) ( $stored['time'] ?? time() ),
			);
		}

		return array(
			'ok'     => true,
			'reason' => '',
			'path'   => $dir,
			'time'   => time(),
		);
	}

	// ---- Paths -----------------------------------------------------------

	public static function file_path( string $domain, string $locale ): string {
		return self::base_dir() . '/' . self::sanitize_domain( $domain ) . '-' . $locale . '.l10n.php';
	}

	public static function base_dir(): string {
		if ( null !== self::$base_dir_cache ) {
			return self::$base_dir_cache;
		}
		$uploads = wp_upload_dir( null, false );
		$base    = isset( $uploads['basedir'] ) && '' !== $uploads['basedir']
			? rtrim( (string) $uploads['basedir'], '/' )
			: rtrim( WP_CONTENT_DIR, '/' ) . '/uploads';
		self::$base_dir_cache = $base . '/' . self::SUBDIR;
		return self::$base_dir_cache;
	}

	private static function sanitize_domain( string $domain ): string {
		$clean = preg_replace( '/[^a-z0-9_-]/i', '-', $domain );
		$clean = is_string( $clean ) ? trim( $clean, '-' ) : '';
		return '' !== $clean ? strtolower( $clean ) : 'default';
	}

	// ---- Internal --------------------------------------------------------

	/**
	 * @param array<string, string> $messages
	 */
	private static function write_file( string $domain, string $locale, array $messages ): bool {
		$dir = self::base_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			self::record_failure( 'mkdir_failed', $dir );
			return false;
		}
		if ( ! is_writable( $dir ) ) {
			self::record_failure( 'not_writable', $dir );
			return false;
		}

		$path = self::file_path( $domain, $locale );

		// Empty translations: delete file rather than write an empty array.
		if ( empty( $messages ) ) {
			if ( file_exists( $path ) ) {
				if ( function_exists( 'opcache_invalidate' ) ) {
					@opcache_invalidate( $path, true );
				}
				@unlink( $path );
			}
			return true;
		}

		$data = array(
			'messages' => $messages,
			'language' => $locale,
		);

		$content = "<?php\n// Generated by codeon-multilingual. Do not edit; rebuilt automatically on translation save.\nreturn " . var_export( $data, true ) . ";\n";

		$tmp = $path . '.' . uniqid( 'cml', true ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $content, LOCK_EX ) ) {
			self::record_failure( 'write_failed', $path );
			return false;
		}

		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			self::record_failure( 'rename_failed', $path );
			return false;
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true );
		}

		delete_option( self::OPTION_HEALTH );
		return true;
	}

	private static function record_failure( string $reason, string $path ): void {
		update_option(
			self::OPTION_HEALTH,
			array(
				'ok'     => false,
				'reason' => $reason,
				'path'   => $path,
				'time'   => time(),
			),
			false
		);
	}
}
