#!/usr/bin/env bash
# Start the local development server (PHP built-in server).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
[ -f .env ] && set -a && . ./.env && set +a
WP_DIR="${WP_DIR:-.dev/wordpress}"
HOSTPORT="$(echo "${SITE_URL:-http://localhost:8080}" | sed -E 's#^https?://##; s#/.*$##')"
# PHP_CLI_SERVER_WORKERS lets two requests run at the same time (needed for the stock race test).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php -d upload_max_filesize=20M -d post_max_size=60M -S "$HOSTPORT" -t "$WP_DIR" "$ROOT/scripts/router.php"
