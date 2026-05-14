<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

/**
 * Server-rendered Gutenberg block wrapping LanguageSwitcher::render().
 *
 * Registered via WP's block.json registry (WP 5.5+ for `register_block_type`
 * + 5.8+ for `block.json` discovery). The editor uses ServerSideRender
 * (loaded via `editor.js`) for a live preview so authors can see the actual
 * switcher markup, not a generic placeholder.
 *
 * Attributes mirror LanguageSwitcher's render() signature:
 *   - style:      'list' | 'dropdown' | 'flags'
 *   - showFlag:   bool
 *   - showNative: bool
 *   - showCode:   bool
 *
 * Server-rendered means there's no client-saved HTML — the block stays
 * `<!-- wp:codeon-multilingual/switcher /-->` in post content and renders
 * fresh on every request, so adding a new language or changing a translation
 * shows up immediately without re-saving every post that contains the block.
 */
final class SwitcherBlock {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'init', array( self::class, 'register_block' ) );
	}

	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$block_dir = CML_PATH . 'assets/blocks/switcher';
		if ( ! is_readable( $block_dir . '/block.json' ) ) {
			return;
		}
		register_block_type(
			$block_dir,
			array(
				'render_callback' => array( self::class, 'render' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function render( array $attributes ): string {
		$style      = isset( $attributes['style'] ) ? (string) $attributes['style'] : 'dropdown';
		$show_flag  = ! empty( $attributes['showFlag'] );
		$show_nat   = ! empty( $attributes['showNative'] );
		$show_code  = ! empty( $attributes['showCode'] );

		// Sanitise style to a known value so a hand-edited block doesn't pass
		// arbitrary strings through to LanguageSwitcher.
		if ( ! in_array( $style, array( 'list', 'dropdown', 'flags' ), true ) ) {
			$style = 'dropdown';
		}

		// Always pass `show_current = true` so the switcher reflects the
		// current language regardless of style — the block's "show flag"
		// toggle is finer-grained than the legacy widget's options.
		$markup = LanguageSwitcher::render( $style, $show_flag, true, $show_nat, $show_code );
		if ( '' === $markup ) {
			return '';
		}

		$wrapper_attrs = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( array( 'class' => 'wp-block-codeon-multilingual-switcher' ) )
			: 'class="wp-block-codeon-multilingual-switcher"';

		return '<div ' . $wrapper_attrs . '>' . $markup . '</div>';
	}
}
