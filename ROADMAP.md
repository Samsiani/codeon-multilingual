# Roadmap & gap analysis

Current version: **v0.7.1** (released 2026-05-14)

This file is the single source of truth for "what's done vs what's planned." Every release updates the relevant rows.

## Where we are

~90% of the locked v0.1.0 MVP scope is shipped, plus migration tooling, inline string editor, scan-based discovery, WP 6.5+ native `.l10n.php` translation path, and the WPML compatibility shim. Production deployment validated on artcase.ge with 32 passing unit tests.

## Status vs v0.1.0 MVP commitments

| Commitment | Status | Notes |
|---|---|---|
| Subdirectory URL routing | ✅ done | `Url/SubdirectoryStrategy.php` |
| Post / Page / CPT translation | ✅ done | `Content/PostTranslator.php` |
| Taxonomy term translation | ✅ done | `Content/TermTranslator.php` |
| Attachment translation | ✅ done | included in `translatable_post_types()`, status falls back to `inherit` |
| **Menu translation flow** | ⚠️ **partial** | No dedicated "translate this menu" admin action. Menu items inherit via post/term plumbing. |
| String translation — wp_cache path | ✅ done | `Strings/StringTranslator.php` |
| String translation — `.l10n.php` path | ✅ done (opt-in) | `Strings/L10nFileWriter.php`, v0.6.0 |
| Compiled-blob caching | ✅ done | both paths |
| WC product translation | ✅ done | via PostTranslator |
| WC variation auto-clone | ✅ done | `Woo/VariationTranslator.php` |
| WC stock/price/dimensions/tax sync | ✅ done | 20 synced props, `Woo/ProductSync.php` |
| **WC shipping-zone strings (curated)** | ❌ **missing** | Captured only via auto-discovery when on |
| **WC payment-method titles (curated)** | ❌ **missing** | Same |
| **WC email subjects/bodies (curated)** | ❌ **missing** | Same |
| **WC cart/checkout strings (curated)** | ❌ **missing** | Same |
| Language switcher — shortcode | ✅ done | `[cml_language_switcher]` |
| Language switcher — classic widget | ✅ done | `Frontend/LanguageSwitcherWidget.php` |
| Language switcher — auto-floating | ✅ done | `Frontend/FloatingSwitcher.php`, v0.5.0 |
| **Language switcher — Gutenberg block** | ❌ **missing** | Legacy-widget block covers it indirectly |
| **Language switcher — nav menu item** | ❌ **missing** | No `wp_nav_menu_items` integration |
| Hreflang + x-default | ✅ done | `Frontend/Hreflang.php` |
| `<html lang>` + `dir` rewriting | ✅ done | fixed regex bug in v0.5.3 |
| Locale override for `.mo` selection | ✅ done | `Frontend/LocaleOverride.php`, v0.5.5 |
| REST API `?lang=` filter | ✅ done | `Rest/LangParam.php` |
| WPML compat shim (`icl_*`, `wpml_*`) | ✅ done | `src/Compat/WpmlFunctions.php` — 13 surfaces (v0.7.0) |
| Slug uniqueness scoped per language | ✅ done | both `wp_unique_post_slug` and `wp_unique_term_slug` |
| Backfill on activation | ✅ done | `Core/Backfill.php` + Activator |
| **Admin per-user UI language** | ❌ **missing** | No `user_meta` for admin locale; admin always follows site locale |
| **WP-CLI commands** | ✅ done | `src/Cli/` — 5 command groups (language, translate, strings, migrate, backfill), v0.7.1 |
| Activator opcache flush + BUILD_ID | ✅ done | per CodeOn convention |

## Bonus features shipped beyond v0.1.0

| Feature | Version | Notes |
|---|---|---|
| WPML data migration tool | v0.3.0 | 5-statement SQL importer, idempotent |
| Admin posts-list language quick-link bar | v0.3.1 | Two-row subsubsub trick, cookie-persisted |
| Admin "Languages" column with translate icons | v0.3.1 | Globe / pen / + per active language |
| Inline popup string editor | v0.3.2 → v0.5.2 | Vanilla JS, click-outside-saves |
| Per-string source-language detection + badge | v0.4.0 | Schema v2, character-range heuristic |
| File-system string scanner | v0.5.0 | Themes + plugins with master-toggle |
| Settings page with health-check indicator | v0.5.0 | + v0.6.0 `.l10n.php` health |
| Floating frontend switcher | v0.5.0 | 4-corner positioning |
| PUC auto-update | v0.1.0 | GitHub-release-driven |
| Schema upgrade path | v0.4.0 | `Activator::maybe_upgrade` runs on `admin_init` |
| CI matrix (PHP 8.1/8.2/8.3) | v0.1.0 | + tag-version sanity check on release |
| 22 unit tests | v0.5.3 | SubdirectoryStrategy + HtmlLangAttribute + StringTranslator hash |
| WPML compat shim (13 API surfaces) | v0.7.0 | Astra/Elementor/WoodMart/YITH now consume our data through WPML's public API |
| 30 unit tests | v0.7.0 | + WpmlElementTypeTest (8 tests covering all 4 element-type conventions) |

## What's missing — ranked by impact for production sites

### 1. Menu translation flow ❌ (next priority)

**Why it matters:** Admins expect explicit "translate this menu" actions, the same way WPML does. Currently menus inherit via post/term plumbing but there's no visible admin surface, so editing translated menus is awkward.

**Scope:** ~300 LOC, new module `Content/MenuTranslator.php` + admin integration on `nav-menus.php`.

### 2. WC curated string registration ❌

**Why it matters:** Auto-discovery captures these strings only when traffic hits the right code paths. Curated registration guarantees they're in the catalog from day one.

**Scope:** ~150 LOC, single file `Woo/KnownStringsBootstrap.php` that registers known WC strings on activation.

**Categories:**
- Shipping zone titles
- Payment method titles + descriptions
- Email subjects + headings
- Cart/checkout notices and labels

### 3. Language switcher — nav menu item ❌

**Why it matters:** Themes that don't render the floating switcher have no in-menu language option. Common WPML pattern — admin picks "Languages" under Appearance → Menus and gets per-language items.

**Scope:** ~200 LOC. Register a virtual menu item type via `customize_register` + filter `wp_nav_menu_items`.

### 4. Polylang compat shim ❌

**Why it matters:** Some plugins are written against Polylang's `pll_*` API instead of WPML's. Implementing this widens our migration funnel.

**Scope:** ~300 LOC, single file `Compat/PolylangFunctions.php`.

### 5. Language switcher — Gutenberg block ❌

**Why it matters:** Modern editors expect a block. Right now the legacy-widget block covers it but feels dated.

**Scope:** ~200 LOC PHP + a small block.json registration. Server-rendered so no JS bundle.

### 6. Admin per-user UI language ❌

**Why it matters:** Small but expected. Each admin user picks their own locale for the wp-admin interface.

**Scope:** ~80 LOC. `user_meta cml_admin_locale` + a profile page field + the `locale` filter respecting it in admin.

## Forward versioning plan

| Version | Theme | Headline features | Status |
|---|---|---|---|
| ~~v0.7.0~~ | Compatibility | WPML compat shim (`icl_*` + `wpml_*` filters), foundational adoption move | ✅ shipped |
| ~~v0.7.1~~ | Ops | WP-CLI command surface (`wp cml language / translate / strings / migrate / backfill`) | ✅ shipped |
| **v0.7.2** | UX gap closure | Menu translation flow, language switcher nav menu item, Gutenberg block | next |
| **v0.8.0** | WC depth | WC curated string registration, attribute-term slug mapping for variations, shipping/payment/email strings | planned |
| **v0.8.1** | Polylang | Polylang compat shim + import path | planned |
| **v0.9.0** | Integration tests | wp-phpunit test scaffold + MySQL CI service; cover DB-bound paths | planned |
| **v0.9.1** | Polish | Admin per-user UI language, Gutenberg block, performance instrumentation page | planned |
| **v1.0.0** | Production-grade | Battle-tested on 3+ live sites, full migration path from WPML in one click | planned |

## Out of scope (deliberately)

| Feature | Why we're not building it |
|---|---|
| Translation management workflow (drafts → review → published per language) | WPML's biggest source of bloat. Most sites don't use it. |
| Translation memory / glossaries | Out of scope for a lightweight tool. |
| Professional translation service integrations (ICanLocalize, etc.) | Niche. Plugin authors can build on top. |
| Machine translation (Google / DeepL / Microsoft) | Same — too opinionated for the core. |
| Multi-currency | Separate concern. Would be a sister-plugin if/when needed. |
| Advanced Translation Editor (WPML side-by-side rich-text editor) | The native WP editor with our meta-box switcher is sufficient. |
| Page builder JSON walker | Themes that store layouts as JSON blobs can be translated by re-editing in the builder UI. Walker per builder = maintenance treadmill. |
| String packages (Yoast/ACF/Elementor blob translation) | Too plugin-specific. May ship as separate per-plugin integrations later. |

## Out of MVP but tracked

- **Multi-currency add-on** — separate plugin, future
- **Subdomain routing** (`en.site.com`) — v0.4+ planned
- **Per-domain routing** (`site.com` / `site.de`) — v0.4+ planned
- **Translation status workflow** — opt-in v0.4+
- **REST API for admin operations** (CRUD languages, run scans, etc.) — covered by WP-CLI for now; REST equivalents in v0.9+

## How to suggest a change

This file is opinionated but not closed. To suggest a change to the roadmap:
- Open an issue with the use case and a rough impact estimate
- Or open a PR moving an item between sections with a one-paragraph justification

Most updates to this file happen alongside the release commit that ships the corresponding row.
