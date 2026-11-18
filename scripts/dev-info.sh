#!/usr/bin/env bash
# Print where the development shop is and how to use it.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[ -f "$ROOT/.env" ] && set -a && . "$ROOT/.env" && set +a
if [ -n "${CODESPACE_NAME:-}" ]; then
  URL="https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
else
  URL="${SITE_URL:-http://localhost:8080}"
fi
cat <<MSG

  Jewelry Store – development build (demo content, DEMO payments, no real email)

  Shop:   $URL
  Admin:  $URL/wp-admin   user: ${ADMIN_USER:-admin}   password: ${ADMIN_PASSWORD:-change-me-local-only}
  Emails: .dev/mail/  (one JSON file per email; nothing is sent)

  Server not running?     scripts/serve.sh
  Start again from zero:  scripts/setup-dev.sh --reset
  Tests:                  tests/run-integration.sh  and  npm run test:flows

MSG
