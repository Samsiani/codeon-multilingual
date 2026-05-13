<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\Settings;

/**
 * Auto-injected floating language switcher on every public page.
 *
 * Renders into wp_footer wrapped in a position-aware container. Pulls the
 * actual switcher HTML from LanguageSwitcher::render so the shortcode, widget,
 * and floating element all share one rendering path.
 *
 * Disabled when the site has only one active language, or when the admin
 * toggles "Show on frontend" off in Settings.
 */
final class FloatingSwitcher {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_styles' ) );
		add_action( 'wp_footer',          array( self::class, 'render' ) );
	}

	public static function enqueue_styles(): void {
		if ( ! self::should_render() ) {
			return;
		}
		wp_enqueue_style(
			'cml-floating-switcher',
			CML_URL . 'assets/floating-switcher.css',
			array(),
			defined( 'CML_BUILD_ID' ) ? CML_BUILD_ID : '0'
		);
	}

	public static function render(): void {
		if ( ! self::should_render() ) {
			return;
		}

		$position  = (string) Settings::get( 'frontend_switcher_position', 'bottom-right' );
		$style     = (string) Settings::get( 'frontend_switcher_style', 'dropdown' );
		$show_flag = (bool) Settings::get( 'frontend_switcher_show_flag', true );

		$markup = LanguageSwitcher::render( $style, $show_flag, true, true, false );
		if ( '' === $markup ) {
			return;
		}

		printf(
			'<div class="cml-floating-switcher cml-pos-%s">%s</div>',
			esc_attr( $position ),
			$markup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup escaped inside LanguageSwitcher::render
		);
	}

	private static function should_render(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( ! (bool) Settings::get( 'frontend_switcher_enabled', true ) ) {
			return false;
		}
		if ( count( Languages::active() ) <= 1 ) {
			return false;
		}
		return true;
	}
}
