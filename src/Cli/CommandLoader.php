<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Cli;

use WP_CLI;

/**
 * Single entry-point that registers every `wp cml ...` subcommand.
 *
 * Called from the main plugin file inside an `if ( defined( 'WP_CLI' ) && WP_CLI )`
 * guard, so command classes never load on a normal web request. WP-CLI is
 * available extremely early — before plugins_loaded — so registration here
 * does not require any of Plugin::boot()'s hook wiring.
 */
final class CommandLoader {

	public static function register(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! class_exists( '\\WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'cml language', LanguageCommand::class );
		WP_CLI::add_command( 'cml translate', TranslateCommand::class );
		WP_CLI::add_command( 'cml strings', StringsCommand::class );
		WP_CLI::add_command( 'cml migrate', MigrateCommand::class );
		WP_CLI::add_command( 'cml backfill', BackfillCommand::class );
	}
}
