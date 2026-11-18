#!/usr/bin/env bash
# First-time setup in GitHub Codespaces / Dev Containers. Safe to run again by hand:
#   bash scripts/codespace-setup.sh
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
SUDO=""; [ "$(id -u)" != "0" ] && SUDO="sudo"

echo "== 1/3 Optional PHP extensions (images, languages, zip)"
missing=""
for m in gd intl zip; do php -m | grep -qix "$m" || missing="$missing $m"; done
if [ -n "$missing" ] && command -v docker-php-ext-install > /dev/null; then
  # The image lists a Yarn package source whose signing key has expired, which makes
  # "apt-get update" fail. Yarn is not needed here, so drop that source first.
  $SUDO rm -f /etc/apt/sources.list.d/yarn.list
  ( $SUDO apt-get update -qq \
    && $SUDO apt-get install -y -qq libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev libzip-dev unzip > /dev/null \
    && $SUDO docker-php-ext-configure gd --with-jpeg --with-freetype > /dev/null \
    && $SUDO docker-php-ext-install -j"$(nproc)"$missing > /dev/null ) \
    && echo "   installed:$missing" || echo "   could not install$missing (the shop still works; demo images may be missing)"
else
  echo "   nothing to install"
fi

echo "== 2/3 The shop"
npm install --no-audit --no-fund && npm start -- --build-only || echo "   Shop build failed. Run: npm start"

echo "== 3/3 Optional test browser"
npx playwright install --with-deps chromium > /dev/null 2>&1 && echo "   installed" || echo "   skipped (later: npm run test:browser-install)"
