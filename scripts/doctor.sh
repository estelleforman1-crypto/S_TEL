#!/usr/bin/env bash
# Print everything needed to find out why the development shop does not run.
# Usage: npm run doctor   (then copy the whole output into the support request)
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
PORT="$(echo "${SITE_URL:-http://localhost:8080}" | sed -E 's#^https?://##; s#/.*$##; s#^[^:]*:?##')"; PORT="${PORT:-80}"
line() { printf '\n=== %s\n' "$1"; }

line "Where"
echo "codespaces=${CODESPACES:-no} name=${CODESPACE_NAME:-} user=$(id -un) pwd=$PWD"
echo "git branch: $(git rev-parse --abbrev-ref HEAD 2>/dev/null) commit: $(git rev-parse --short HEAD 2>/dev/null)"
echo ".devcontainer present: $([ -f .devcontainer/devcontainer.json ] && echo yes || echo NO)"
[ -f /etc/os-release ] && . /etc/os-release && echo "os: $PRETTY_NAME"

line "Tools"
for t in node npm php curl unzip wp; do printf '%-6s %s\n' "$t" "$(command -v $t || echo MISSING)"; done
node -v 2>/dev/null
php -v 2>/dev/null | head -1
echo "php modules: $(php -m 2>/dev/null | tr '\n' ' ' | grep -oiE '\b(pdo_sqlite|sqlite3|mysqli|gd|intl|zip|curl|mbstring|xml)\b' | tr '\n' ' ')"

line "Site build"
echo "wp-config: $([ -f .dev/wordpress/wp-config.php ] && echo yes || echo no)   setup complete: $([ -f .dev/.setup-complete ] && echo yes || echo no)"
grep -c "dev-wp-config.php" .dev/wordpress/wp-config.php 2>/dev/null | sed 's/^/dynamic host include: /'

line "Server on port $PORT"
pgrep -fa "php.*-S" || echo "no PHP server process"
curl -s -o /dev/null -m 10 -w "GET / -> HTTP %{http_code} redirect=%{redirect_url}\n" "http://127.0.0.1:$PORT/" || echo "no answer on 127.0.0.1:$PORT"
curl -s -o /dev/null -m 10 -w "GET / as Codespaces -> HTTP %{http_code} redirect=%{redirect_url}\n" -H "X-Forwarded-Host: test-$PORT.app.github.dev" -H "X-Forwarded-Proto: https" "http://127.0.0.1:$PORT/" 2>/dev/null

line "Logs"
for f in /tmp/jewelry-server.log .dev/server.log .dev/wordpress/wp-content/debug.log /workspaces/.codespaces/.persistedshare/creation.log; do
  [ -f "$f" ] && { echo "--- $f (last 25 lines)"; tail -n 25 "$f"; }
done
echo
echo "In the browser, also open <shop address>/__jw-headers and copy what it shows."
