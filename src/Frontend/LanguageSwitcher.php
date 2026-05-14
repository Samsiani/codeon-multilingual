<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Frontend;

use Samsiani\CodeonMultilingual\Core\CurrentLanguage;
use Samsiani\CodeonMultilingual\Core\LanguageCatalog;
use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;
use Samsiani\CodeonMultilingual\Url\Router;
use WP_Term;

/**
 * Public language switcher — shortcode, widget, and URL helper.
 *
 * Resolves each language's URL for the CURRENT view:
 *   - singular post/term: linked translation if it exists, else home in target lang
 *   - archive / front page / search: current URL with the lang prefix swapped
 *
 * Three render styles (`style` attribute): `list` (default), `dropdown`, `flags`.
 *
 * Output is intentionally minimal — themes are expected to style via the
 * `.cml-language-switcher` class. Native styles are inline and tiny.
 */
final class LanguageSwitcher {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_shortcode( 'cml_language_switcher', array( self::class, 'render_shortcode' ) );
		add_action( 'widgets_init', array( self::class, 'register_widget' ) );
	}

	public static function register_widget(): void {
		register_widget( LanguageSwitcherWidget::class );
	}

	/**
	 * @param array<string, mixed>|string $atts
	 */
	public static function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'style'        => 'list',
				'show_flag'    => 'no',
				'show_current' => 'yes',
				'show_native'  => 'yes',
				'show_code'    => 'no',
			),
			is_array( $atts ) ? $atts : array(),
			'cml_language_switcher'
		);

		return self::render(
			(string) $atts['style'],
			'yes' === $atts['show_flag'],
			'yes' === $atts['show_current'],
			'yes' === $atts['show_native'],
			'yes' === $atts['show_code']
		);
	}

	public static function render(
		string $style = 'list',
		bool $show_flag = false,
		bool $show_current = true,
		bool $show_native = true,
		bool $show_code = false
	): string {
		$languages = Languages::active();
		if ( count( $languages ) <= 1 ) {
			return '';
		}

		$current_code = CurrentLanguage::code();
		$items        = array();

		foreach ( $languages as $code => $lang ) {
			$is_current = ( $code === $current_code );
			if ( $is_current && ! $show_current ) {
				continue;
			}
			$items[] = array(
				'code'       => $code,
				'native'     => (string) $lang->native,
				'name'       => (string) $lang->name,
				'flag'       => (string) $lang->flag,
				'url'        => self::url_for_language( $code ),
				'is_current' => $is_current,
			);
		}

		if ( array() === $items ) {
			return '';
		}

		return match ( $style ) {
			'dropdown' => self::render_dropdown( $items, $show_flag, $show_native, $show_code ),
			'flags'    => self::render_flags( $items, $show_native, $show_code ),
			default    => self::render_list( $items, $show_flag, $show_native, $show_code ),
		};
	}

	/**
	 * Resolve the URL for the current view in another language.
	 */
	public static function url_for_language( string $code ): string {
		// Single post / page / CPT / attachment.
		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
			if ( $post_id > 0 ) {
				$group = TranslationGroups::get_group_id( $post_id );
				if ( null !== $group ) {
					$siblings   = TranslationGroups::get_siblings( $group );
					$sibling_id = (int) ( array_search( $code, $siblings, true ) ?: 0 );
					if ( $sibling_id > 0 ) {
						return (string) get_permalink( $sibling_id );
					}
				}
			}
			return Router::with_lang( home_url( '/' ), $code );
		}

		// Term archive (category, tag, custom taxonomy).
		if ( is_category() || is_tag() || is_tax() ) {
			$queried = get_queried_object();
			if ( $queried instanceof WP_Term ) {
				$group = TranslationGroups::get_term_group_id( (int) $queried->term_id );
				if ( null !== $group ) {
					$siblings   = TranslationGroups::get_term_siblings( $group );
					$sibling_id = (int) ( array_search( $code, $siblings, true ) ?: 0 );
					if ( $sibling_id > 0 ) {
						$link = get_term_link( $sibling_id );
						if ( is_string( $link ) ) {
							return $link;
						}
					}
				}
			}
			return Router::with_lang( home_url( '/' ), $code );
		}

		// Home / blog / archive / search / 404 — swap the prefix on current URL.
		$scheme  = is_ssl() ? 'https' : 'http';
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : '';
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		$current = '' !== $host ? $scheme . '://' . $host . $uri : home_url( $uri );

		$stripped = Router::strip_lang_prefix( $current );
		return Router::with_lang( $stripped, $code );
	}

	/**
	 * @param array<int, array{code:string,native:string,name:string,flag:string,url:string,is_current:bool}> $items
	 */
	private static function render_list( array $items, bool $show_flag, bool $show_native, bool $show_code ): string {
		$out = '<ul class="cml-language-switcher cml-style-list">';
		foreach ( $items as $i ) {
			$classes = 'cml-language-item';
			if ( $i['is_current'] ) {
				$classes .= ' cml-current';
			}
			$out .= '<li class="' . esc_attr( $classes ) . '">';
			$out .= '<a href="' . esc_url( $i['url'] ) . '" hreflang="' . esc_attr( $i['code'] ) . '" lang="' . esc_attr( $i['code'] ) . '">';
			$out .= self::render_label( $i, $show_flag, $show_native, $show_code );
			$out .= '</a></li>';
		}
		$out .= '</ul>';
		return $out;
	}

	/**
	 * @param array<int, array{code:string,native:string,name:string,flag:string,url:string,is_current:bool}> $items
	 */
	private static function render_flags( array $items, bool $show_native, bool $show_code ): string {
		$out = '<ul class="cml-language-switcher cml-style-flags">';
		foreach ( $items as $i ) {
			$classes = 'cml-language-item';
			if ( $i['is_current'] ) {
				$classes .= ' cml-current';
			}
			$out .= '<li class="' . esc_attr( $classes ) . '">';
			$out .= '<a href="' . esc_url( $i['url'] ) . '" hreflang="' . esc_attr( $i['code'] ) . '" lang="' . esc_attr( $i['code'] ) . '" title="' . esc_attr( $i['native'] ) . '">';
			$out .= self::render_label( $i, true, $show_native, $show_code );
			$out .= '</a></li>';
		}
		$out .= '</ul>';
		return $out;
	}

	/**
	 * @param array<int, array{code:string,native:string,name:string,flag:string,url:string,is_current:bool}> $items
	 */
	private static function render_dropdown( array $items, bool $show_flag, bool $show_native, bool $show_code ): string {
		// Custom button + list so flag <img> tags can sit inside the menu —
		// native <select>/<option> can't render images.
		$current = null;
		foreach ( $items as $i ) {
			if ( $i['is_current'] ) {
				$current = $i;
				break;
			}
		}
		if ( null === $current && ! empty( $items ) ) {
			$current = $items[0];
		}

		$current_label = '';
		if ( null !== $current ) {
			$parts = array();
			if ( $show_flag && '' !== $current['flag'] ) {
				$parts[] = self::flag_markup( $current['flag'], $current['name'] );
			}
			if ( $show_native ) {
				$parts[] = '<span class="cml-native">' . esc_html( $current['native'] ) . '</span>';
			} else {
				$parts[] = '<span class="cml-name">' . esc_html( $current['name'] ) . '</span>';
			}
			if ( $show_code ) {
				$parts[] = '<span class="cml-code">(' . esc_html( $current['code'] ) . ')</span>';
			}
			$current_label = implode( ' ', $parts );
		}

		// Ensure the dropdown's toggle JS is loaded even when the floating
		// switcher is off (shortcode / widget use). wp_enqueue_script is
		// idempotent, so calling it again from FloatingSwitcher is harmless.
		if ( function_exists( 'wp_enqueue_script' ) && ! wp_script_is( 'cml-dropdown-switcher', 'enqueued' ) ) {
			wp_enqueue_script(
				'cml-dropdown-switcher',
				CML_URL . 'assets/dropdown-switcher.js',
				array(),
				defined( 'CML_BUILD_ID' ) ? CML_BUILD_ID : '0',
				true
			);
		}
		if ( function_exists( 'wp_enqueue_style' ) && ! wp_style_is( 'cml-floating-switcher', 'enqueued' ) ) {
			wp_enqueue_style(
				'cml-floating-switcher',
				CML_URL . 'assets/floating-switcher.css',
				array(),
				defined( 'CML_BUILD_ID' ) ? CML_BUILD_ID : '0'
			);
		}

		$out  = '<div class="cml-language-switcher cml-style-dropdown" data-cml-dropdown>';
		$out .= '<button type="button" class="cml-dropdown-toggle" aria-haspopup="listbox" aria-expanded="false">';
		$out .= $current_label;
		$out .= '<span class="cml-dropdown-arrow" aria-hidden="true">▾</span>';
		$out .= '</button>';
		$out .= '<ul class="cml-dropdown-menu" role="listbox" hidden>';
		foreach ( $items as $i ) {
			$out .= '<li role="option"' . ( $i['is_current'] ? ' aria-selected="true"' : '' ) . '>';
			$out .= '<a href="' . esc_url( $i['url'] ) . '" lang="' . esc_attr( $i['code'] ) . '">';
			$out .= self::render_label( $i, $show_flag, $show_native, $show_code );
			$out .= '</a></li>';
		}
		$out .= '</ul></div>';
		return $out;
	}

	/**
	 * @param array{code:string,native:string,name:string,flag:string,url:string,is_current:bool} $i
	 */
	private static function render_label( array $i, bool $show_flag, bool $show_native, bool $show_code ): string {
		$parts = array();
		if ( $show_flag && '' !== $i['flag'] ) {
			$parts[] = self::flag_markup( $i['flag'], $i['name'] );
		}
		if ( $show_native ) {
			$parts[] = '<span class="cml-native">' . esc_html( $i['native'] ) . '</span>';
		} else {
			$parts[] = '<span class="cml-name">' . esc_html( $i['name'] ) . '</span>';
		}
		if ( $show_code ) {
			$parts[] = '<span class="cml-code">(' . esc_html( $i['code'] ) . ')</span>';
		}
		return implode( ' ', $parts );
	}

	/**
	 * Prefer the bundled SVG flag (consistent rendering on Windows / older
	 * systems that lack flag-emoji glyphs) and fall back to the Unicode emoji
	 * when no SVG ships for the country code.
	 */
	private static function flag_markup( string $country_code, string $alt ): string {
		$svg_url = LanguageCatalog::flag_svg_url( $country_code );
		if ( null !== $svg_url ) {
			return '<img class="cml-flag cml-flag-svg" src="' . esc_url( $svg_url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy">';
		}
		return '<span class="cml-flag">' . esc_html( self::flag_emoji( $country_code ) ) . '</span>';
	}

	/**
	 * Map an ISO 3166-1 alpha-2 country code to the corresponding flag emoji
	 * via regional-indicator codepoints. Returns empty string for invalid input.
	 */
	private static function flag_emoji( string $code ): string {
		$code = strtoupper( trim( $code ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return '';
		}
		$first  = mb_chr( 0x1F1E6 + ord( $code[0] ) - ord( 'A' ), 'UTF-8' );
		$second = mb_chr( 0x1F1E6 + ord( $code[1] ) - ord( 'A' ), 'UTF-8' );
		return $first . $second;
	}
}
