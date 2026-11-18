#!/usr/bin/env bash
# Run the integration tests inside the local WordPress site (scripts/setup-dev.sh first).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
[ -f .env ] && set -a && . ./.env && set +a
WP="${WP_CLI:-wp} --path=${WP_DIR:-.dev/wordpress}"
[ "$(id -u)" = "0" ] && WP="$WP --allow-root"
exec $WP eval-file "$ROOT/tests/integration/run.php"
