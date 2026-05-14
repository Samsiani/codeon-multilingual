# Changelog

All notable changes to CodeOn Multilingual are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) loosely; semantic versioning applies.

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
