#!/usr/bin/env bash
set -euo pipefail

ZIP_PATH="${1:-}"
BUILD_DIR=""

build_package() {
  BUILD_DIR="$(mktemp -d)"
  local pkg="$BUILD_DIR/codeon-multilingual"

  mkdir -p "$pkg"
  rsync -a \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='tests' \
    --exclude='node_modules' \
    --exclude='*.zip' \
    --exclude='.DS_Store' \
    --exclude='phpcs.xml.dist' \
    --exclude='phpstan.neon.dist' \
    --exclude='phpunit.xml.dist' \
    --exclude='phpunit.integration.xml.dist' \
    --exclude='.gitignore' \
    --exclude='vendor' \
    ./ "$pkg/"

  composer install \
    --working-dir="$pkg" \
    --no-dev \
    --no-interaction \
    --optimize-autoloader \
    --prefer-dist

  (cd "$BUILD_DIR" && zip -rq codeon-multilingual.zip codeon-multilingual)
  ZIP_PATH="$BUILD_DIR/codeon-multilingual.zip"
}

if [[ -z "$ZIP_PATH" ]]; then
  build_package
fi

if [[ ! -f "$ZIP_PATH" ]]; then
  echo "Package not found: $ZIP_PATH" >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
cleanup() {
  rm -rf "$TMP_DIR"
  if [[ -n "$BUILD_DIR" ]]; then
    rm -rf "$BUILD_DIR"
  fi
}
trap cleanup EXIT

unzip -q "$ZIP_PATH" -d "$TMP_DIR"
PKG="$TMP_DIR/codeon-multilingual"

if [[ ! -d "$PKG" ]]; then
  echo "ZIP must contain a top-level codeon-multilingual directory." >&2
  exit 1
fi

if [[ ! -f "$PKG/vendor/autoload.php" && ! -f "$PKG/vendor/autoload_packages.php" ]]; then
  echo "Packaged plugin is missing a Composer autoloader." >&2
  exit 1
fi

for forbidden in .git .github tests node_modules phpcs.xml.dist phpstan.neon.dist phpunit.xml.dist phpunit.integration.xml.dist; do
  if [[ -e "$PKG/$forbidden" ]]; then
    echo "Package contains forbidden development path: $forbidden" >&2
    exit 1
  fi
done

for dev_dep in phpunit phpstan squizlabs wp-coding-standards brain mockery phpcompatibility; do
  if compgen -G "$PKG/vendor/*/$dev_dep" > /dev/null || [[ -e "$PKG/vendor/$dev_dep" ]]; then
    echo "Package contains development dependency: $dev_dep" >&2
    exit 1
  fi
done

if [[ "$(head -c 5 "$PKG/src/Core/BuildId.php")" != "<?php" ]]; then
  echo "BuildId.php must start with <?php exactly." >&2
  exit 1
fi

find "$PKG" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

echo "Package smoke test passed: $ZIP_PATH"
