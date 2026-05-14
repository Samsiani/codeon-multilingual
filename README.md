# CodeOn Multilingual

A lightweight multilingual plugin for WordPress and WooCommerce. WPML-compatible feature surface at a fraction of the weight.

[![CI](https://github.com/Samsiani/codeon-multilingual/actions/workflows/ci.yml/badge.svg)](https://github.com/Samsiani/codeon-multilingual/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/Samsiani/codeon-multilingual?label=release)](https://github.com/Samsiani/codeon-multilingual/releases/latest)

## Why this exists

WPML and Polylang work, but they carry a lot of 2009-era baggage: 17+ DB tables, polymorphic indexes that MySQL plans poorly, React-heavy admin bundles, translation-workflow surface most sites never use. We deliver the same outcomes at **~10% of the disk weight** and **~10–20% of the per-request query/memory cost**.

| Dimension | CodeOn Multilingual | Polylang | WPML |
|---|---|---|---|
| PHP code (LOC) | ~10.9 k | ~30–50 k | ~100 k+ across the suite |
| Release ZIP | ~700 KB (incl. 60 SVG flags + catalog) | ~3 MB | ~50–150 MB |
| DB tables added | 5 | 2 + reused taxonomy | 17+ |
| Frontend JS shipped by default | ~1.4 KB (dropdown toggle) | ~30 KB | ~200 KB+ |
| Admin JS bundle | ~6 KB vanilla | ~120 KB jQuery+UI | 400 KB+ React |
| Extra queries per WP_Query | 1 LEFT JOIN | 2–3 (taxonomy joins) | 3–7 |
| `gettext` hot path overhead | 1 isset() per call against a request-static map | filter per call | filter + DB hit |
| Memory per request | <1.5 MB | 3–5 MB | 8–15 MB |

## Status

**v0.7.23** — live in production on artcase.ge. The locked v0.1.0 MVP scope is shipped, plus migration tooling, inline string editor, scan-based discovery, WP 6.5+ native `.l10n.php` translation path, the **WPML compatibility shim** (13 API surfaces — themes/plugins written against WPML's public API run unmodified), a full **WP-CLI command surface** for ops/CI workflows, a **first-run setup wizard** with a bundled 66-language catalog, **bundled SVG flags** (60 countries — Windows-safe vs emoji-only rendering), **per-language compiled-map cache** (each request can serve any number of languages without cross-contamination), and a complete **WooCommerce translation surface** (product field locking on translations, automatic shop-page mapping per language, cart items follow current language with fallback to original).

See [`ROADMAP.md`](ROADMAP.md) for what's built, what's missing, and what's next.

## Features at a glance

**Routing**
- Subdirectory URL strategy (`/ka/`, `/en/`), virtual prefix, no flush_rewrite_rules() needed
- Single `LEFT JOIN` injection on every `WP_Query` and `WP_Term_Query`
- Per-language locale switching (`<html lang>`, `.mo` bundle selection, hreflang)

**Content**
- Post / Page / CPT / Attachment translation via deep `post_meta` clone
- Taxonomy term translation with hierarchical parent mapping
- Slug uniqueness scoped per (post_type, post_parent, language)
- Auto-tag new posts on `save_post`
- Cron-driven backfill for legacy content

**WooCommerce**
- Product translation with full variation auto-clone
- 22 props synced across the translation group: price, stock, manage_stock, backorders, sold_individually, low_stock_amount, sale dates, dimensions, weight, tax_class, tax_status, downloadable, virtual, SKU, GTIN/UPC/EAN/ISBN, shipping class
- Field-lock UI on translations (WPML-style): pricing, SKU, GTIN, stock controls, dimensions, shipping class, tax, virtual/downloadable, and the entire Attributes panel render disabled + read-only on translated products; banner reads "You're editing a translation of <Source> — edit the original to change these"
- Shop-page mapping per language: on `/en/cart/`, `wc_get_page_id('cart')` returns the English cart page so `is_cart()` matches and the cart template fires
- Cart items follow current language: line items, mini-cart, checkout review, order-received page, and emails swap to the translation; items without a translation stay in their original language
- WC's "duplicate SKU" validator suppresses errors when the conflict is just another sibling in the same translation group
- Translated WP pages without a sibling in the current language fall back to the source page rather than 404
- Group-keyed lock prevents recursion during sibling sync

**Strings**
- Hash-keyed compiled map in wp_cache, **keyed per language** so a request that touches multiple locales (e.g. WP boot's early `__()` calls + later router lang switch) never bleeds one language's translations into another
- Translations into the **default language** apply correctly (the source code is in English; the site default is Georgian; the Georgian translation of an English source string IS the right thing to render)
- Opt-in WP 6.5+ native `.l10n.php` writer (atomic + opcache-aware)
- File-system scanner with regex for `__`, `_e`, `_x`, `_ex`, `esc_*`
- Inline popup editor (vanilla JS, click-outside-saves)
- Per-string source-language detection + badge

**Frontend**
- Shortcode `[cml_language_switcher]` + classic widget + auto-floating switcher + nav-menu item + Gutenberg block — five drop-in surfaces wrapping the same `LanguageSwitcher::render()`
- Nav-menu item: side meta-box on Appearance → Menus inserts a single placeholder that auto-expands at render time, with per-item display (names / flags / both) and layout (inline / dropdown) settings
- Gutenberg block (`codeon-multilingual/switcher`): server-rendered, `ServerSideRender` preview in the editor, style + show-flag/native/code attributes, wide/full alignment + spacing controls
- Custom button/menu dropdown with bundled SVG flags (60 countries, MIT-licensed from lipis/flag-icons; Windows desktop without flag-emoji glyphs renders properly now)
- Dropdown menu auto-sizes to the widest item, opens upward when anchored at the bottom of the viewport, and locks to the floating-switcher box width with a measure-and-pin JS pass that's stable across repeat clicks
- Hreflang link tags + `x-default`
- `<html lang>` + `dir` rewriting per current view
- WP-core sitemap participation (all-language enumeration)

**Admin**
- First-run setup wizard at `admin.php?page=cml-setup` — 4 steps (welcome → default language → secondary languages → done), 66-language catalog, search-as-you-type, SVG flags, auto-fills locale/native/RTL when a code is recognised
- Defensive redirect: `admin-post.php?page=cml-setup&step=N` 302s to the canonical `admin.php?...` so stale bookmarks don't white-screen
- Languages admin list shows SVG flag per row + "Run setup wizard" page-title action
- "Add Language" form auto-fills locale, English name, native name, flag, RTL from the catalog as the admin types a code
- Per-post-type language quick-link row above the list table
- "Languages" column with translate icons (globe / pen / +)
- Admin bar language indicator reflects the **edited post's** language (not the admin user's) on edit screens; sub-menu jumps directly to sibling edit screens; "Add translation" always creates from the group source
- "Permalink:" preview on the translation edit screen now reflects the post's own language URL
- Translation meta box distinguishes the **source** ("Edit X (original)") from translations ("Edit X translation")
- Cookie-persisted language selection
- WPML data migration tool (5-statement SQL importer)

**REST**
- `?lang=` parameter on every `show_in_rest` endpoint
- `POST /cml/v1/strings/<id>/translations` for inline editor

**WPML compatibility**
- `icl_object_id`, `icl_get_languages`, `icl_get_default_language`, `icl_get_current_language` functions
- `wpml_object_id`, `wpml_current_language`, `wpml_default_language`, `wpml_active_languages`, `wpml_post_language_details`, `wpml_element_has_translations`, `wpml_translate_single_string` filters
- `wpml_register_single_string`, `wpml_switch_language` actions
- Astra, GeneratePress, WoodMart, Elementor, YITH, Woo extensions work without code changes

**WP-CLI**
- `wp cml language list / create / activate / deactivate / set-default / delete`
- `wp cml translate post <id> --to=<lang>` / `wp cml translate term <id> --tax=<tax> --to=<lang>`
- `wp cml strings scan [--theme=<slug>] [--plugin=<slug>] [--all]`
- `wp cml strings export --lang=<code> [--format=po|json] [--domain=<d>] [--output=<file>]`
- `wp cml strings import <file> [--format=auto|po|json] [--lang=<code>]`
- `wp cml migrate wpml [--dry-run]`
- `wp cml backfill run [--all]` / `status` / `reset`

## Requirements

- PHP 8.1+
- WordPress 6.5+
- WooCommerce 8.0+ (optional, only for product sync)

## Install

### Production (auto-updates)

Download the latest ZIP from [Releases](https://github.com/Samsiani/codeon-multilingual/releases) and install via **Plugins → Add New → Upload**. Plugin Update Checker keeps it current as new tags are pushed.

### Development

```bash
git clone https://github.com/Samsiani/codeon-multilingual.git
cd codeon-multilingual
composer install
ln -s "$(pwd)" /path/to/wp-content/plugins/codeon-multilingual
```

Then activate in **Plugins**.

See [`DEVELOPMENT.md`](DEVELOPMENT.md) for the full dev workflow.

## Quick start

1. Activate the plugin — the setup wizard opens automatically on first run, walking you through default + secondary language selection from a bundled catalog of 66 curated languages.
2. (After the wizard) Edit any post — use the **Translations** meta box to create translations.
3. Visit `/{lang}/` on the front-end to see the translated site.
4. **Multilingual → Settings** — tune the floating switcher and the optional native `.l10n.php` translation path.

If you skipped the wizard, "Run setup wizard" stays available as a link on the Languages page.

## Documentation

| Doc | Purpose |
|---|---|
| [`README.md`](README.md) | This file — overview and quick start |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Deep dive: modules, design decisions, hot paths, schema, request lifecycle |
| [`ROADMAP.md`](ROADMAP.md) | Current state, gap analysis, prioritised next steps |
| [`CHANGELOG.md`](CHANGELOG.md) | Per-release notes |
| [`DEVELOPMENT.md`](DEVELOPMENT.md) | Dev environment, tests, release workflow |

## License

GPL-2.0-or-later. See plugin header.
