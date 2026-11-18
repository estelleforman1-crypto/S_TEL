#!/usr/bin/env bash
# Start the local development server (PHP built-in server).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
[ -f .env ] && set -a && . ./.env && set +a
WP_DIR="${WP_DIR:-.dev/wordpress}"
PORT="$(echo "${SITE_URL:-http://localhost:8080}" | sed -E 's#^https?://##; s#/.*$##; s#^[^:]*:?##')"
PORT="${PORT:-80}"
if [ ! -f "$WP_DIR/wp-config.php" ] || [ ! -f .dev/.setup-complete ]; then
  echo "The development shop is not built yet. Run: npm start   (it builds the shop, then serves it)" >&2
  exit 1
fi
# 127.0.0.1, not "localhost": "localhost" can bind to IPv6 only, which port forwarding
# (Codespaces, VS Code) may not reach. Set SERVER_BIND=0.0.0.0 to allow other machines.
HOSTPORT="${SERVER_BIND:-127.0.0.1}:$PORT"
# PHP_CLI_SERVER_WORKERS lets two requests run at the same time (needed for the stock race test).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php -d upload_max_filesize=20M -d post_max_size=60M -S "$HOSTPORT" -t "$WP_DIR" "$ROOT/scripts/router.php"
