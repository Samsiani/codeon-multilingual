# CodeOn Multilingual

A lightweight multilingual plugin for WordPress and WooCommerce — WPML-compatible feature surface at a fraction of the weight.

## Status

v0.1.0 — feature-complete MVP. Live-tested on artcase.ge (WP 6.9, WC 10.7, PHP 8.3).

## Features

- Subdirectory URL routing (`/ka/`, `/en/`) with virtual prefix (no rewrite rules to flush)
- Post / page / CPT translation via duplicate-and-link
- Taxonomy term translation with slug-by-language scoping
- WooCommerce sync — price, stock, sale dates, dimensions, tax, SKU fanned across translations
- Variable products auto-duplicate variations on parent translation
- String translations via `gettext` interceptor with hash-keyed compiled cache
- REST API `?lang=` parameter on every show_in_rest endpoint
- WP-CLI ready (planned)
- Single LEFT JOIN per `WP_Query` / `WP_Term_Query` — request-memoized SQL

## Requirements

- PHP 8.1+
- WordPress 6.5+
- WooCommerce 8.0+ (optional — only required for product sync)

## Install (production)

Download the latest release ZIP from [Releases](https://github.com/Samsiani/codeon-multilingual/releases) and install via WP Admin → Plugins → Add New → Upload.

### Auto-update via GitHub

The repo is currently public — PUC fetches releases token-less. WP will surface a "new version available" prompt for each tagged release.

When the repo flips back to private (planned post-1.0), each WP install needs a fine-grained PAT in `wp-config.php`:

```php
define( 'CML_GITHUB_TOKEN', 'github_pat_…' );
```

**Token scope:** repository access limited to `Samsiani/codeon-multilingual`, permissions: `Contents: Read`, `Metadata: Read` (auto-included). Without the token PUC silently reports "no updates available" while the repo is private.

## Install (dev)

```bash
git clone https://github.com/Samsiani/codeon-multilingual codeon-multilingual
cd codeon-multilingual
composer install
ln -s "$(pwd)" /path/to/wp-content/plugins/codeon-multilingual
```

Activate in WP admin → Plugins.

## Tests

```bash
composer install
vendor/bin/phpunit --testsuite=Unit
```

Unit tests use Brain Monkey to stub WP globals. The `Integration` testsuite is scaffolded for when wp-phpunit setup is added.

Run on every PR / push to main via `.github/workflows/ci.yml` (PHP 8.1, 8.2, 8.3 matrix).

## Release process

1. Bump `Version:` header in `codeon-multilingual.php`.
2. Commit + push to `main`.
3. Tag: `git tag v0.1.1 && git push --tags`.
4. `.github/workflows/release.yml` builds the release ZIP, stamps `CML_BUILD_ID` with `version+sha`, and publishes the release with the ZIP attached.

The release workflow refuses to publish if the plugin header version doesn't match the tag.

## Architecture

See `src/` for the full module layout. Top-level subdirs:

| Dir | Purpose |
|---|---|
| `Core/` | Bootstrap, languages, schema, backfill, translation groups |
| `Url/` | Routing (router + strategy interface + subdirectory impl), post/term link filters |
| `Query/` | `posts_clauses` and `terms_clauses` JOIN injection |
| `Content/` | Post and term translators (duplicate engines) |
| `Strings/` | Gettext interceptor + compiled translation cache + discovery |
| `Woo/` | WooCommerce product sync + variation auto-translation |
| `Rest/` | REST API `?lang=` parameter |
| `Admin/` | Top-level menu, admin bar, language and string pages |

## Roadmap

| Version | Focus |
|---|---|
| 0.1 | MVP — done |
| 0.2 | Frontend switcher, hreflang, html lang attr, sitemap filter |
| 0.3 | WPML migration tool, page builder JSON walker, query-param URL strategy |
| 0.4 | Multi-currency add-on, subdomain routing, translation workflow |
| 1.0 | Battle-tested, full WPML compat shim, one-click WPML migration |
