# Changelog

All notable changes to CodeOn Multilingual are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) loosely; semantic versioning applies.

## [0.7.6] — 2026-05-14

### Added
- **Flag column on the admin Languages list.** Each row now shows the language's bundled SVG flag (or emoji fallback / em-dash when neither is available) so the table matches the wizard's visual style.

## [0.7.5] — 2026-05-14

### Added
- **Bundled SVG flag library** under `res/flags/` — 60 country flags (4×3 aspect ratio) sourced from [lipis/flag-icons](https://github.com/lipis/flag-icons) (MIT, license bundled at `res/flags/LICENSE`). Total payload ~560 KB; loaded lazily via `<img loading="lazy">` so they don't block page renders.
- **`LanguageCatalog::flag_svg_url()` / `flag_svg_path()` / `flag_svg_exists()`** — resolve a country code to the bundled SVG asset, returning `null` for unbundled codes so callers fall back to the existing emoji renderer gracefully.
- **Auto-fill in the admin "Add Language" form** — typing a code (e.g. `ka`) auto-populates locale, English name, native name, flag, and RTL from the catalog. Admin can still edit any field; once edited, the field is marked manual and the auto-fill stops overwriting it.

### Changed
- **Frontend switcher** (`LanguageSwitcher`, floating + shortcode + widget) now renders flags as bundled `<img>` SVGs by default, with emoji fallback when no SVG ships for the code. Fixes the long-standing Windows desktop rendering issue (Microsoft doesn't ship flag emoji glyphs, so users saw letter pairs).
- **Setup wizard catalog grid** uses SVG flags too.

### Tests
- 6 new tests in `LanguageCatalogTest` covering SVG URL resolution, case-insensitivity, invalid input, file readability, and a contract check that every catalog entry with a flag code ships a matching SVG. Total: 55 → 61 passing.

### Notes
- Plugin zip grows by ~250 KB compressed (SVG payload) — still in the lightweight pitch range.
- Emoji rendering path is preserved as a fallback, so older / very stripped-down installs that delete `res/flags/` keep working.

## [0.7.4] — 2026-05-14

### Fixed
- **Defensive rescue for `admin-post.php?page=cml-setup` URLs.** The v0.7.2 bug pointed the welcome-step form at `admin-post.php`; v0.7.3 fixed the form, but bookmarks and browser-history entries can still hit the bad URL and white-screen (admin-post.php dies silently when no `action` is set). `SetupRedirector::rescue_admin_post_url` now hooks `admin_post` + `admin_post_nopriv`, recognises `page=cml-setup`, and 302-redirects to the canonical `admin.php?page=cml-setup&step=<n>` URL so the wizard always opens instead of going blank.

## [0.7.3] — 2026-05-14

### Fixed
- **Setup wizard step 1 → step 2 white screen.** The "Let's go" button on the welcome screen was wrapped in a `<form action="admin-post.php" method="get">` with no `action` field, so submitting it navigated the browser to `wp-admin/admin-post.php?page=cml-setup&step=2`. `admin-post.php` only routes registered `admin_post_<action>` hooks and dies silently when none match, so the user landed on a blank page. Replaced the form with a plain anchor pointing at `admin.php?page=cml-setup&step=2` (the wizard URL builder we already use everywhere else).

## [0.7.2] — 2026-05-14

### Added
- **First-run setup wizard** at `admin.php?page=cml-setup` — 4-step guide mirroring WPML's onboarding flow:
  1. Welcome screen with detected site locale.
  2. Default-language picker with search + 66-entry catalog (major languages grouped first).
  3. Multi-select for additional active languages.
  4. Summary + deep links to Languages, Settings, and Migration pages.
- **Auto-redirect on first activation** — `Admin\SetupRedirector` arms a 30-second transient on activate, then `admin_init` p1 redirects an admin with `manage_options` to the wizard. Guarded against AJAX/CLI/cron/network admin. Subsequent activations skip the redirect once `cml_settings['setup_complete']` is true.
- **"Run setup wizard" page-title action** on the Languages screen so admins can re-run the wizard after dismissing it.
- **Bundled language catalog** `res/languages.json` (66 entries, ~9 KB) sourced from WPML's curated list. Each entry: `code`, `name`, `native`, `locale`, `flag` (ISO 3166-1 alpha-2 for emoji rendering), `rtl`, `major`. Loaded once per request and cached.
- **`Core\LanguageCatalog` service** — `all() / get() / search() / major() / by_locale() / flag_emoji()`. Search is case-insensitive and ranks major-flagged languages first to keep common picks at the top.
- **`Settings` keys**: `setup_complete`, `setup_skipped`, `setup_step` (defaults all falsy so existing installs are not affected).

### Tests
- 12 new unit tests in `LanguageCatalogTest` covering catalog shape, exact lookup, native-script search, case-insensitivity, major-first ranking, locale prefix fallback (`en_GB` → `en`), flag-emoji codepoint composition, and RTL flagging. Total: 43 → 55 passing.

### Notes
- No image assets bundled. Flags render as Unicode regional-indicator emoji pairs, in keeping with the plugin's lightweight pitch.

## [0.7.1] — 2026-05-14

### Added
- **WP-CLI command surface** — 5 new commands under `src/Cli/` (`LanguageCommand`, `TranslateCommand`, `StringsCommand`, `MigrateCommand`, `BackfillCommand`) registered through `Cli/CommandLoader`. Hidden behind a `defined('WP_CLI') && WP_CLI` guard so command classes never load on a normal web request.
  - `wp cml language list / create / activate / deactivate / set-default / delete` — full CRUD against `wp_cml_languages`, with cache flush, default-row enforcement, and code-regex validation.
  - `wp cml translate post <id> --to=<lang>` / `wp cml translate term <id> --tax=<tax> --to=<lang>` — thin wrappers over the existing `PostTranslator::duplicate` / `TermTranslator::duplicate` services. Idempotent.
  - `wp cml strings scan [--theme=<slug>] [--plugin=<slug>] [--all]` — drives `Scanner::scan_theme` / `scan_plugin`. `--all` walks active theme + every active plugin in one pass.
  - `wp cml strings export --lang=<code> [--format=po|json] [--domain=<d>] [--output=<file>]` — PO (default) or JSON, multi-domain via `# Domain:` comments, untranslated rows opt-in.
  - `wp cml strings import <file> [--format=auto|po|json] [--lang=<code>] [--regenerate-l10n]` — auto-detects format by extension, accepts STDIN (`-`), inserts missing source strings on the fly.
  - `wp cml strings count` — catalog summary by language.
  - `wp cml migrate wpml [--dry-run]` — non-interactive wrapper for `WpmlImporter::import_all` with a dry-run summary.
  - `wp cml backfill run [--all]` / `status` / `reset` — synchronous backfill loop independent of wp-cron.
- **`PoExporter` / `PoImporter`** — pure PO + JSON serializer/parser pair under `src/Strings/`. Lossless round-trip across multi-domain catalogs, embedded quotes and newlines, msgctxt, and untranslated entries (verified by tests).
- **`Languages::create() / update_active() / set_default() / delete()`** — write-side mutators on the existing read-service so CLI doesn't duplicate the admin-page logic.
- **`Backfill::run_batch_now()`** — synchronous batch wrapper for CLI; the wp-cron path is unchanged.

### Tests
- 13 new unit tests: 8 in `PoRoundTripTest` (PO/JSON round-trips, msgctxt, quote/newline preservation, plural skipping with warning, header Language detection, untranslated entries), 5 in `CliHelpersTest` (row shaping, format auto-detection). Total: 30 → 43 passing.
- New WP-CLI class stubs in `tests/bootstrap.php` and `tests/phpstan-stubs.php` so unit tests and static analysis resolve `WP_CLI` / `WP_CLI_Command` / `WP_CLI\Utils\format_items` without the live CLI.

### Notes
- Roadmap originally specified `wp cml language add`; shipped command is `create` to follow `wp post create` / `wp term create` / `wp user create` conventions. Roadmap row updated.

## [0.7.0] — 2026-05-14

### Added
- **WPML compatibility shim** — `src/Compat/WpmlFunctions.php` + `src/Compat/wpml-functions-bootstrap.php`. Implements 13 API surfaces that real-world themes/plugins (Astra, GeneratePress, WoodMart, Elementor, YITH, Woo extensions) consume:
  - Global functions: `icl_object_id`, `icl_get_languages`, `icl_get_default_language`, `icl_get_current_language`
  - Filters: `wpml_object_id`, `wpml_current_language`, `wpml_default_language`, `wpml_active_languages` (full WPML keyed-by-code array shape with 11 fields per language), `wpml_post_language_details`, `wpml_element_has_translations`, `wpml_translate_single_string`
  - Actions: `wpml_register_single_string` (inserts into our `cml_strings`), `wpml_switch_language`
- **Settings toggle** `wpml_compat_enabled` (default `true`) with admin UI under Settings → Compatibility.
- **Self-disabling guard**: every global function declaration goes through `function_exists()` so a real WPML install (defensive — they shouldn't coexist) wins the namespace and our shim becomes a no-op.
- **Element-type detection** for `icl_object_id` covers all four WPML conventions: literal core slugs (`post`/`page`/`category`/`post_tag`), `post_*` / `tax_*` prefix conventions, registry lookup (`taxonomy_exists` / `post_type_exists`), and a sensible default.

### Fixed
- **`post_tag` mis-classified as a post** — initial implementation tested the `post_*` prefix branch before the explicit core-taxonomies list, so `'post_tag'` matched the prefix branch first. Caught by the new `WpmlElementTypeTest`. Reordered checks: literal lists first, prefix conventions second.

### Tests
- 8 new unit tests in `tests/Unit/WpmlElementTypeTest.php` covering all four element-type conventions. Total: 22 → 30 passing tests.

### Performance
- Filter registration cost: ~10 μs total at `plugins_loaded` p5. Strict zero per-request thereafter when no WPML-aware code calls the filters.

## [0.6.0] — 2026-05-14

### Added
- **Opt-in WP 6.5+ native `.l10n.php` translation path.** New `Strings/L10nFileWriter.php` generates per-(domain, locale) files under `wp-content/uploads/cml-translations/`, hooks `load_textdomain` priority 99 so WP_Translation_Controller merges our overrides on the native path. Atomic tmp+rename with `opcache_invalidate`. Default is **off** — settings toggle enables it.
- **`Settings::defaults['use_native_l10n_files']`** added (false by default).
- **Settings page section** "Performance — native .l10n.php files" with writability indicator, regenerate-now button, and toggle.
- **Auto-regenerate** on every translation save in `StringsApi` (REST endpoint).
- **Bulk regenerate** after WPML migration in `WpmlImporter`.
- **Graceful fallback**: if uploads dir is not writable or a write fails, records `cml_l10n_health` option and silently falls back to the v0.5.5 wp_cache path.
- **Uninstall cleanup**: `L10nFileWriter::delete_all` purges generated files when the plugin is removed.

## [0.5.5] — 2026-05-13

### Fixed
- **`Frontend/LocaleOverride.php`** new — hooks `determine_locale` + `locale` filters so visiting `/en/` on a Georgian-default site loads `en_US.mo` instead of `ka_GE.mo`. Without this, the `<html lang>` reported `en-US` but page content still rendered Georgian translations. Admin keeps WP's own locale.

## [0.5.4] — 2026-05-13

### Fixed
- **Infinite canonical-redirect loop on `/lang/` URLs.** When `Router::on_request` stripped `/en/` from `REQUEST_URI`, WP's `redirect_canonical` compared the stripped URI (`/`) against the URL built from our `home_url` filter (which prepends `/en/` back), saw a mismatch, and 301'd back to `/en/` — an infinite loop. Router now adds `add_filter('redirect_canonical', '__return_false')` whenever it strips a prefix.

## [0.5.3] — 2026-05-13

### Fixed
- **`<html lang>` regex.** `HtmlLangAttribute::filter` required a whitespace char before `lang=`, but WP's `language_attributes()` output typically starts with `lang="…"` (no leading whitespace). The regex never matched, so the filter was a silent no-op. Changed `\s` to `\b` (word boundary).
- Same fix applied to the `dir="rtl"` branch.

### Added
- 5 new unit tests in `tests/Unit/HtmlLangAttributeTest.php` covering the regex regression. Suite now at 22 tests.

## [0.5.2] — 2026-05-13

### Fixed
- **`StringsApi` rejected default-language translations.** A leftover check from v0.1 when sources were assumed default ("Cannot translate into the default language") blocked legitimate saves — a string registered in English on a Georgian-default site genuinely needs a Georgian translation. Check removed.
- **Strings popup clipped on the right.** Popup is now appended to `document.body` on open (out of `.wrap`/table containers), positioning switched from `position:absolute` to `position:fixed` (viewport-relative, no scroll math), right-edge clamping reliably keeps it visible.

## [0.5.1] — 2026-05-13

### Changed
- **WPML-style strings table.** Column order is now Domain | Progress | Source | Lang | (one column per active language). The Lang column holds the source-language badge as its own column. Per-language column shows globe (source), pen (translated), or + (missing).
- **Inline popup editor** replaces the inline placeholder row. Three columns: source textarea (readonly, grey) | copy button | translation textarea. Click outside autosaves; Esc cancels; Shift+Enter newlines; Ctrl/⌘+Enter saves explicitly.
- Light zebra (#fff / #fafbfc) with subtle hover (#f0f6fc).
- Copy button uses `dashicons-arrow-right-alt` to signal direction.

## [0.5.0] — 2026-05-13

### Added
- **Frontend floating switcher** — `Frontend/FloatingSwitcher.php` auto-injects a switcher on every public page. 4 positions (bottom-right default), 3 styles (list/dropdown/flags), flag toggle. Hidden when only 1 language is active.
- **Scan-based string discovery** — `Strings/Scanner.php` walks PHP files recursively and matches `__`, `_e`, `_x`, `_ex`, `esc_html__`, `esc_html_e`, `esc_attr__`, `esc_attr_e`, `esc_html_x`, `esc_attr_x`, `translate`. Per-string source-language detected on insert.
- **`Admin/Pages/ScanPage.php`** — WPML-style checkbox UI of themes + plugins with active ones pre-checked. Submit runs `Scanner::scan_theme` / `scan_plugin` synchronously and reports new-string count + elapsed ms.
- **`Admin/Pages/SettingsPage.php`** — frontend switcher options + auto-discovery toggle.

### Changed
- **`StringTranslator::discover` is off by default** (Settings `auto_discover_strings = false`). Catalog now grows from the Scan page, not from runtime gettext interception. Reduces hot-path overhead on busy sites.

## [0.4.0] — 2026-05-13

### Added
- **Schema v2** — `wp_cml_strings.source_language VARCHAR(10) DEFAULT 'en'` column with `KEY source_language_idx`.
- **`Activator::maybe_upgrade`** — new entry point hooked on `admin_init`. Runs `Schema::install()` (dbDelta adds the column non-destructively) when stored `cml_db_version` is behind `Schema::VERSION`. Auto-update via wp-admin bypasses activation hooks, so we can't rely on `Activator::activate`.
- **`StringTranslator::detect_source_language`** — character-range heuristic (Georgian / Cyrillic / Arabic / Hebrew / CJK / Japanese ranges, else 'en').
- **Backfill** of existing strings via detection on schema upgrade.
- **WPML migration** now uses authoritative `icl_strings.language` via `ON DUPLICATE KEY UPDATE`.

### Changed
- **Strings admin page** shows colour-coded source-language badge per row.

## [0.3.3] — 2026-05-13

### Added
- **Admin posts-list quick-link bar** — replaces the dropdown with a second `.subsubsub` row directly under WP's stock "All | Published | Drafts". Native name + count per language plus "All languages". Cookie-persisted (30-day) language selection.

### Changed
- HTML trick to render a second `<ul class="subsubsub">` via a single synthetic view in the `views_edit-<post_type>` filter — no JS, no extra HTTP.

## [0.3.2] — 2026-05-13

### Added
- **`StringsApi` REST endpoint** — `POST /cml/v1/strings/<id>/translations` for the inline editor.
- **`assets/strings-admin.js`** — vanilla JS inline editor. Click + or pen → opens an inline editor row below. Ctrl/⌘+Enter saves, Esc cancels. Save uses fetch + REST nonce; on success swaps the icon and updates progress.
- **`assets/strings-admin.css`** — styling.

### Changed
- **Strings admin page rebuilt.** Search + domain filter + status filter (untranslated / partial / fully translated) all in one form. Per-language columns with + / pen icons.

### Removed
- Full-page edit screen (inline editor replaces it).

## [0.3.1] — 2026-05-13

### Added
- **`Admin/PostsListLanguage.php`** — filter dropdown above edit.php for every translatable post type. Adds a "Languages" column after Title with badge for own language + ✓ for translated siblings + + for missing.
- Batch preload via `the_posts` filter — typical 20-row admin page now adds ~3 queries total, not 40.

### Changed
- `Query/PostsClauses::should_skip` opts back in when admin filter dropdown is engaged (`cml_admin_lang_filter` query var).

## [0.3.0] — 2026-05-13

### Added
- **WPML migration tool** — `Migration/WpmlImporter.php` and `Admin/Pages/MigrationPage.php`. Five `INSERT … SELECT [ON DUPLICATE KEY UPDATE]` statements, database does the join + dedupe, no PHP iteration. Idempotent.
- Maps `icl_translations.trid` directly to our `group_id`; copies WPML's authoritative `language` for source strings.

## [0.2.0] — 2026-05-13

### Added
- **Frontend module** — `Frontend/`:
  - `LanguageSwitcher` (`[cml_language_switcher]` shortcode with list/dropdown/flags styles)
  - `LanguageSwitcherWidget` (classic WP widget)
  - `Hreflang` (wp_head emits per-language tags + x-default)
  - `HtmlLangAttribute` (filters `language_attributes`)
  - `SitemapFilter` (passes `cml_skip_filter` to WP core sitemap queries)

## [0.1.0] — 2026-05-13

### Added
- Initial release. MVP foundation:
  - **Schema** — 5 tables (`cml_languages`, `cml_post_language`, `cml_term_language`, `cml_strings`, `cml_string_translations`)
  - **Routing** — `SubdirectoryStrategy` (`/ka/`, `/en/`) with virtual prefix
  - **Query injection** — single `LEFT JOIN` on every `WP_Query` and `WP_Term_Query`, memoised SQL fragments
  - **Post/Page/CPT translation** — `Content/PostTranslator.php` with deep `post_meta` clone, hierarchical parent mapping, slug-by-language scoping
  - **Attachment translation** — included in `translatable_post_types()`
  - **Taxonomy term translation** — `Content/TermTranslator.php`
  - **WooCommerce product sync** — `Woo/ProductSync.php` fans 20 props across translation group, group-keyed recursion lock
  - **Variation auto-clone** — `Woo/VariationTranslator.php` listens for `cml_post_translation_created`
  - **String translation** — `Strings/StringTranslator.php` via gettext interceptor + hash-keyed compiled cache
  - **REST `?lang=` parameter** — `Rest/LangParam.php` on every show_in_rest endpoint
  - **Background backfill** — `Core/Backfill.php` cron-driven for legacy posts (synchronous for terms)
  - **Admin pages** — Languages (CRUD), Strings (list + edit), Migration (detection only)
  - **Admin bar** — language switcher on frontend + admin
  - **Plugin Update Checker** — `yahnis-elsts/plugin-update-checker` wired to GitHub releases
  - **GitHub Actions** — tag-driven release workflow (version-gated, stamps BUILD_ID, builds + uploads ZIP) + CI with PHPUnit on PHP 8.1/8.2/8.3 matrix
  - **17 unit tests** — SubdirectoryStrategy + StringTranslator hash

## Release process

See [`DEVELOPMENT.md`](DEVELOPMENT.md#release-process).
