#!/usr/bin/env bash

set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-127.0.0.1}
WP_VERSION=${5:-latest}
WC_VERSION=${6:-latest}

WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}
WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
TMPDIR=${TMPDIR:-/tmp}

download() {
	local url=$1
	local destination=$2

	if command -v curl >/dev/null 2>&1; then
		curl -fsSL "$url" -o "$destination"
	elif command -v wget >/dev/null 2>&1; then
		wget -q -O "$destination" "$url"
	else
		echo "curl or wget is required to download WordPress test dependencies." >&2
		exit 1
	fi
}

install_wordpress() {
	local archive="$TMPDIR/wordpress.tar.gz"
	local url="https://wordpress.org/latest.tar.gz"

	if [[ "$WP_VERSION" != "latest" ]]; then
		url="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
	fi

	mkdir -p "$WP_CORE_DIR"
	download "$url" "$archive"
	tar --strip-components=1 -xzf "$archive" -C "$WP_CORE_DIR"
}

detect_wordpress_version() {
	php -r 'include $argv[1]; echo $wp_version;' "$WP_CORE_DIR/wp-includes/version.php"
}

install_test_suite() {
	local suite_version=$1
	local svn_base

	if [[ "$suite_version" == "trunk" || "$suite_version" == "nightly" ]]; then
		svn_base="https://develop.svn.wordpress.org/trunk/tests/phpunit"
	else
		svn_base="https://develop.svn.wordpress.org/tags/${suite_version}/tests/phpunit"
	fi

	if ! command -v svn >/dev/null 2>&1; then
		echo "svn is required to install the WordPress PHPUnit test suite." >&2
		exit 1
	fi

	mkdir -p "$WP_TESTS_DIR"
	svn export --quiet --force "$svn_base/includes" "$WP_TESTS_DIR/includes"
	svn export --quiet --force "$svn_base/data" "$WP_TESTS_DIR/data"
}

install_woocommerce() {
	if [[ -z "$WC_VERSION" || "$WC_VERSION" == "none" ]]; then
		return
	fi

	local plugin_dir="$WP_CORE_DIR/wp-content/plugins"
	local archive="$TMPDIR/woocommerce.zip"
	local url="https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip"

	if [[ "$WC_VERSION" != "latest" ]]; then
		url="https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip"
	fi

	if ! command -v unzip >/dev/null 2>&1; then
		echo "unzip is required to install WooCommerce for integration tests." >&2
		exit 1
	fi

	mkdir -p "$plugin_dir"
	download "$url" "$archive"
	rm -rf "$plugin_dir/woocommerce"
	unzip -q "$archive" -d "$plugin_dir"
}

create_database() {
	local db_host_name=$DB_HOST
	local db_port=

	if [[ "$DB_HOST" == *:* && "$DB_HOST" != \[* ]]; then
		db_host_name=${DB_HOST%%:*}
		db_port=${DB_HOST##*:}
	fi

	local mysql_args=(--host="$db_host_name" --user="$DB_USER")
	if [[ -n "$db_port" ]]; then
		mysql_args+=(--port="$db_port" --protocol=tcp)
	fi

	if ! command -v mysql >/dev/null 2>&1; then
		echo "mysql client is required to create the WordPress test database." >&2
		exit 1
	fi

	MYSQL_PWD="$DB_PASS" mysql "${mysql_args[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"
}

write_tests_config() {
	cat > "$WP_TESTS_DIR/wp-tests-config.php" <<PHP
<?php
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

\$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'CodeOn Multilingual Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'ABSPATH', '$WP_CORE_DIR/' );
PHP
}

install_wordpress
DETECTED_WP_VERSION=$(detect_wordpress_version)
TEST_SUITE_VERSION=$WP_VERSION
if [[ "$WP_VERSION" == "latest" ]]; then
	TEST_SUITE_VERSION=$DETECTED_WP_VERSION
fi

install_test_suite "$TEST_SUITE_VERSION"
install_woocommerce
create_database
write_tests_config

echo "WordPress core: $WP_CORE_DIR ($DETECTED_WP_VERSION)"
echo "WordPress tests: $WP_TESTS_DIR"
if [[ "$WC_VERSION" != "none" ]]; then
	echo "WooCommerce: $WP_CORE_DIR/wp-content/plugins/woocommerce/woocommerce.php"
fi
