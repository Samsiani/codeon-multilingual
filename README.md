# CodeOn Multilingual

A lightweight multilingual plugin for WordPress and WooCommerce. WPML-compatible feature surface at a fraction of the weight.

[![CI](https://github.com/Samsiani/codeon-multilingual/actions/workflows/ci.yml/badge.svg)](https://github.com/Samsiani/codeon-multilingual/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/Samsiani/codeon-multilingual?label=release)](https://github.com/Samsiani/codeon-multilingual/releases/latest)

## Why this exists

WPML and Polylang work, but they carry a lot of 2009-era baggage: 17+ DB tables, polymorphic indexes that MySQL plans poorly, React-heavy admin bundles, translation-workflow surface most sites never use. We deliver the same outcomes at **~10% of the disk weight** and **~10–20% of the per-request query/memory cost**.

| Dimension | CodeOn Multilingual | Polylang | WPML |
|---|---|---|---|
| PHP code (LOC) | ~7 710 | ~30–50k | ~100k+ across the suite |
| Release ZIP | ~370 KB | ~3 MB | ~50–150 MB |
| DB tables added | 5 | 2 + reused taxonomy | 17+ |
| Frontend JS shipped by default | 0 KB | ~30 KB | ~200 KB+ |
| Admin JS bundle | ~6 KB vanilla | ~120 KB jQuery+UI | 400 KB+ React |
| Extra queries per WP_Query | 1 LEFT JOIN | 2–3 (taxonomy joins) | 3–7 |
| `gettext` hot path overhead | off by default | filter per call | filter + DB hit |
| Memory per request | <1.5 MB | 3–5 MB | 8–15 MB |

## Status

**v0.7.1** — live in production on artcase.ge. ~90% of the locked v0.1.0 MVP scope is shipped, plus migration tooling, inline string editor, scan-based discovery, WP 6.5+ native `.l10n.php` translation path, the **WPML compatibility shim** (13 API surfaces — themes/plugins written against WPML's public API run unmodified), and a full **WP-CLI command surface** for ops/CI workflows.

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
- 20 prop sync across translation group (price, stock, sale dates, dimensions, tax, SKU…)
- Group-keyed lock prevents recursion

**Strings**
- Hash-keyed compiled map in wp_cache (default fallback path)
- Opt-in WP 6.5+ native `.l10n.php` writer (atomic + opcache-aware)
- File-system scanner with regex for `__`, `_e`, `_x`, `_ex`, `esc_*`
- Inline popup editor (vanilla JS, click-outside-saves)
- Per-string source-language detection + badge

**Frontend**
- Shortcode `[cml_language_switcher]` + classic widget + auto-floating switcher
- Hreflang link tags + `x-default`
- `<html lang>` + `dir` rewriting per current view
- WP-core sitemap participation (all-language enumeration)

**Admin**
- Per-post-type language quick-link row above the list table
- "Languages" column with translate icons (globe / pen / +)
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

1. Activate the plugin
2. **Multilingual → Languages** — add a second language (the site default is seeded from `get_locale()` on activation)
3. **Multilingual → Settings** — choose frontend switcher position/style; optionally enable native `.l10n.php` files for faster string lookups
4. Edit any post — use the **Translations** meta box to create translations
5. Visit `/{lang}/` on the front-end to see the translated site

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
