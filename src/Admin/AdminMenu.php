<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin;

use Samsiani\CodeonMultilingual\Admin\Pages\LanguagesPage;
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
	}
}
