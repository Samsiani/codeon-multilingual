<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Core;

use Samsiani\CodeonMultilingual\Admin\AdminBar;
use Samsiani\CodeonMultilingual\Admin\AdminMenu;
use Samsiani\CodeonMultilingual\Admin\PostsListLanguage;
use Samsiani\CodeonMultilingual\Admin\Pages\LanguagesPage;
use Samsiani\CodeonMultilingual\Admin\Pages\MigrationPage;
use Samsiani\CodeonMultilingual\Admin\Pages\ScanPage;
use Samsiani\CodeonMultilingual\Admin\Pages\SettingsPage;
use Samsiani\CodeonMultilingual\Admin\Pages\SetupWizard;
use Samsiani\CodeonMultilingual\Admin\Pages\StringsPage;
use Samsiani\CodeonMultilingual\Admin\SetupRedirector;
use Samsiani\CodeonMultilingual\Compat\WpmlFunctions;
use Samsiani\CodeonMultilingual\Content\PostTranslator;
use Samsiani\CodeonMultilingual\Content\TermTranslator;
use Samsiani\CodeonMultilingual\Frontend\FloatingSwitcher;
use Samsiani\CodeonMultilingual\Frontend\Hreflang;
use Samsiani\CodeonMultilingual\Frontend\HtmlLangAttribute;
use Samsiani\CodeonMultilingual\Frontend\LanguageSwitcher;
use Samsiani\CodeonMultilingual\Frontend\LocaleOverride;
use Samsiani\CodeonMultilingual\Frontend\SitemapFilter;
use Samsiani\CodeonMultilingual\Query\PostsClauses;
use Samsiani\CodeonMultilingual\Query\TermsClauses;
use Samsiani\CodeonMultilingual\Rest\LangParam;
use Samsiani\CodeonMultilingual\Rest\StringsApi;
use Samsiani\CodeonMultilingual\Strings\L10nFileWriter;
use Samsiani\CodeonMultilingual\Strings\StringTranslator;
use Samsiani\CodeonMultilingual\Url\PostLinkFilter;
use Samsiani\CodeonMultilingual\Url\Router;
use Samsiani\CodeonMultilingual\Url\TermLinkFilter;
use Samsiani\CodeonMultilingual\Woo\ProductSync;
use Samsiani\CodeonMultilingual\Woo\TranslationLock;
use Samsiani\CodeonMultilingual\Woo\VariationTranslator;

/**
 * Plugin orchestrator.
 *
 * Called from the bootstrap at plugin-file load time so modules get a chance to
 * register their early hooks (Router needs plugins_loaded p1, which fires before
 * any plugins_loaded p5 callback could run).
 *
 * Modules expose static register() that wires hooks — Plugin::boot() is the
 * canonical list of what's loaded.
 */
final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Routing.
		Router::register();
		PostLinkFilter::register();
		TermLinkFilter::register();

		// Query injection.
		PostsClauses::register();
		TermsClauses::register();

		// Background work.
		Backfill::register();

		// Content translation.
		PostTranslator::register();
		TermTranslator::register();

		// WooCommerce.
		ProductSync::register();
		VariationTranslator::register();
		TranslationLock::register();

		// Strings.
		StringTranslator::register();
		L10nFileWriter::register();

		// Frontend (switcher, hreflang, html lang, locale override, sitemap).
		LanguageSwitcher::register();
		FloatingSwitcher::register();
		Hreflang::register();
		HtmlLangAttribute::register();
		LocaleOverride::register();
		SitemapFilter::register();

		// REST.
		LangParam::register();
		StringsApi::register();

		// Compatibility shims.
		WpmlFunctions::register();

		// Admin.
		AdminMenu::register();
		AdminBar::register();
		PostsListLanguage::register();
		LanguagesPage::register();
		StringsPage::register();
		ScanPage::register();
		SettingsPage::register();
		MigrationPage::register();
		SetupWizard::register();
		SetupRedirector::register();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( Activator::class, 'maybe_upgrade' ) );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'codeon-multilingual',
			false,
			dirname( CML_BASENAME ) . '/languages'
		);
	}
}
