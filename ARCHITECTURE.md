# Architecture — CodeOn Multilingual v0.7.22

This document describes how the plugin is structured, why each design choice was made, and how data flows through the system at request time.

## Top-level layout

```
src/
├── Core/                — Bootstrap, settings, languages, translation groups, schema, activation, bundled catalog
├── Url/                 — Routing strategy interface + Subdirectory impl + post/term link filters
├── Query/               — posts_clauses + terms_clauses JOIN injection + pagename → page_id rewriter
├── Content/             — Post + term duplicate engines
├── Strings/             — Gettext interceptor, per-language compiled cache, scanner, .l10n.php writer, PO/JSON exporter+importer
├── Woo/                 — WC product sync, variation auto-translation, translation field-lock UI, shop-page mapping, cart-item translation
├── Frontend/            — Switcher (list/dropdown/flags), hreflang, html lang, locale override, sitemap, floating switcher
├── Rest/                — REST language detection + strings API
├── Migration/           — WPML importer (icl_* tables → ours)
├── Compat/              — WPML compatibility shim (icl_* functions + wpml_* filters)
├── Cli/                 — WP-CLI command classes (language, translate, strings, migrate, backfill)
└── Admin/               — Top-level menu, admin bar, posts-list integration, page renderers, setup wizard, redirector

res/
├── languages.json       — bundled catalog (66 entries) used by the setup wizard
└── flags/               — 60 SVG flags (lipis/flag-icons, MIT) used by switchers + admin Languages list + setup wizard

assets/
├── admin.css / admin.js                 — admin styles + posts-list filter JS
├── strings-admin.css / .js              — inline strings editor (popup)
├── floating-switcher.css                — frontend switcher styles (incl. custom dropdown)
└── dropdown-switcher.js                 — ~1.4 KB toggle + measure-and-pin width JS
```

59 source files. Every module exposes a static `register()` that wires its hooks. `Core\Plugin::boot()` is the single source of truth for what's loaded — calling all `register()` methods in dependency order. CLI commands are registered separately via `Cli\CommandLoader::register()` from the main plugin file, guarded by `defined('WP_CLI') && WP_CLI` so command classes never load on a normal web request.

## Bootstrap and hook timing

```
WP loads our plugin file
  ↓
codeon-multilingual.php defines constants + requires autoloader
  ↓
\Samsiani\CodeonMultilingual\Core\Plugin::instance()->boot()
  │
  ├─ Router::register()         → add_action('plugins_loaded', on_request, priority 1)
  │                              → add_filter('home_url', filter_url)
  │                              → add_filter('day_link', filter_url) etc.
  │
  ├─ PostsClauses::register()   → add_filter('posts_clauses', filter_clauses, 10, 2)
  ├─ TermsClauses::register()   → add_filter('terms_clauses', filter_clauses, 10, 3)
  ├─ PostTranslator::register() → add_meta_boxes, save_post, wp_unique_post_slug, admin_post_*
  ├─ TermTranslator::register() → init p99 → per-tax hooks, wp_unique_term_slug, admin_post_*
  ├─ ProductSync::register()    → woocommerce_product_object_updated_props
  ├─ StringTranslator::register() → gettext + gettext_with_context filters
  ├─ L10nFileWriter::register() → load_textdomain p99 (only if setting enabled)
  ├─ LocaleOverride::register() → determine_locale + locale filters
  ├─ Hreflang::register()       → wp_head p1
  ├─ HtmlLangAttribute::register() → language_attributes
  ├─ LangParam::register()      → rest_pre_dispatch + rest_*_collection_params
  └─ admin_init                 → Activator::maybe_upgrade (schema bump path)
```

### Request lifecycle (frontend)

```
1. PHP-FPM starts handling the request
2. wp-settings.php loads plugins → our boot() runs at file-load time, hooks registered
3. WP fires 'plugins_loaded' priority 1
   ├─ Router::on_request() runs
   │   ├─ Skip if admin/ajax/cron/REST
   │   ├─ Detect language prefix in REQUEST_URI via SubdirectoryStrategy
   │   ├─ If found and not default lang:
   │   │   ├─ CurrentLanguage::set('en')
   │   │   ├─ Strip /en/ from $_SERVER['REQUEST_URI']
   │   │   └─ add_filter('redirect_canonical', '__return_false')  ← prevents loop
   │   └─ Otherwise: CurrentLanguage stays at default (no filter overhead)
   │
4. WP fires 'init', 'parse_request', 'wp', 'template_redirect'
   └─ determine_locale() is called for each textdomain load
       └─ LocaleOverride::filter() returns the current language's locale
       └─ WP loads the matching .mo (or our .l10n.php if enabled)
5. WP_Query runs
   ├─ apply_filters('posts_clauses', ...) → PostsClauses::filter_clauses
   │   └─ Injects ONE LEFT JOIN on cml_post_language + WHERE language = ?
   └─ Returns posts filtered by language
6. Template renders
   ├─ get_permalink($id) → apply_filters('post_link', ...) → PostLinkFilter
   │   └─ Returns URL prefixed with the POST's language (not request's)
   ├─ home_url() → Router::filter_url
   │   └─ Returns URL prefixed with CURRENT request's language
   ├─ language_attributes() → HtmlLangAttribute::filter → <html lang="en-US">
   ├─ wp_head() → Hreflang::render → <link rel="alternate" ...>
   └─ Templates call __() / _e() → WP_Translation_Controller
       └─ Returns the right translation per current locale
7. wp_footer
   └─ FloatingSwitcher::render → emits the floating switcher
```

## Design decisions (with reasoning)

### Storage model — duplicate posts + sibling tables

Each translation is its own `wp_posts` row, linked via a translation group ID in companion tables. Two tables: `cml_post_language` for posts, `cml_term_language` for terms.

| Alternative | Rejected because |
|---|---|
| Polymorphic single table (`wp_icl_translations` style) | `element_type LIKE 'post_%'` prevents index optimisation. Splitting by type lets us index by `(language, post_id)` directly. |
| qTranslate-style single post + serialized translations | Breaks WP_Query, search, REST, page builders. |
| Term-taxonomy-based (Polylang style) | Forces JOIN through `wp_term_relationships + wp_term_taxonomy + wp_terms` for every query just to know a post's language. |
| Companion table + post meta (`_cml_language`) | Either store twice (sync risk) or join postmeta with non-numeric value column (slow). Dedicated table wins. |

### Query injection — single LEFT JOIN, memoised SQL fragments

`PostsClauses::filter_clauses` injects exactly one LEFT JOIN + one WHERE clause. The SQL strings are built once per request (current language is stable for a request lifetime) and reused on every subsequent WP_Query.

```sql
-- Default language (with IS NULL fallback for legacy untagged content)
LEFT JOIN wp_cml_post_language cml_pl ON cml_pl.post_id = wp_posts.ID
WHERE … AND (cml_pl.language = 'en' OR cml_pl.language IS NULL)

-- Non-default language (strict)
WHERE … AND cml_pl.language = 'ka'
```

Skip rules:
- `$query->get('cml_skip_filter') === true` — explicit opt-out
- `p`, `page_id`, `attachment_id`, non-empty `post__in` — specific lookups bypass
- `is_admin() && ! $query->get('cml_admin_lang_filter')` — admin shows all by default unless the language dropdown is engaged

Per-WP_Query overhead: ~1–2 μs of PHP + a single SQL join the planner handles via the `(language, post_id)` index.

### Routing — virtual prefix, no rewrite rules

We mutate `$_SERVER['REQUEST_URI']` at `plugins_loaded` priority 1 — stripping `/en/` so WP routes the cleaned URL through its normal rewrite machinery. **No new rewrite rules to flush.** WP_Rewrite never knows the language prefix existed.

Trade-off: WP's canonical redirect (which compares URI vs `home_url()` output) sees a mismatch and 301s back to the prefixed form, causing a loop. We fix this by adding `add_filter('redirect_canonical', '__return_false')` whenever a prefix was stripped (since v0.5.4).

### Permalink filters — post's language vs current language

| Filter | Returns the language of… | Why |
|---|---|---|
| `home_url`, `day_link`, `month_link`, `year_link`, `feed_link`, `search_link` | Current request | These are context-free URLs |
| `post_link`, `page_link`, `post_type_link`, `attachment_link` | The post being linked | A Georgian post linked from an English page should still resolve to `/ka/…` |
| `term_link` | The term being linked | Same reasoning |

`PostLinkFilter` / `TermLinkFilter` strip whatever current-language prefix `home_url` injected, then add the post/term's own language prefix.

### Slug uniqueness — scoped per (post_type, post_parent, language)

Both `wp_unique_post_slug` and `wp_unique_term_slug` filters check the `cml_post_language` / `cml_term_language` table joined with WP's tables, only considering same-language rows when deciding uniqueness.

Net effect: `/en/about/` and `/ka/about/` both have slug `about` — no `-2` suffix.

### Translation groups — request-scoped memo, no persistent cache

`TranslationGroups` is two request-static maps: `(post_id) → group_id+lang` and `(group_id) → siblings`. Membership changes whenever a translation is created/deleted; that's the right invalidation boundary. Persistent caching would just add invalidation complexity for sub-millisecond gain.

Batch API (`preload`, `preload_terms`) collapses N-row admin list rendering into one query.

### String translations — two paths, default-safe

**Default path (every site):** `gettext` filter + hash-keyed compiled map in `wp_cache` + request-static.

```
Per __() call:
  1× md5(domain|context|source)  ~0.5 μs
  2× isset() lookups               ~0.2 μs
  Total: ~1–2 μs per call
```

Per request: one SELECT + array build the first time `gettext` fires for the current language (when no Redis), then served from static for the rest of the request.

**Opt-in fast path:** WP 6.5+ native `.l10n.php` files.

```
On translation save:
  1× DB read for the (domain, language) → build messages map
  1× atomic write (tmp file + rename)
  1× opcache_invalidate()

On request:
  load_textdomain action fires (when WP loads the .mo for a textdomain)
  Our hook calls load_textdomain($our_file, $locale)
  WP_Translation_Controller merges, our keys win
  __() lookup is ~0.1–0.3 μs via native path
```

The gettext+wp_cache filter stays active even when `.l10n.php` is enabled — silent no-op when the native path covered the translation, fallback when it didn't.

### Source language tracking

Schema v2 added `cml_strings.source_language`. Three writers:
- **Scanner** — detects from character ranges (Georgian/Cyrillic/Arabic/Hebrew/CJK/Japanese, else 'en')
- **gettext discovery** — same detector
- **WPML migration** — copies `icl_strings.language` directly (authoritative)

The admin strings list shows a coloured badge per row indicating each string's source language.

### WC product sync — single hook + group lock

```php
add_action('woocommerce_product_object_updated_props',
    fn($product, $updated_props) => sync_to_siblings_if_synced_prop_changed());
```

One hook handles admin saves, programmatic price updates, AND order-time stock decrement (because they all flow through `set_*` then `save()` on the WC_Product). Variations inherit the hook from WC_Product.

Recursion is guarded by an in-request lock keyed on `group_id`. Variations live in their own translation groups, so locking the product doesn't block its variations and vice versa.

Twenty synced props: `price`, `regular_price`, `sale_price`, `date_on_sale_from/to`, `stock_quantity`, `stock_status`, `manage_stock`, `backorders`, `sold_individually`, `low_stock_amount`, `weight/length/width/height`, `tax_class`, `tax_status`, `downloadable`, `virtual`, `sku`. Per-language fields (title, slug, description) intentionally NOT synced.

### Cache layers — request-scoped first, persistent second

| Layer | Stores | TTL | Purpose |
|---|---|---|---|
| Static class property | Compiled string map, language list, translation-group map | request | Eliminates re-query within a single render |
| `wp_cache_*` (object cache group `cml*`) | Same data | 12h / 6h | Persistent across requests when Redis/Memcached |
| Transient | `cml_backfill_*`, `cml_strings_flush_lock`, `cml_l10n_health` | varies | Cross-request flags |
| Disk file (`.l10n.php`) | Compiled translations per (domain, locale) | until next save | Native WP path, opcache-cached after first require |

## Database schema

Five tables, Schema v2.

```sql
wp_cml_languages
  code        VARCHAR(10) NOT NULL PRIMARY KEY
  locale      VARCHAR(20) NOT NULL
  name        VARCHAR(100) NOT NULL
  native      VARCHAR(100) NOT NULL
  flag        VARCHAR(50) NOT NULL DEFAULT ''
  rtl         TINYINT(1) NOT NULL DEFAULT 0
  active      TINYINT(1) NOT NULL DEFAULT 1
  is_default  TINYINT(1) NOT NULL DEFAULT 0
  position    INT NOT NULL DEFAULT 0
  KEY active_idx (active, position)

wp_cml_post_language
  post_id     BIGINT UNSIGNED NOT NULL PRIMARY KEY
  group_id    BIGINT UNSIGNED NOT NULL
  language    VARCHAR(10) NOT NULL
  KEY language_idx (language, post_id)
  KEY group_idx (group_id)

wp_cml_term_language
  term_id     BIGINT UNSIGNED NOT NULL PRIMARY KEY
  group_id    BIGINT UNSIGNED NOT NULL
  language    VARCHAR(10) NOT NULL
  KEY language_idx (language, term_id)
  KEY group_idx (group_id)

wp_cml_strings
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
  hash            BINARY(16) NOT NULL                       — md5(domain|context|source)
  domain          VARCHAR(190) NOT NULL
  context         VARCHAR(190) NOT NULL DEFAULT ''
  source          LONGTEXT NOT NULL
  source_language VARCHAR(10) NOT NULL DEFAULT 'en'
  created_at      INT UNSIGNED NOT NULL DEFAULT 0
  UNIQUE KEY hash_unique (hash)
  KEY domain_idx (domain)
  KEY source_language_idx (source_language)

wp_cml_string_translations
  string_id    BIGINT UNSIGNED NOT NULL
  language     VARCHAR(10) NOT NULL
  translation  LONGTEXT NOT NULL
  updated_at   INT UNSIGNED NOT NULL DEFAULT 0
  PRIMARY KEY (string_id, language)
  KEY language_idx (language)
```

Plus one autoloaded option `cml_settings`, and tracking options: `cml_db_version`, `cml_activated_at`, `cml_backfill_status`, `cml_backfill_last_id`, `cml_l10n_health`.

### Settings keys (`cml_settings`)

```php
[
    'frontend_switcher_enabled'   => true,
    'frontend_switcher_position'  => 'bottom-right',
    'frontend_switcher_style'     => 'dropdown',
    'frontend_switcher_show_flag' => true,
    'auto_discover_strings'       => false,
    'use_native_l10n_files'       => false,
]
```

## Module reference

| Module | File | Triggers |
|---|---|---|
| Plugin orchestrator | `Core/Plugin.php` | file-load time |
| Settings wrapper | `Core/Settings.php` | on-demand |
| Languages | `Core/Languages.php` | on-demand, cached |
| CurrentLanguage | `Core/CurrentLanguage.php` | request state |
| TranslationGroups | `Core/TranslationGroups.php` | on-demand, request-scoped |
| Activator | `Core/Activator.php` | activation hook + `admin_init` (schema upgrade) |
| Schema | `Core/Schema.php` | activation + uninstall |
| Backfill | `Core/Backfill.php` | activation + WP-Cron `cml_backfill_run` |
| Deactivator | `Core/Deactivator.php` | deactivation |
| BuildId | `Core/BuildId.php` | file load |
| Router | `Url/Router.php` | `plugins_loaded` p1, `home_url`, archive link filters |
| RoutingStrategy / SubdirectoryStrategy | `Url/*` | invoked by Router |
| PostLinkFilter | `Url/PostLinkFilter.php` | `post_link`, `page_link`, `post_type_link`, `attachment_link` — applies in admin too so the "Permalink:" preview on the edit screen reflects the post's own language |
| TermLinkFilter | `Url/TermLinkFilter.php` | `term_link` |
| PostsClauses | `Query/PostsClauses.php` | `posts_clauses` (language-filter every WP_Query) + `request` (rewrite `pagename=<slug>` to `page_id=<sibling>` so URL resolution doesn't pick the wrong-language sibling and trigger a 404; falls back to default-language sibling when no translation exists) |
| TermsClauses | `Query/TermsClauses.php` | `terms_clauses` |
| PostTranslator | `Content/PostTranslator.php` | `add_meta_boxes`, `save_post`, `wp_unique_post_slug`, `admin_post_cml_add_translation` |
| TermTranslator | `Content/TermTranslator.php` | `init` p99 (per-taxonomy hooks), `wp_unique_term_slug`, `admin_post_cml_add_term_translation` |
| ProductSync | `Woo/ProductSync.php` | `woocommerce_product_object_updated_props` |
| VariationTranslator | `Woo/VariationTranslator.php` | `cml_post_translation_created` |
| TranslationLock | `Woo/TranslationLock.php` | `add_meta_boxes_product` (p100, footer-injected JS); `wc_product_has_unique_sku` filter swallows duplicates within the same group |
| PageMapping | `Woo/PageMapping.php` | `option_woocommerce_*_page_id` filters — cart, checkout, my-account, shop, terms, pay, view-order |
| CartTranslation | `Woo/CartTranslation.php` | `woocommerce_cart_item_product`, `woocommerce_cart_item_permalink` |
| StringTranslator | `Strings/StringTranslator.php` | `gettext`, `gettext_with_context`, `shutdown` (flush discovery). Per-language compiled-map cache: `array<lang, array<hash, translation>>` so a request that switches languages mid-flight doesn't bleed one language's map into another |
| Scanner | `Strings/Scanner.php` | invoked by ScanPage |
| L10nFileWriter | `Strings/L10nFileWriter.php` | `load_textdomain` p99 (when setting enabled) |
| LanguageSwitcher | `Frontend/LanguageSwitcher.php` | `cml_language_switcher` shortcode, `widgets_init` |
| LanguageSwitcherWidget | `Frontend/LanguageSwitcherWidget.php` | classic widget API |
| FloatingSwitcher | `Frontend/FloatingSwitcher.php` | `wp_footer`, `wp_enqueue_scripts` |
| Hreflang | `Frontend/Hreflang.php` | `wp_head` p1 |
| HtmlLangAttribute | `Frontend/HtmlLangAttribute.php` | `language_attributes` |
| LocaleOverride | `Frontend/LocaleOverride.php` | `determine_locale`, `locale` |
| SitemapFilter | `Frontend/SitemapFilter.php` | WP core sitemap query args |
| LangParam | `Rest/LangParam.php` | `rest_pre_dispatch`, `rest_*_collection_params` |
| StringsApi | `Rest/StringsApi.php` | `rest_api_init` (registers `cml/v1/strings/<id>/translations`) |
| WpmlImporter | `Migration/WpmlImporter.php` | invoked by MigrationPage |
| WpmlFunctions | `Compat/WpmlFunctions.php` | `plugins_loaded` p5 (defers registration); registers ~9 filters + 2 actions and declares `icl_*` global functions via `wpml-functions-bootstrap.php` |
| AdminMenu | `Admin/AdminMenu.php` | `admin_menu` |
| AdminBar | `Admin/AdminBar.php` | `admin_bar_menu` |
| PostsListLanguage | `Admin/PostsListLanguage.php` | `views_edit-*`, `manage_*_posts_columns`, `pre_get_posts`, `the_posts`, `admin_init` (cookie) |
| LanguagesPage | `Admin/Pages/LanguagesPage.php` | `admin_post_*` for CRUD |
| StringsPage | `Admin/Pages/StringsPage.php` | `admin_post_cml_purge_strings`, `admin_enqueue_scripts` |
| ScanPage | `Admin/Pages/ScanPage.php` | `admin_post_cml_run_scan` |
| SettingsPage | `Admin/Pages/SettingsPage.php` | `admin_post_cml_save_settings` |
| MigrationPage | `Admin/Pages/MigrationPage.php` | `admin_post_cml_run_wpml_import` |
| SetupWizard | `Admin/Pages/SetupWizard.php` | hidden submenu `admin.php?page=cml-setup`, `admin_post_cml_setup_step/skip/finish` |
| SetupRedirector | `Admin/SetupRedirector.php` | `admin_init` p1 — one-shot redirect to wizard after first activation. Also defensively hooks `admin_post` / `admin_post_nopriv` so stale `admin-post.php?page=cml-setup` URLs 302 to the canonical `admin.php?...` instead of white-screening |
| LanguageCatalog | `Core/LanguageCatalog.php` | invoked by SetupWizard + admin Add-Language form auto-fill; reads `res/languages.json` and resolves bundled SVG flag URLs in `res/flags/<code>.svg` |
| PoExporter | `Strings/PoExporter.php` | pure render (PO + JSON), consumed by `StringsCommand::export` |
| PoImporter | `Strings/PoImporter.php` | pure parse (PO + JSON), consumed by `StringsCommand::import` |
| CommandLoader | `Cli/CommandLoader.php` | invoked at plugin-file load time, guarded by `defined('WP_CLI') && WP_CLI` |
| LanguageCommand | `Cli/LanguageCommand.php` | `wp cml language ...` |
| TranslateCommand | `Cli/TranslateCommand.php` | `wp cml translate post|term ...` |
| StringsCommand | `Cli/StringsCommand.php` | `wp cml strings scan|export|import|count` |
| MigrateCommand | `Cli/MigrateCommand.php` | `wp cml migrate wpml` |
| BackfillCommand | `Cli/BackfillCommand.php` | `wp cml backfill run|status|reset` |

### CLI design notes

WP-CLI command classes live in their own `src/Cli/` directory and are only loaded when `WP_CLI` is defined. The classes are thin wrappers over the existing services — `Languages::create / update_active / set_default / delete` on the read service for language CRUD, `PostTranslator::duplicate` / `TermTranslator::duplicate` for content translation, `Scanner::scan_*` for catalog discovery, `WpmlImporter::import_all` for migration, and `Backfill::run_batch_now()` (a new synchronous wrapper around the existing private batch logic) for cron-independent backfills.

The string export/import path is built on two pure helpers (`PoExporter` / `PoImporter`) so a `wp cml strings export | wp cml strings import -` round-trip is byte-stable. The PO output carries a non-standard `# Domain: <name>` comment per entry so multi-domain catalogs survive without one-file-per-domain bookkeeping; standard PO tooling ignores the comment. Plural forms (`msgid_plural` / `msgstr[n]`) are intentionally skipped during import — our string table doesn't model plurals; WP core handles those natively via `.mo`.

## Public extension hooks

| Hook | Type | Args | Purpose |
|---|---|---|---|
| `cml_post_translation_created` | action | `$new_id, $source_id, $target_lang, $group_id` | Fires after a post translation is created (consumed by VariationTranslator) |
| `cml_term_translation_created` | action | `$new_id, $source_id, $taxonomy, $target_lang, $group_id` | Fires after a term translation is created |
| `cml_translatable_post_types` | filter | `array $types` | Override which post types are translatable |
| `cml_translatable_taxonomies` | filter | `array $taxonomies` | Override which taxonomies are translatable |
| `cml_woo_synced_props` | filter | `array $props` | Add/remove props from the WC sync list |

## REST API surface

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `/wp-json/wp/v2/{post_type}?lang=xx` | GET | public | Filter post collections by language |
| `/wp-json/wp/v2/{taxonomy}?lang=xx` | GET | public | Filter term collections by language |
| `/wp-json/cml/v1/strings/<id>/translations` | POST | `manage_options` | Save one translation; auto-rewrites the `.l10n.php` file if opt-in path is on |

## Performance budget (steady state)

| Site size | Extra queries / page | Per-request overhead | Memory delta |
|---|---|---|---|
| Small (default lang, no Redis) | 1 LEFT JOIN | <2 ms | <500 KB |
| Medium WC (non-default lang, no Redis) | 1 LEFT JOIN + 1 compiled map rebuild | ~10–15 ms | ~1 MB |
| Medium WC (non-default lang + Redis) | 1 LEFT JOIN + 1 wp_cache_get | ~5 ms | ~1 MB |
| Large multi-vendor (`.l10n.php` enabled) | 1 LEFT JOIN | <5 ms | ~1.5 MB |
