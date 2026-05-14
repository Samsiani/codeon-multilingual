<?php
declare(strict_types=1);

namespace Samsiani\CodeonMultilingual\Woo;

use Samsiani\CodeonMultilingual\Core\Languages;
use Samsiani\CodeonMultilingual\Core\TranslationGroups;

/**
 * Lock non-translatable product fields on translations.
 *
 * `Woo/ProductSync` already fans non-translatable props (price, stock, SKU,
 * dimensions, shipping class, GTIN, attributes…) across every sibling on save,
 * so editing them on a translation is meaningless — the next save of the
 * source would overwrite the edit. This module makes that explicit in the UI:
 *
 *   - A top-of-screen banner reads "This is a translation of <Source>…"
 *   - Every synced field gets a "locked because it copies from the original
 *     language" notice and is rendered disabled (mirrors WPML's UX).
 *   - The WC duplicate-SKU validator is silenced when the conflicting
 *     product is a sibling in the same translation group, so creating a
 *     translation (which inherits the source's SKU) doesn't error.
 *
 * Source detection: a product is a translation iff `group_id != post_id`
 * (the schema seeds `group_id = post_id` on the original, and translations
 * inherit the source's group_id).
 */
final class TranslationLock {

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'add_meta_boxes_product', array( self::class, 'maybe_lock_translation' ), 100 );
		add_filter( 'wc_product_has_unique_sku', array( self::class, 'allow_sibling_sku_duplicate' ), 10, 3 );
	}

	/**
	 * Source ID for a given product, or null when this product IS the source.
	 */
	public static function source_id_for( int $product_id ): ?int {
		$group_id = TranslationGroups::get_group_id( $product_id );
		if ( null === $group_id ) {
			return null;
		}
		if ( $group_id === $product_id ) {
			return null;
		}
		return $group_id;
	}

	/**
	 * @param \WP_Post|null $post
	 */
	public static function maybe_lock_translation( $post = null ): void {
		if ( ! $post instanceof \WP_Post ) {
			global $post;
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$source_id = self::source_id_for( (int) $post->ID );
		if ( null === $source_id ) {
			return;
		}

		$source_post = get_post( $source_id );
		if ( ! $source_post instanceof \WP_Post ) {
			return;
		}

		$source_lang       = TranslationGroups::get_language( $source_id ) ?? Languages::default_code();
		$source_lang_entry = Languages::get( $source_lang );
		$source_lang_label = $source_lang_entry ? $source_lang_entry->native : $source_lang;
		$edit_url          = (string) ( get_edit_post_link( $source_id, 'raw' ) ?: '' );

		add_action(
			'admin_footer-post.php',
			static function () use ( $source_post, $source_lang_label, $edit_url ): void {
				self::render_assets( (string) $source_post->post_title, $source_lang_label, $edit_url );
			}
		);
		add_action(
			'admin_footer-post-new.php',
			static function () use ( $source_post, $source_lang_label, $edit_url ): void {
				self::render_assets( (string) $source_post->post_title, $source_lang_label, $edit_url );
			}
		);
	}

	/**
	 * WC's duplicate-SKU validator (`wc_product_has_unique_sku`) returns true
	 * when the same SKU is found on another product. When that "other product"
	 * is a sibling in the same translation group we WANT them to share the SKU
	 * (ProductSync syncs it deliberately) — so swallow the duplicate flag.
	 *
	 * @param bool|mixed $sku_found
	 * @param int|mixed  $product_id
	 * @param string     $sku
	 * @return bool
	 */
	public static function allow_sibling_sku_duplicate( $sku_found, $product_id, $sku ): bool {
		if ( ! $sku_found ) {
			return (bool) $sku_found;
		}
		$product_id = (int) $product_id;
		if ( $product_id <= 0 || '' === $sku ) {
			return (bool) $sku_found;
		}
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return (bool) $sku_found;
		}
		$existing_id = (int) wc_get_product_id_by_sku( $sku );
		if ( $existing_id <= 0 || $existing_id === $product_id ) {
			return (bool) $sku_found;
		}
		$g1 = TranslationGroups::get_group_id( $product_id );
		$g2 = TranslationGroups::get_group_id( $existing_id );
		if ( null !== $g1 && null !== $g2 && $g1 === $g2 ) {
			return false;
		}
		return (bool) $sku_found;
	}

	private static function render_assets( string $source_title, string $source_lang_label, string $source_edit_url ): void {
		// Two pieces of text the JS injects via textContent (NOT innerHTML).
		// We build banner text from primitives so there's no HTML concatenation
		// in the script. The source-product title becomes an anchor in PHP
		// only if a URL is present, then exposed to the JS as a structured
		// payload of { prefix, linkText, linkUrl, suffix }.
		$banner_prefix   = __( "You're editing a translation of ", 'codeon-multilingual' );
		$banner_suffix   = sprintf(
			/* translators: %s: source language native name */
			__( ' (%s). Synced fields below are locked — edit the original to change them.', 'codeon-multilingual' ),
			$source_lang_label
		);
		$field_note      = __( 'Locked — copies from the original language. Edit the source product to change this.', 'codeon-multilingual' );
		$banner_payload  = array(
			'prefix'   => $banner_prefix,
			'linkText' => $source_title,
			'linkUrl'  => $source_edit_url,
			'suffix'   => $banner_suffix,
		);

		// Selectors map onto WooCommerce's stable product edit-screen field IDs
		// plus attribute panels. Tested against WC 8.0+. If WC renames a field
		// the lock degrades gracefully — the JS just doesn't find it.
		$selectors = array(
			// Pricing
			'#_regular_price',
			'#_sale_price',
			'#_sale_price_dates_from',
			'#_sale_price_dates_to',
			'.sale_price_dates_fields a.sale_schedule',
			'.sale_price_dates_fields a.cancel_sale_schedule',
			// Inventory
			'#_sku',
			'#_global_unique_id',
			'#_manage_stock',
			'#_stock',
			'#_low_stock_amount',
			'#_sold_individually',
			'input[name="_backorders"]',
			'select[name="_backorders"]',
			'#_stock_status',
			'input[name="_stock_status"]',
			// Shipping
			'#_weight',
			'#_length',
			'#_width',
			'#_height',
			'#product_shipping_class',
			'select[name="product_shipping_class"]',
			// Tax / virtual / downloadable
			'#_tax_status',
			'#_tax_class',
			'#_virtual',
			'#_downloadable',
		);
		// Containers that we lock wholesale (visually disable everything inside).
		$panel_selectors = array(
			'#product_attributes', // Attributes tab
		);

		?>
		<style>
			.cml-lock-banner {
				position: relative;
				margin: 12px 0 14px;
				padding: 11px 14px;
				background: #f0f6fc;
				border-left: 4px solid #2271b1;
				border-radius: 3px;
				color: #1d2327;
				font-size: 13px;
				line-height: 1.45;
			}
			.cml-lock-banner a { font-weight: 600; }
			.cml-locked-wrap { position: relative; }
			.cml-locked-wrap > input,
			.cml-locked-wrap > select,
			.cml-locked-wrap > textarea {
				background: #f6f7f7 !important;
				color: #50575e !important;
				cursor: not-allowed !important;
			}
			.cml-lock-note {
				display: block;
				margin-top: 4px;
				font-size: 11px;
				color: #646970;
				font-style: italic;
			}
			.cml-locked-panel {
				position: relative;
				pointer-events: none;
				opacity: 0.55;
			}
			.cml-locked-panel::before {
				content: attr(data-cml-note);
				display: block;
				position: sticky;
				top: 0;
				z-index: 10;
				margin: 0 0 8px;
				padding: 6px 10px;
				background: #f0f6fc;
				border-left: 3px solid #2271b1;
				color: #1d2327;
				font-size: 12px;
				font-style: italic;
				pointer-events: auto;
			}
		</style>
		<script>
			(function () {
				'use strict';
				const SELECTORS = <?php echo wp_json_encode( $selectors ); ?>;
				const PANEL_SELECTORS = <?php echo wp_json_encode( $panel_selectors ); ?>;
				const FIELD_NOTE = <?php echo wp_json_encode( $field_note ); ?>;
				const BANNER = <?php echo wp_json_encode( $banner_payload ); ?>;

				function lockField(el) {
					if (!el || el.dataset.cmlLocked) return;
					el.dataset.cmlLocked = '1';
					el.setAttribute('disabled', 'disabled');
					el.setAttribute('readonly', 'readonly');
					const wrap = document.createElement('div');
					wrap.className = 'cml-locked-wrap';
					el.parentNode.insertBefore(wrap, el);
					wrap.appendChild(el);
					const note = document.createElement('span');
					note.className = 'cml-lock-note';
					note.textContent = FIELD_NOTE;
					wrap.appendChild(note);
				}

				function lockPanel(panel) {
					if (!panel || panel.dataset.cmlLocked) return;
					panel.dataset.cmlLocked = '1';
					panel.classList.add('cml-locked-panel');
					panel.setAttribute('data-cml-note', FIELD_NOTE);
					panel.querySelectorAll('input, select, textarea, button').forEach(function (el) {
						el.setAttribute('disabled', 'disabled');
					});
				}

				function buildBanner() {
					const banner = document.createElement('div');
					banner.className = 'cml-lock-banner notice notice-info';
					banner.appendChild(document.createTextNode(BANNER.prefix));
					if (BANNER.linkText) {
						banner.appendChild(document.createTextNode('"'));
						if (BANNER.linkUrl) {
							const a = document.createElement('a');
							a.href = BANNER.linkUrl;
							a.textContent = BANNER.linkText;
							banner.appendChild(a);
						} else {
							const strong = document.createElement('strong');
							strong.textContent = BANNER.linkText;
							banner.appendChild(strong);
						}
						banner.appendChild(document.createTextNode('"'));
					}
					banner.appendChild(document.createTextNode(BANNER.suffix));
					return banner;
				}

				function init() {
					const heading = document.querySelector('.wrap > h1.wp-heading-inline') || document.querySelector('.wrap > h1');
					if (heading && !document.querySelector('.cml-lock-banner')) {
						heading.parentNode.insertBefore(buildBanner(), heading.nextSibling);
					}
					SELECTORS.forEach(function (sel) {
						document.querySelectorAll(sel).forEach(lockField);
					});
					PANEL_SELECTORS.forEach(function (sel) {
						document.querySelectorAll(sel).forEach(lockPanel);
					});
				}

				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', init);
				} else {
					init();
				}
				// WC tabs sometimes lazy-render — re-scan after a short delay.
				setTimeout(init, 600);
				setTimeout(init, 1500);
			})();
		</script>
		<?php
	}
}
