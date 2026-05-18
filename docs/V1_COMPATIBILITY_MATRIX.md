# v1.0 Compatibility Matrix

This matrix is the certification plan for WordPress/WooCommerce production
readiness. "Baseline" means translated posts/terms are cloned and editable.
"Adapter" means CodeOn also understands plugin-specific JSON, serialized data,
shortcodes, templates, dynamic IDs, strings, or cache behavior.

## Page Builders And Theme Builders

| Target | Priority | Current posture | v1.0 certification scope |
|---|---:|---|---|
| Gutenberg / Block Editor | P0 | Attribute adapter started | `parse_blocks()` ID/link walker, reusable `wp_block`, navigation block, dynamic blocks |
| WooCommerce Blocks / Store API | P0 | Partial | Cart, checkout, notices, product grids, variation add-to-cart, REST/Store API language |
| Elementor | P0 | JSON adapter started | `_elementor_data` JSON strings, template IDs, links, widgets, loop/grid widgets |
| Elementor Pro | P0 | Needs adapter | Theme Builder, forms, popups, Woo widgets, dynamic tags, conditions |
| ACF / ACF Pro | P0 | Field-value adapter started | Options pages per language, flexible/repeater walker, relationship fields, field group policy |
| Yoast SEO | P0 | Adapter started | Breadcrumb and schema URL/object localization with static asset/REST/admin URL guards; sitemap/canonical smoke tests remain |
| Rank Math | P0 | Adapter started | Breadcrumb and schema URL/object localization with static asset/REST/admin URL guards; sitemap/canonical smoke tests remain |
| Avada / Fusion Builder | P1 | Baseline shortcode/meta clone | Global elements, layout sections, dynamic data, shortcode ID/link remapping |
| Divi Builder / Divi Theme Builder | P1 | Baseline shortcode clone | `et_pb_layout`, global modules, theme builder templates, shortcode ID/link remapping |
| WPBakery | P1 | Good shortcode baseline | Template/library refs, grid explicit IDs, Woo elements, shortcode attribute remapping |
| Visual Composer Website Builder | P1 | Partial | Hub templates, shortcode/template refs, frontend editor smoke tests |
| Beaver Builder / Beaver Themer | P1 | Serialized meta adapter started | `_fl_builder_data`, saved rows/modules, Themer layouts, serialized strings/IDs |
| Bricks Builder | P1 | Needs adapter | Builder content meta, templates, query loops, conditions, dynamic data, ACF integration |
| Oxygen Builder | P1 | Needs adapter | Templates, conditions, reusable parts, shortcodes/meta, query components |
| Breakdance | P1 | Needs adapter | Builder data store, templates, conditions, forms, AJAX rendering |
| Brizy | P2 | Needs adapter | Project JSON/meta, global blocks, forms, templates |
| SiteOrigin Page Builder | P2 | Partial | `panels_data` serialized widget strings, post/page IDs, widgets bundle |
| Thrive Architect / Theme Builder | P2 | Needs adapter | Architect content data, theme templates, symbols, forms/leads |
| Themify Builder | P2 | Partial | Builder data/meta, layout parts, global rows, module IDs |
| Kadence Blocks / Kadence Elements | P1 | Gutenberg baseline | Elements/templates, Row Layout, Advanced Gallery, dynamic content |
| GenerateBlocks / GeneratePress Elements | P1 | Gutenberg baseline | Query loops, Elements, local/global styles |
| Spectra / Ultimate Addons for Gutenberg | P2 | Gutenberg baseline | Blocks with IDs/links/forms, dynamic content |
| Stackable | P2 | Gutenberg baseline | Block attributes, dynamic content, design library templates |
| Otter Blocks | P2 | Gutenberg baseline | Dynamic content, forms, block visibility |
| CoBlocks | P3 | Gutenberg baseline | Block attributes and gallery/media references |
| SeedProd | P2 | Needs adapter | Landing page builder data, forms, theme templates |
| Cornerstone / Themeco Pro | P2 | Needs adapter | Layout builder data, global blocks, conditions |
| Zion Builder | P3 | Needs adapter | Builder data, templates, reusable elements |
| PageLayer | P3 | Needs adapter | Builder data/meta, forms, templates |
| MotoPress Content Editor | P3 | Needs adapter | Shortcode/content handling and template refs |
| Live Composer | P3 | Needs adapter | Legacy shortcode/content handling |

## WooCommerce And Payments

| Target | Priority | v1.0 certification scope |
|---|---:|---|
| WooCommerce core | P0 | Products, variations, attributes, categories, tags, cart, checkout, emails, downloadable files |
| WooCommerce Blocks | P0 | Store API language bridge, cart item object/permalink swap, shared variation display hooks, and Store API notice/error message translation are covered; cart/checkout block response smoke tests remain |
| Stripe | P0 | Gateway title/description, checkout labels, order emails, webhook-created notes |
| PayPal Payments | P0 | Gateway labels, hosted-button redirects, order notes/emails |
| Bank transfer / COD / Cheque | P0 | Core method title/description and email text |
| Klarna / Afterpay / Apple Pay / Google Pay | P1 | PHP-visible labels plus hosted/iframe limitations documented |
| Subscriptions / Bookings | P1 | Product type labels, cart/order/email language, renewal emails |

## Cache, Search, And Infrastructure

| Target | Priority | v1.0 certification scope |
|---|---:|---|
| Redis Object Cache | P0 | Language/string cache groups, stale cache health, flush tools |
| Memcached object cache | P1 | Same as Redis |
| LiteSpeed Cache | P0 | URL/path variation, purge on translation/language/string changes through `litespeed_purge_*` actions |
| WP Rocket | P0 | Page cache purge through `rocket_clean_post()`, `rocket_clean_files()`, and `rocket_clean_domain()` when present |
| W3 Total Cache / WP Super Cache | P1 | Page cache purge and object cache behavior |
| Cloudflare APO/CDN | P1 | Path-based cache variation and purge guidance |
| Relevanssi / SearchWP | P1 | Index per language, query filtering, translated terms |
| WP-CLI | P0 | Every admin migration/repair operation has a CLI equivalent |
| Multisite | P1 decision | Either explicitly unsupported or certified for sub-sites with isolation |

## v1.0 Test Rule

Every P0 target needs automated or repeatable scripted tests. P1 targets need at
least one documented smoke test on a real staging store before v1.0 final.
