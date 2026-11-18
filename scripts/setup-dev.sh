#!/usr/bin/env bash
# Build a local development site from this repository.
# The site goes into $WP_DIR (default .dev/wordpress). It is not committed.
# Usage: scripts/setup-dev.sh [--reset]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
[ -f .env ] && set -a && . ./.env && set +a

SITE_URL="${SITE_URL:-http://localhost:8080}"
WP_DIR="${WP_DIR:-.dev/wordpress}"
DB_ENGINE="${DB_ENGINE:-sqlite}"
WP_VERSION="${WP_VERSION:-7.1.4}"
WC_VERSION="${WC_VERSION:-11.3.0}"
SQLITE_PLUGIN_VERSION="${SQLITE_PLUGIN_VERSION:-3.1.3}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-change-me-local-only}"
ADMIN_EMAIL="${ADMIN_EMAIL:-dev@example.test}"
WP="${WP_CLI:-wp} --path=$WP_DIR"
[ "$(id -u)" = "0" ] && WP="$WP --allow-root"

if [ "${1:-}" = "--reset" ]; then
  rm -rf "$WP_DIR" .dev/mail .dev/private-uploads
  [ "$DB_ENGINE" = "mysql" ] && mysql -h"$DB_HOST" -u"$DB_USER" ${DB_PASSWORD:+-p"$DB_PASSWORD"} -e "DROP DATABASE IF EXISTS \`$DB_NAME\`" || true
fi

mkdir -p "$WP_DIR" .dev/mail .dev/private-uploads

if [ ! -f "$WP_DIR/wp-load.php" ]; then
  $WP core download --version="$WP_VERSION" --skip-content
fi
mkdir -p "$WP_DIR/wp-content/plugins" "$WP_DIR/wp-content/themes" "$WP_DIR/wp-content/mu-plugins"

if [ ! -f "$WP_DIR/wp-config.php" ]; then
  if [ "$DB_ENGINE" = "sqlite" ]; then
    $WP config create --dbname=wordpress --dbuser=unused --dbpass=unused --skip-check
  else
    $WP config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="${DB_PASSWORD:-}" --dbhost="$DB_HOST"
    $WP db create || true
  fi
  $WP config set WP_ENVIRONMENT_TYPE local
  $WP config set WP_DEBUG true --raw
  $WP config set WP_DEBUG_LOG true --raw
  $WP config set WP_DEBUG_DISPLAY false --raw
  # Private commission uploads live outside the web root.
  $WP config set JW_PRIVATE_UPLOAD_DIR "$ROOT/.dev/private-uploads"
  $WP config set JW_DEV_MAIL_DIR "$ROOT/.dev/mail"
fi

if [ "$DB_ENGINE" = "sqlite" ] && [ ! -f "$WP_DIR/wp-content/db.php" ]; then
  # WP-CLI cannot boot without a database, so fetch the SQLite drop-in directly.
  tmpzip="$(mktemp -d)/sqlite.zip"
  curl -fsSL -o "$tmpzip" "https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_PLUGIN_VERSION}.zip"
  unzip -qo "$tmpzip" -d "$WP_DIR/wp-content/plugins/"
  sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$ROOT/$WP_DIR/wp-content/plugins/sqlite-database-integration#" \
      -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
      "$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" > "$WP_DIR/wp-content/db.php"
fi

if ! $WP core is-installed 2>/dev/null; then
  $WP core install --url="$SITE_URL" --title="Jewelry Store (development)" \
    --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASSWORD" --admin_email="$ADMIN_EMAIL" --skip-email
fi

# Project code is linked, not copied, so edits in the repo show at once.
ln -sfn "$ROOT/theme/jewelry-store" "$WP_DIR/wp-content/themes/jewelry-store"
ln -sfn "$ROOT/plugins/jewelry-workflows" "$WP_DIR/wp-content/plugins/jewelry-workflows"
# Development-only helpers: mail sandbox and labeled mock gateway. Never deploy.
ln -sfn "$ROOT/scripts/dev-mu-plugins/jw-dev-sandbox.php" "$WP_DIR/wp-content/mu-plugins/jw-dev-sandbox.php"

# Downloads use curl, so they work behind HTTPS proxies that PHP does not use.
fetch() { curl -fsSL --retry 3 -o "$2" "$1"; }
if ! $WP plugin is-installed woocommerce; then
  fetch "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip" .dev/woocommerce.zip
  $WP plugin install .dev/woocommerce.zip
fi
$WP plugin activate woocommerce
$WP plugin activate jewelry-workflows
$WP theme activate jewelry-store
$WP rewrite structure '/%postname%/' --hard 2>/dev/null || $WP rewrite structure '/%postname%/'

# Translations for WordPress and WooCommerce interface strings.
install_lang() { # $1 = api path (core | plugins), $2 = slug, $3 = version, $4 = target dir
  local json
  json="$(curl -fsSL "https://api.wordpress.org/translations/$1/1.0/?version=$3${2:+&slug=$2}")" || return 1
  for loc in he_IL fr_FR; do
    url="$(printf '%s' "$json" | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach($j["translations"]??[] as $t){ if($t["language"]===$argv[1]){ echo $t["package"]; } }' "$loc")"
    [ -n "$url" ] || { echo "WARN: no $loc package for ${2:-core}"; continue; }
    fetch "$url" .dev/lang.zip && mkdir -p "$4" && unzip -qo .dev/lang.zip -d "$4"
  done
}
install_lang core "" "$WP_VERSION" "$WP_DIR/wp-content/languages" || echo "WARN: core language packs not installed"
install_lang plugins woocommerce "$WC_VERSION" "$WP_DIR/wp-content/languages/plugins" || echo "WARN: WooCommerce language packs not installed"

$WP eval-file "$ROOT/scripts/seed-demo.php"
echo
echo "Ready. Start the server with: scripts/serve.sh"
echo "Admin: $SITE_URL/wp-admin  user: $ADMIN_USER"
