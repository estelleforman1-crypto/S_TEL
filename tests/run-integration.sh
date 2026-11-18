#!/usr/bin/env bash
# Run the integration tests inside the local WordPress site (scripts/setup-dev.sh first).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
[ -f .env ] && set -a && . ./.env && set +a
exec "$ROOT/scripts/wp" eval-file "$ROOT/tests/integration/run.php"
