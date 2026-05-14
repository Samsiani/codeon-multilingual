# Development guide

Setup, conventions, testing, and the release workflow.

## Prerequisites

- PHP 8.1 or newer (8.3 recommended for dev)
- Composer 2.x
- Git
- A local WordPress install at PHP 8.1+, WP 6.5+
- (optional) `gh` CLI for release management
- (optional) MySQL/MariaDB if you want to run integration tests against a real DB

## Initial setup

```bash
git clone https://github.com/Samsiani>/codeon-multilingual.git
cd codeon-multilingual
composer install
ln -s "$(pwd)" /path/to/wp-content/plugins/codeon-multilingual
```

Activate the plugin in `Plugins` → check the **Multilingual** menu appears.

If you're working against an existing WP install:

```bash
# Re-pull and re-link
cd ~/Documents/codeon-multilingual
git pull
composer install
# Symlink is preserved, just re-activate if needed
```

## Repository structure

```
.
├── assets/                  — Frontend + admin CSS/JS
├── src/                     — All plugin source
│   ├── Core/                — Bootstrap, settings, schema, activation, languages
│   ├── Url/                 — Routing, strategy, permalink filters
│   ├── Query/               — posts_clauses / terms_clauses JOIN injection
│   ├── Content/             — Post + term duplicate engines
│   ├── Strings/             — String translation runtime + scanner + .l10n.php writer
│   ├── Woo/                 — WooCommerce sync
│   ├── Frontend/            — Switcher, hreflang, html lang, locale, sitemap
│   ├── Rest/                — REST endpoints
│   ├── Migration/           — WPML importer
│   └── Admin/               — Menu, admin bar, page renderers
├── tests/
│   ├── Unit/                — Brain Monkey + Mockery unit tests
│   ├── Integration/         — (reserved for wp-phpunit; not yet populated)
│   ├── bootstrap.php        — Test bootstrap
│   └── phpstan-stubs.php    — Stubs declaring CML_* constants for PHPStan
├── .github/workflows/
│   ├── ci.yml               — Lint + PHPStan + unit tests (PHP 8.1, 8.2, 8.3 matrix)
│   └── release.yml          — Build release ZIP on v* tag push
├── codeon-multilingual.php  — Bootstrap (header, constants, autoloader)
├── uninstall.php            — Drop tables on plugin removal
├── composer.json            — Deps + autoload psr-4
├── composer.lock            — Committed per CodeOn convention
├── phpcs.xml.dist           — WordPress coding standards
├── phpstan.neon.dist        — Level 6 + szepeviktor/phpstan-wordpress
├── phpunit.xml.dist         — Test config
├── README.md                — Overview + quick start
├── ARCHITECTURE.md          — Technical deep dive
├── ROADMAP.md               — Status + gap analysis + forward plan
├── CHANGELOG.md             — Per-release notes
└── DEVELOPMENT.md           — This file
```

## Code conventions

- **PHP 8.1+ features encouraged**: typed properties, enums, readonly, match expressions, named arguments
- **`declare(strict_types=1)`** at the top of every file
- **Namespace** `Samsiani\CodeonMultilingual\` rooted at `src/`
- **Static-only classes** for stateless utilities (`StringTranslator`, `LanguageSwitcher`, etc.). Each exposes `register()` for hook wiring and pure static methods for everything else.
- **Final classes** unless a clear reason to extend exists
- **WordPress hooks** registered via static method references (`array( self::class, 'method' )`) — never anonymous closures (so they're removable for testing)
- **DB writes** go through `$wpdb` directly with `prepare()` + explicit format arrays. Raw `INSERT INTO … VALUES …` strings allowed when the values come from `prepare()`.
- **Output escaping** at the boundary: `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`. `wp_kses_post` only for known-safe HTML content.
- **No comments explaining WHAT** — names already do that. Comments explain WHY (a hidden constraint, a workaround, a subtle invariant).
- **No emojis in code** unless explicitly user-facing UI

## Testing

### Run the unit suite

```bash
vendor/bin/phpunit --testsuite=Unit
```

22 tests passing as of v0.6.0. Coverage:
- `Url\SubdirectoryStrategy` — 12 tests (detect, build_url, strip_lang_prefix, strip_from_request)
- `Frontend\HtmlLangAttribute` — 5 tests (regex edge cases, admin pass-through)
- `Strings\StringTranslator::hash` — 5 tests (determinism, domain/context discrimination)

### Add a new unit test

```php
// tests/Unit/MyModuleTest.php
namespace Samsiani\CodeonMultilingual\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Samsiani\CodeonMultilingual\Some\Module;

final class MyModuleTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'is_admin' )->justReturn( false );
        // ...stub other WP functions as needed
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_something(): void {
        $this->assertSame( 'expected', Module::method() );
    }
}
```

Brain Monkey stubs WP functions; Mockery is available for object mocking.

### Integration tests (planned, not yet built)

The `tests/Integration/` suite is reserved for full WP+MySQL tests using `wp-phpunit`. Scheduled for v0.9.0. Until then, integration testing is manual via the live artcase.ge deployment.

### Static analysis

```bash
vendor/bin/phpstan analyse --memory-limit=1G
```

Level 6 + `szepeviktor/phpstan-wordpress` for WP function signatures. The `tests/phpstan-stubs.php` file declares `CML_*` constants so analysis of `src/` doesn't see them as undefined.

### Coding standards

```bash
vendor/bin/phpcs                  # check
vendor/bin/phpcbf                 # auto-fix what's fixable
```

WordPress ruleset with the following exclusions (`phpcs.xml.dist`):
- `WordPress.Files.FileName` (we use PSR-4 file names, not WP's hyphen-separated)
- `Generic.Files.LineLength` (no max length)
- Doc-comment rules (we comment WHY, not just to satisfy a rule)
- `Universal.Operators.DisallowShortTernary` (we use `?:` freely)
- `WordPress.PHP.YodaConditions` (we don't write Yoda)

PHPCS runs with `continue-on-error: true` in CI — won't fail the build, but its annotations show up in the GitHub Checks UI.

### Manual end-to-end testing on artcase.ge

The site `artcase.ge` is the production validation environment. Updates flow through GitHub releases → wp-admin's auto-update UI. **Do not rsync directly to the live install** — all changes go through the release pipeline.

After updating on artcase.ge, you can SSH in for diagnostic queries:

```bash
ssh root@194.163.189.230 'cd /home/artcase.ge/public_html && wp eval-file /path/to/test.php --allow-root'
```

There's a small library of acceptance scripts in past commits (see `/tmp/cml-*.php` patterns) — copy-and-adapt for new test sessions.

## Release process

Every release is one tag push. The GitHub Actions release workflow does the rest.

### 1. Bump the version

The plugin version lives in **two places** (both in `codeon-multilingual.php`):

```php
* Version:           0.X.Y
…
define( 'CML_VERSION', '0.X.Y' );
```

Both must match. The release workflow validates this and refuses to publish if they don't.

### 2. Commit, push, tag

```bash
git add -A
git commit -m "vX.Y.Z — <one-line summary>

<paragraph describing what changed and why>

Co-Authored-By: …"
git push
git tag vX.Y.Z -m "Release vX.Y.Z — <summary>"
git push origin vX.Y.Z
```

### 3. Release workflow runs (~15 seconds)

`.github/workflows/release.yml` triggers on `v*` tag push:

1. Checkout
2. Setup PHP 8.1 + Composer
3. Derive `version` from `${REF_NAME#v}`
4. Stamp `src/Core/BuildId.php` with `version+sha`
5. Verify plugin-header version matches the tag (fails if not)
6. `composer install --no-dev --optimize-autoloader --prefer-dist`
7. rsync to staging dir excluding `.git`, `.github`, `tests`, `phpcs.xml.dist`, etc.
8. ZIP the staging dir
9. Create GitHub Release with the ZIP attached, auto-generated release notes

### 4. Update on production

Each WP install with the plugin sees the new release within 12 hours (PUC's default check interval) — or click **Check Again** on the plugin row to force.

To force-check via SSH:
```bash
ssh root@SERVER 'cd /home/SITE/public_html && \
  wp db query "DELETE FROM wp_options WHERE option_name = \"external_updates-codeon-multilingual\"" --allow-root && \
  wp eval "delete_site_transient(\"update_plugins\"); wp_update_plugins();" --allow-root'
```

### 5. Update `CHANGELOG.md`

Always update the changelog with the same commit that bumps the version — or in a follow-up commit referencing the tag. The release workflow doesn't enforce this but readers expect it.

## CI workflow

`.github/workflows/ci.yml` runs on push to main + every PR:

- **lint job**: PHP syntax check, PHPStan (must pass), PHPCS (advisory)
- **unit-tests job**: matrix on PHP 8.1, 8.2, 8.3 — `phpunit --testsuite=Unit`

Both must be green for a clean release.

## Plugin Update Checker (PUC)

We use `yahnis-elsts/plugin-update-checker ^5.6`. Wired in `codeon-multilingual.php`:

```php
$cml_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/Samsiani/codeon-multilingual/',
    __FILE__,
    'codeon-multilingual'
);
$cml_vcs_api = $cml_update_checker->getVcsApi();
if ( method_exists( $cml_vcs_api, 'enableReleaseAssets' ) ) {
    $cml_vcs_api->enableReleaseAssets();   // download the release ZIP, not the repo tarball
}
if ( defined( 'CML_GITHUB_TOKEN' ) && '' !== CML_GITHUB_TOKEN ) {
    $cml_update_checker->setAuthentication( CML_GITHUB_TOKEN );   // private-repo path
}
```

### Behaviour by repo visibility

- **Public repo** (current): PUC fetches `latest` release without authentication. Every install sees updates automatically.
- **Private repo**: each install must define `CML_GITHUB_TOKEN` in `wp-config.php` (fine-grained PAT with `Contents: Read` on this repo only). Without the token, PUC silently reports "no update available".

## Branching and commit conventions

- Trunk-based — work directly on `main` for now (small team, low conflict risk)
- Feature branches when a change is risky or being reviewed
- Squash-merge PRs to keep `main` history linear
- Commit messages: first line under 70 chars, blank line, then body. Always include the `Co-Authored-By` trailer when AI-assisted.

## Debugging tips

### Plugin not loading
- Check `wp-content/debug.log` (set `WP_DEBUG_LOG = true` in wp-config.php)
- Verify `composer install` ran (or `vendor/autoload_packages.php` exists)
- `wp plugin status codeon-multilingual` to see WP's view

### Translations not appearing
- Check `Multilingual → Settings` → "Uploads directory writable" indicator
- Check `cml_l10n_health` option for last failure (`wp option get cml_l10n_health`)
- Verify the `.l10n.php` file exists: `ls -la wp-content/uploads/cml-translations/`
- If using wp_cache path: `wp_cache_flush_group cml_strings` (Redis-aware)
- Force compiled-map rebuild: edit any translation in the strings admin

### Language URL not detected
- Add a `mu-plugins/cml-debug.php` with:
  ```php
  <?php
  add_action('wp_footer', function () {
      if (!current_user_can('manage_options')) return;
      echo '<!-- CML: ' . \Samsiani\CodeonMultilingual\Core\CurrentLanguage::code() . ' -->';
  });
  ```
- Visit the URL, view source, check the comment

### REST API returns wrong language
- Verify `?lang=xx` is in the URL
- Check Languages → ensure target is `active = 1`
- `Rest/LangParam` only honors active languages; unknown codes are ignored

## Where to ask questions

- Open an issue in the GitHub repo
- For architectural questions, reference `ARCHITECTURE.md` first
- For roadmap questions, reference `ROADMAP.md` first
