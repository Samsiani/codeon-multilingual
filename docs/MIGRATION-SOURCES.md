# CodeOn Migration Sources

This document defines the v1.0 migration bar. A source is not considered
"supported" until it has dry-run reporting, conflict detection, idempotent
import, snapshot rollback, tests, and post-import health checks.

## Migration Rules

- Source plugin tables are read-only. CodeOn never deletes WPML, Polylang, or
  other source-plugin data during import.
- Every production import must offer a dry run first.
- Every production import must create a CodeOn snapshot before writing, unless
  the operator uses an explicit unsafe bypass.
- Existing CodeOn rows are not overwritten silently. Conflicts stop the admin
  importer and require an explicit WP-CLI policy.
- Re-running the same import must be idempotent.
- Post-import health must report missing rows, orphan rows, and unknown language
  codes.

## Source Matrix

| Source | Model | v1.0 target | Current support | Remaining work |
|---|---|---:|---|---|
| WPML | Separate translated posts/terms plus `icl_*` tables | Full import | Languages, posts, terms, strings, dry run, conflicts, snapshots, rollback | String packages, workflow/status metadata, multicurrency |
| Polylang | WordPress taxonomy relationships | Full object import | Taxonomy-backed languages, post mappings, term mappings, dry run, conflicts, admin + WP-CLI import | Real-export validation for terms/products and `polylang_mo` string adapter |
| TranslatePress | Rendered-string/dictionary overlay | String import plus explicit content-split policy | Detection planned | Dictionary/gettext/slug tables, SEO data, content duplication policy |
| Weglot | Remote/API/proxy translation layer | Export/API/crawl adapter | Planned | API/export reader, URL crawl fallback, source-string matching |
| GTranslate / ConveyThis | Proxy/automatic translation layer | Export/API/crawl adapter | Planned | Same as Weglot; may not expose complete local data |
| MultilingualPress | One language per multisite blog | Cross-site merge adapter | Planned | `switch_to_blog()` import, URL/media merge policy, duplicate-slug policy |
| qTranslate-X | Inline language tags in one field | Field parser + object duplicator | Planned | Parse title/content/excerpt/meta/options, duplicate objects, preserve shortcodes |
| WPGlobus | Inline/multi-field language data | Field parser + object duplicator | Planned | Similar to qTranslate-X; source-specific syntax parser |
| Falang | Joomla-style translation tables | Table adapter | Planned | Schema research, term/post/meta mapping |
| Loco Translate | PO/MO file/string editor, not content multilingual | Strings import only | Existing PO import path | Document as string-only, not full site migration |

## Adapter Requirements

Each adapter must produce this report shape before any writes:

- detected source version and tables/options
- source languages and default language
- post mapping count
- term mapping count
- string/source count
- conflict count by table
- unsupported data categories
- estimated writes
- rollback snapshot status

## Current Commands

```sh
wp cml migrate sources
wp cml migrate wpml --dry-run
wp cml migrate wpml --snapshot=/path/codeon-before-wpml.json
wp cml migrate wpml --no-snapshot --confirm-no-snapshot
wp cml migrate polylang --dry-run
wp cml migrate polylang --snapshot=/path/codeon-before-polylang.json
wp cml migrate export --output=/path/codeon-snapshot.json
wp cml migrate rollback /path/codeon-snapshot.json --dry-run
```

## v1.0 Acceptance

- WPML import works on at least one real WooCommerce store with products,
  variations, terms, menus, strings, and SEO metadata.
- Polylang import works on at least one real WooCommerce store with products,
  variations, product categories, product tags, and attributes. The current
  importer writes only when Polylang taxonomy rows are present; API-only
  detection is reported but is not importable yet.
- TranslatePress migration has a documented safe path. If object splitting is
  not implemented, the importer must clearly report it as string/dictionary
  migration only.
- Remote/proxy plugins must not claim full migration unless source translations
  are available through API/export/crawl and mapped into CodeOn deterministically.
