<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Cli;

use Samsiani\CodeonMultilingual\Core\Languages;
use WP_CLI;
use WP_CLI\Utils;
use WP_CLI_Command;

/**
 * Manage configured languages from the command line.
 *
 * Mirrors the Admin → Languages screen but stays scriptable for ops/CI work:
 * staging environments, idempotent provisioning, bulk activation toggles.
 * Every mutation flushes the request- and object-cache layers via
 * Languages::flush_cache() so the next request sees a coherent snapshot.
 *
 * Examples
 *
 *     wp cml language list --format=table
 *     wp cml language create ka --locale=ka_GE --name=Georgian --native=ქართული --default
 *     wp cml language activate ru
 *     wp cml language deactivate de
 *     wp cml language set-default en
 *     wp cml language delete ru
 */
final class LanguageCommand extends WP_CLI_Command {

	private const CODE_REGEX = '/^[a-z]{2,3}(-[a-z0-9]+)?$/';

	/**
	 * Lists every configured language.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * [--active]
	 * : Show only active languages.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function list( array $args, array $assoc_args ): void {
		$languages = isset( $assoc_args['active'] ) ? Languages::active() : Languages::all();

		if ( 'ids' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::log( implode( ' ', array_keys( $languages ) ) );
			return;
		}

		$rows = array();
		foreach ( $languages as $lang ) {
			$rows[] = self::row_for_display( $lang );
		}

		Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'code', 'locale', 'name', 'native', 'active', 'is_default', 'rtl', 'position' )
		);
	}

	/**
	 * Creates a new language.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : 2-3 letter lowercase language code, optionally with a region suffix (e.g. en, ka, pt-br).
	 *
	 * --locale=<locale>
	 * : WordPress locale string (en_US, ka_GE, pt_BR).
	 *
	 * --name=<name>
	 * : English name (e.g. Georgian).
	 *
	 * --native=<native>
	 * : Native name (e.g. ქართული).
	 *
	 * [--flag=<flag>]
	 * : Optional country code for the flag icon. Defaults to <code>.
	 *
	 * [--rtl]
	 * : Mark this language as right-to-left.
	 *
	 * [--inactive]
	 * : Create the language but leave it inactive (active by default).
	 *
	 * [--default]
	 * : Make this language the site default, demoting any existing default.
	 *
	 * [--position=<n>]
	 * : Sort order in switchers (lower = first). Defaults to 10.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public function create( array $args, array $assoc_args ): void {
		$code = strtolower( (string) ( $args[0] ?? '' ) );
		if ( ! preg_match( self::CODE_REGEX, $code ) ) {
			WP_CLI::error( "Invalid language code '{$code}'. Use 2-3 lowercase letters with optional region suffix (e.g. en, ka, pt-br)." );
		}
		if ( Languages::exists( $code ) ) {
			WP_CLI::error( "Language '{$code}' already exists. Use `wp cml language activate {$code}` to toggle active status." );
		}

		$locale = (string) ( $assoc_args['locale'] ?? '' );
		$name   = (string) ( $assoc_args['name'] ?? '' );
		$native = (string) ( $assoc_args['native'] ?? '' );
		if ( '' === $locale || '' === $name || '' === $native ) {
			WP_CLI::error( 'Missing required flag: --locale, --name, and --native are all mandatory.' );
		}

		$ok = Languages::create(
			array(
				'code'       => $code,
				'locale'     => $locale,
				'name'       => $name,
				'native'     => $native,
				'flag'       => (string) ( $assoc_args['flag'] ?? $code ),
				'rtl'        => isset( $assoc_args['rtl'] ) ? 1 : 0,
				'active'     => isset( $assoc_args['inactive'] ) ? 0 : 1,
				'is_default' => isset( $assoc_args['default'] ) ? 1 : 0,
				'position'   => isset( $assoc_args['position'] ) ? (int) $assoc_args['position'] : 10,
			)
		);

		if ( ! $ok ) {
			WP_CLI::error( "Failed to insert language '{$code}'." );
		}
		WP_CLI::success( "Created language '{$code}' ({$name})." );
	}

	/**
	 * Marks a language as active.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Language code.
	 *
	 * @param array<int, string> $args
	 */
	public function activate( array $args ): void {
		$code = (string) ( $args[0] ?? '' );
		self::require_existing( $code );
		Languages::update_active( $code, true );
		WP_CLI::success( "Activated '{$code}'." );
	}

	/**
	 * Marks a language as inactive. Cannot deactivate the default language.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Language code.
	 *
	 * @param array<int, string> $args
	 */
	public function deactivate( array $args ): void {
		$code = (string) ( $args[0] ?? '' );
		self::require_existing( $code );

		if ( Languages::is_default( $code ) ) {
			WP_CLI::error( "Cannot deactivate the default language '{$code}'. Set another language as default first." );
		}
		Languages::update_active( $code, false );
		WP_CLI::success( "Deactivated '{$code}'." );
	}

	/**
	 * Promotes a language to site default.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Language code.
	 *
	 * @subcommand set-default
	 * @param array<int, string> $args
	 */
	public function set_default( array $args ): void {
		$code = (string) ( $args[0] ?? '' );
		self::require_existing( $code );
		Languages::set_default( $code );
		WP_CLI::success( "Set '{$code}' as default language." );
	}

	/**
	 * Deletes a language. Refuses to delete the default; reassign first.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Language code.
	 *
	 * @param array<int, string> $args
	 */
	public function delete( array $args ): void {
		$code = (string) ( $args[0] ?? '' );
		self::require_existing( $code );

		if ( Languages::is_default( $code ) ) {
			WP_CLI::error( "Cannot delete the default language '{$code}'. Use `set-default` to move the default first." );
		}
		if ( ! Languages::delete( $code ) ) {
			WP_CLI::error( "Failed to delete '{$code}'." );
		}
		WP_CLI::success( "Deleted '{$code}'. Posts tagged with this language remain in the DB but become orphaned until re-tagged." );
	}

	/**
	 * Shape a single language row for table/csv/json output.
	 *
	 * Pure transform — extracted so tests can assert column shaping without a DB.
	 *
	 * @return array<string, string>
	 */
	public static function row_for_display( object $lang ): array {
		return array(
			'code'       => (string) $lang->code,
			'locale'     => (string) $lang->locale,
			'name'       => (string) $lang->name,
			'native'     => (string) $lang->native,
			'active'     => (int) $lang->active ? 'yes' : 'no',
			'is_default' => (int) $lang->is_default ? 'yes' : 'no',
			'rtl'        => (int) $lang->rtl ? 'yes' : 'no',
			'position'   => (string) (int) $lang->position,
		);
	}

	private static function require_existing( string $code ): void {
		if ( '' === $code ) {
			WP_CLI::error( 'Language code is required.' );
		}
		if ( ! Languages::exists( $code ) ) {
			WP_CLI::error( "Unknown language '{$code}'. Run `wp cml language list` to see configured codes." );
		}
	}
}
