#!/usr/bin/env bash
#
# Media Mage - test environment setup.
#
# Creates a fresh WP install at $WPMM_TEST_DIR, points it at a fresh DB,
# copies the plugin in, activates it, and runs the seed fixtures.
#
# Run from anywhere:
#     bash media-mage/test/setup.sh
#
# Re-runnable: drops + recreates the DB, wipes the install dir, re-seeds.
# This is intentional - it's a test fixture, not user data.

set -euo pipefail

# ---- Config ---------------------------------------------------------------
WPMM_TEST_NAME="${WPMM_TEST_NAME:-wpmm-test-1}"
WPMM_TEST_DB="${WPMM_TEST_DB:-wpmm_test_1}"
WPMM_TEST_PARENT="${WPMM_TEST_PARENT:-/c/xampp/htdocs/library/Claude Code Projects}"
WPMM_TEST_DIR="$WPMM_TEST_PARENT/$WPMM_TEST_NAME"
WPMM_TEST_URL="${WPMM_TEST_URL:-http://localhost/library/Claude%20Code%20Projects/$WPMM_TEST_NAME}"

# Admin creds sourced from a gitignored file (keeps the password out of public
# source); env vars override; neutral fallback if the file is absent.
[ -f "$HOME/.wp-local-creds" ] && . "$HOME/.wp-local-creds"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASS="${WP_ADMIN_PASS:-admin}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-lincolnmtracy@gmail.com}"

PHP_BIN="${PHP_BIN:-/c/xampp/php/php.exe}"
MYSQL_BIN="${MYSQL_BIN:-/c/xampp/mysql/bin/mysql.exe}"
WP_CLI_PHAR="$WPMM_TEST_PARENT/wp-cli.phar"

# Run wp-cli with a path containing spaces. Caller passes wp-cli args.
wp_cli() {
	"$PHP_BIN" "$WP_CLI_PHAR" "$@"
}

PLUGIN_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ---- Sanity checks --------------------------------------------------------
echo "==> Media Mage test setup"
echo "    install:  $WPMM_TEST_DIR"
echo "    URL:      $WPMM_TEST_URL"
echo "    DB:       $WPMM_TEST_DB"
echo "    plugin:   $PLUGIN_SRC"
echo

[[ -x "$PHP_BIN"   ]] || { echo "PHP not found at $PHP_BIN"; exit 1; }
[[ -x "$MYSQL_BIN" ]] || { echo "mysql.exe not found at $MYSQL_BIN"; exit 1; }
[[ -f "$WPMM_TEST_PARENT/wp-cli.phar" ]] || { echo "wp-cli.phar not found at $WPMM_TEST_PARENT/wp-cli.phar"; exit 1; }
[[ -f "$PLUGIN_SRC/media-mage.php"  ]] || { echo "media-mage.php not found in $PLUGIN_SRC"; exit 1; }

# ---- Tear down ------------------------------------------------------------
if [[ -d "$WPMM_TEST_DIR" ]]; then
	echo "==> Removing existing install at $WPMM_TEST_DIR"
	rm -rf "$WPMM_TEST_DIR"
fi

echo "==> (Re)creating database $WPMM_TEST_DB"
"$MYSQL_BIN" -u root -e "DROP DATABASE IF EXISTS \`$WPMM_TEST_DB\`; CREATE DATABASE \`$WPMM_TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# ---- Download + configure WP ---------------------------------------------
mkdir -p "$WPMM_TEST_DIR"
cd "$WPMM_TEST_DIR"

echo "==> Downloading WordPress core"
# Note: not --skip-content - we need at least one default theme so the
# front-end renders. --skip-content would download WP without wp-content/
# (no themes, no default plugins) and the home page would 200 with an
# empty body since there's no theme to render.
wp_cli core download --quiet

echo "==> Generating wp-config.php"
wp_cli config create \
	--dbname="$WPMM_TEST_DB" \
	--dbuser=root \
	--dbpass='' \
	--dbhost=localhost \
	--quiet

echo "==> Installing WordPress"
wp_cli core install \
	--url="$WPMM_TEST_URL" \
	--title="Media Mage Test" \
	--admin_user="$WP_ADMIN_USER" \
	--admin_password="$WP_ADMIN_PASS" \
	--admin_email="$WP_ADMIN_EMAIL" \
	--skip-email \
	--quiet

# ---- Install + activate plugin -------------------------------------------
echo "==> Copying media-mage plugin into install"
mkdir -p "$WPMM_TEST_DIR/wp-content/plugins/media-mage"
cp "$PLUGIN_SRC/media-mage.php" "$WPMM_TEST_DIR/wp-content/plugins/media-mage/"
if [[ -d "$PLUGIN_SRC/assets" ]]; then
	cp -R "$PLUGIN_SRC/assets" "$WPMM_TEST_DIR/wp-content/plugins/media-mage/"
fi

echo "==> Activating media-mage"
wp_cli plugin activate media-mage --quiet

# Default theme - WP auto-activates the bundled default during core install,
# so we just confirm one is active. If somehow none is active, fall back to
# whichever theme is on disk.
ACTIVE_THEME=$(wp_cli theme list --status=active --field=name --format=csv 2>/dev/null | head -n1 || echo "")
if [[ -z "$ACTIVE_THEME" ]]; then
	FALLBACK=$(wp_cli theme list --field=name --format=csv 2>/dev/null | head -n1 || echo "")
	if [[ -n "$FALLBACK" ]]; then
		echo "==> Activating fallback theme: $FALLBACK"
		wp_cli theme activate "$FALLBACK" --quiet || true
	else
		echo "==> WARN: no themes on disk - installing twentytwentyfour"
		wp_cli theme install twentytwentyfour --activate --quiet
	fi
fi

# ---- Seed fixtures --------------------------------------------------------
echo "==> Running seed fixtures"
wp_cli eval-file "$PLUGIN_SRC/test/seed.php"

# ---- Done -----------------------------------------------------------------
echo
echo "==> Done."
echo "    Admin URL: $WPMM_TEST_URL/wp-admin/"
echo "    Login:     $WP_ADMIN_USER / $WP_ADMIN_PASS"
echo "    Media Mage: $WPMM_TEST_URL/wp-admin/upload.php?page=media-mage"
