<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin;

use Samsiani\CodeonMultilingual\Admin\Pages\SetupWizard;
use Samsiani\CodeonMultilingual\Core\Settings;

/**
 * One-shot redirect to the Setup Wizard on first activation.
 *
 * Activator::activate sets a transient `cml_run_setup_wizard`; this listener
 * fires on admin_init, sees the transient, deletes it, and redirects the
 * admin to the wizard. We never redirect when:
 *   - DOING_AJAX / WP_CLI / network admin / front-end request
 *   - the user lacks manage_options
 *   - the user is already on the wizard
 *   - setup_complete is already true (subsequent activations don't re-prompt)
 *
 * Bulk-activation safety: multiple activations in one request hit the
 * transient once; the second is a no-op.
 */
final class SetupRedirector {

	public const TRANSIENT = 'cml_run_setup_wizard';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_init', array( self::class, 'maybe_redirect' ), 1 );
	}

	/** Called from Activator::activate on the activation hook. */
	public static function arm(): void {
		if ( (bool) Settings::get( 'setup_complete', false ) ) {
			return;
		}
		set_transient( self::TRANSIENT, 1, 30 );
	}

	public static function maybe_redirect(): void {
		if ( ! get_transient( self::TRANSIENT ) ) {
			return;
		}

		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| is_network_admin()
			|| ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( SetupWizard::is_complete() ) {
			delete_transient( self::TRANSIENT );
			return;
		}

		// Already on the wizard — let the user proceed.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( SetupWizard::PAGE_SLUG === $current_page ) {
			return;
		}

		delete_transient( self::TRANSIENT );
		wp_safe_redirect( SetupWizard::url( 1 ) );
		exit;
	}
}
