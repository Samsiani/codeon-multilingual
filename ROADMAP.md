# Roadmap & gap analysis

Current version: **v0.7.23** (released 2026-05-14)

This file is the single source of truth for "what's done vs what's planned." Every release updates the relevant rows.

## Where we are

The full v0.1.0 MVP scope is shipped, plus migration tooling, inline string editor, scan-based discovery, WP 6.5+ native `.l10n.php` translation path, the WPML compatibility shim, the WP-CLI command surface, the first-run setup wizard, the bundled SVG flag library, the per-language compiled-map cache, and the full WooCommerce translation stack (product field locking, shop-page mapping per language, cart items follow current language with source fallback). 62 passing unit tests, production-deployed on artcase.ge.

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
| WC stock/price/dimensions/tax sync | ✅ done | 22 synced props (incl. GTIN + shipping class), `Woo/ProductSync.php` |
| WC field-lock UI on translations | ✅ done | `Woo/TranslationLock.php`, v0.7.19 — WPML-style locked-field notices |
| WC shop-page mapping per language | ✅ done | `Woo/PageMapping.php`, v0.7.21 — cart/checkout/my-account on `/en/` resolve correctly |
| WC cart items follow current language | ✅ done | `Woo/CartTranslation.php`, v0.7.21 — items swap to current language, fall back to original |
| WC duplicate-SKU validator silenced for siblings | ✅ done | `Woo/TranslationLock::allow_sibling_sku_duplicate`, v0.7.19 |
| **WC shipping-zone strings (curated)** | ❌ **missing** | Captured only via auto-discovery when on |
| **WC payment-method titles (curated)** | ❌ **missing** | Same |
| **WC email subjects/bodies (curated)** | ❌ **missing** | Same |
| **WC cart/checkout strings (curated)** | ❌ **missing** | Same |
| Language switcher — shortcode | ✅ done | `[cml_language_switcher]` |
| Language switcher — classic widget | ✅ done | `Frontend/LanguageSwitcherWidget.php` |
| Language switcher — auto-floating | ✅ done | `Frontend/FloatingSwitcher.php`, v0.5.0 |
| Language switcher — Gutenberg block | ✅ done | `Frontend/SwitcherBlock.php`, v0.7.23 — server-rendered, ServerSideRender preview, style + show-flag/native/code attributes |
| Language switcher — nav menu item | ✅ done | `Frontend/NavMenuSwitcher.php`, v0.7.23 — one placeholder expands at render, per-item display + layout settings |
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
| WP-CLI command surface | v0.7.1 | 5 command groups: language, translate, strings, migrate, backfill |
| First-run setup wizard | v0.7.2 | 4-step guide + bundled 66-language catalog |
| Defensive rescue redirect for admin-post.php?page=cml-setup URLs | v0.7.4 | Stale bookmarks no longer white-screen |
| Bundled SVG flag library | v0.7.5 | 60 flags (~560 KB) from lipis/flag-icons (MIT) |
| Flags on admin Languages list + auto-fill on Add-Language form | v0.7.5–v0.7.6 | |
| Legacy flag-code backfill on schema upgrade | v0.7.7 | Rows seeded with language code (`ka`) get reconciled to country code (`ge`) |
| Custom dropdown switcher with SVG flags | v0.7.8 | Replaces native `<select>` (which can't render `<img>` children) |
| Dropdown UX polish | v0.7.9–v0.7.15 | Source removed from menu, JS-measured width, auto-open upward when bottom-anchored, menu == box width |
| Per-click width accumulation fix + CI hardening | v0.7.16 | scrollWidth measurement reset before each open; PHPStan memory bumped to 2 GB; `make_latest` on releases |
| Translations into the default language now apply | v0.7.17 | Removed leftover is_default() short-circuit |
| Per-language compiled-map cache | v0.7.18 | Each language gets its own request-static slot — no more cross-language bleed |
| Product field-lock UI on translations | v0.7.19 | WPML-style locked fields + banner; SKU dup-validator silenced for siblings |
| Admin permalink + admin-bar follow post's language | v0.7.20 | Source/translation labels distinguished; new translations always seed from group source |
| WooCommerce shop pages + cart items per language | v0.7.21–v0.7.22 | `/en/cart/` resolves to English cart, cart items swap per language, untranslated pages fall back to source |
| 62 unit tests | v0.7.18 | + LanguageCatalog, PoRoundTrip, CliHelpers, StringTranslator hash regression |

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

### 3. Language switcher — nav menu item ✅ shipped v0.7.23

Implemented in `Frontend/NavMenuSwitcher.php`. Side meta-box on Appearance → Menus + per-item display/layout settings; placeholder auto-expands at render so new languages appear in every menu without editing.

### 4. Polylang compat shim ❌

**Why it matters:** Some plugins are written against Polylang's `pll_*` API instead of WPML's. Implementing this widens our migration funnel.

**Scope:** ~300 LOC, single file `Compat/PolylangFunctions.php`.

### 5. Language switcher — Gutenberg block ✅ shipped v0.7.23

Implemented in `Frontend/SwitcherBlock.php`. Server-rendered (`render_callback`); editor uses ServerSideRender for live preview. Style + show-flag/native/code attributes; wide/full alignment + spacing controls.

### 6. Admin per-user UI language ❌

**Why it matters:** Small but expected. Each admin user picks their own locale for the wp-admin interface.

**Scope:** ~80 LOC. `user_meta cml_admin_locale` + a profile page field + the `locale` filter respecting it in admin.

## Forward versioning plan

| Version | Theme | Headline features | Status |
|---|---|---|---|
| ~~v0.7.0~~ | Compatibility | WPML compat shim (`icl_*` + `wpml_*` filters) | ✅ shipped |
| ~~v0.7.1~~ | Ops | WP-CLI command surface (`wp cml language / translate / strings / migrate / backfill`) | ✅ shipped |
| ~~v0.7.2~~ | Onboarding | First-run setup wizard + bundled 66-language catalog | ✅ shipped |
| ~~v0.7.3 – v0.7.4~~ | Wizard polish | Whitescreen fix + defensive rescue redirect for stale URLs | ✅ shipped |
| ~~v0.7.5 – v0.7.10~~ | Flag UX | Bundled SVG flags, flags on admin list, flag backfill, dropdown rebuild, bullet/sizing fixes | ✅ shipped |
| ~~v0.7.11 – v0.7.16~~ | Switcher polish | Menu placement, auto-width, click-accumulation fix, CI + release workflow hardening | ✅ shipped |
| ~~v0.7.17 – v0.7.18~~ | String cache correctness | Default-lang translations apply; per-language compiled-map cache | ✅ shipped |
| ~~v0.7.19 – v0.7.20~~ | Translation editing UX | WPML-style product field locks; admin permalink + admin-bar follow post's language | ✅ shipped |
| ~~v0.7.21 – v0.7.22~~ | WC translation stack | Shop-page mapping per language, cart items per language, source-fallback for untranslated pages | ✅ shipped |
| ~~v0.7.23~~ | UX gap closure (part 1) | Nav-menu language switcher + Gutenberg block, both with display + layout customisation | ✅ shipped |
| **v0.7.24** | UX gap closure (part 2) | Menu translation flow (dedicated admin action on nav-menus.php) | next |
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
