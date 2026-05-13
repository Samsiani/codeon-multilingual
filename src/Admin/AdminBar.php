<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Admin;

use Samsiani\CodeonMultilingual\Content\PostTranslator;
use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use WP_Admin_Bar;

/**
 * Frontend + admin bar language menu.
 *
 * Top node: current language indicator (links to the Languages admin page).
 * Sub-nodes on singular post screens / single-post URLs: jump to the same post
 * in another language, or trigger "Add translation" when none exists.
 */
final class AdminBar {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_bar_menu', [ self::class, 'on_admin_bar' ], 80 );
	}

	public static function on_admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$current_code = CurrentLanguage::code();
		$current_lang = Languages::get( $current_code );
		$label        = $current_lang ? $current_lang->native : $current_code;

		$bar->add_node(
			[
				'id'    => 'cml-lang',
				'title' => '<span class="ab-icon dashicons dashicons-translation" style="margin-top:2px"></span>'
					. '<span class="ab-label">' . esc_html( $label ) . '</span>',
				'href'  => admin_url( 'admin.php?page=' . AdminMenu::PARENT_SLUG ),
			]
		);

		$post_id = self::current_post_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$group_id = TranslationGroups::get_group_id( $post_id ) ?? $post_id;
		$siblings = TranslationGroups::get_siblings( $group_id );

		foreach ( Languages::active() as $code => $lang ) {
			if ( $code === $current_code ) {
				continue;
			}

			$sibling_id = (int) ( array_search( $code, $siblings, true ) ?: 0 );

			if ( $sibling_id > 0 ) {
				$url = is_admin()
					? (string) ( get_edit_post_link( $sibling_id, 'raw' ) ?: '' )
					: (string) get_permalink( $sibling_id );

				/* translators: %s: language native name */
				$title = sprintf( __( 'Open %s version', 'codeon-multilingual' ), $lang->native );
				$icon  = 'dashicons-yes-alt';
				$color = '#46b450';
			} else {
				$url   = PostTranslator::add_translation_url( $post_id, $code );

				/* translators: %s: language native name */
				$title = sprintf( __( 'Add %s translation', 'codeon-multilingual' ), $lang->native );
				$icon  = 'dashicons-plus-alt';
				$color = '#2271b1';
			}

			$bar->add_node(
				[
					'id'     => 'cml-lang-' . $code,
					'parent' => 'cml-lang',
					'title'  => '<span class="dashicons ' . esc_attr( $icon ) . '" style="color:' . esc_attr( $color ) . ';margin-right:6px"></span>'
						. esc_html( $title ),
					'href'   => $url,
					'meta'   => [ 'html' => true ],
				]
			);
		}
	}

	private static function current_post_id(): int {
		if ( is_admin() ) {
			global $post;
			if ( $post instanceof \WP_Post ) {
				return (int) $post->ID;
			}
			if ( isset( $_GET['post'] ) ) {
				return (int) $_GET['post'];
			}
			return 0;
		}
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}
		return 0;
	}
}
