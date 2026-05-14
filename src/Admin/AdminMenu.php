<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin;

use Samsiani\CodeonMultilingual\Admin\Pages\LanguagesPage;
use Samsiani\CodeonMultilingual\Admin\Pages\MigrationPage;
use Samsiani\CodeonMultilingual\Admin\Pages\ScanPage;
use Samsiani\CodeonMultilingual\Admin\Pages\SettingsPage;
use Samsiani\CodeonMultilingual\Admin\Pages\SetupWizard;
use Samsiani\CodeonMultilingual\Admin\Pages\StringsPage;

/**
 * Top-level Multilingual menu. Submenu items are appended by their owning module
 * to keep the menu open to extension without touching this file.
 */
final class AdminMenu {

	public const PARENT_SLUG = 'cml-languages';
	public const CAPABILITY  = 'manage_options';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_menu', array( self::class, 'on_admin_menu' ) );
	}

	public static function on_admin_menu(): void {
		add_menu_page(
			__( 'Multilingual', 'codeon-multilingual' ),
			__( 'Multilingual', 'codeon-multilingual' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( LanguagesPage::class, 'render' ),
			'dashicons-translation',
			76
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Languages', 'codeon-multilingual' ),
			__( 'Languages', 'codeon-multilingual' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( LanguagesPage::class, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Strings', 'codeon-multilingual' ),
			__( 'Strings', 'codeon-multilingual' ),
			self::CAPABILITY,
			StringsPage::PAGE_SLUG,
			array( StringsPage::class, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Scan', 'codeon-multilingual' ),
			__( 'Scan', 'codeon-multilingual' ),
			self::CAPABILITY,
			ScanPage::PAGE_SLUG,
			array( ScanPage::class, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Migration', 'codeon-multilingual' ),
			__( 'Migration', 'codeon-multilingual' ),
			self::CAPABILITY,
			MigrationPage::PAGE_SLUG,
			array( MigrationPage::class, 'render' )
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Settings', 'codeon-multilingual' ),
			__( 'Settings', 'codeon-multilingual' ),
			self::CAPABILITY,
			SettingsPage::PAGE_SLUG,
			array( SettingsPage::class, 'render' )
		);

		// Hidden submenu — accessible via the setup-wizard redirect or the
		// "Run setup wizard" link, but not shown in the sidebar.
		add_submenu_page(
			'', // null parent — page is dispatched but not listed
			__( 'Setup', 'codeon-multilingual' ),
			__( 'Setup', 'codeon-multilingual' ),
			self::CAPABILITY,
			SetupWizard::PAGE_SLUG,
			array( SetupWizard::class, 'render' )
		);
	}
}
