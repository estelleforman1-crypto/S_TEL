# Jewelry Store

Online shop for an independent jewelry maker in Israel. WordPress and WooCommerce run the shop. This repository holds the custom theme, the workflow plugin, the development setup and the tests.

**Status: development build.** All products, prices, images, the shipping rate and the brand name are demo placeholders. Live payments are not set up. See [docs/launch-checklist.md](docs/launch-checklist.md) for what is necessary before launch.

## What is in this repository

| Path | Contents |
|---|---|
| `theme/jewelry-store/` | The storefront theme: templates, CSS, JavaScript, theme translations. No page builder. |
| `plugins/jewelry-workflows/` | Business workflows: requests, quotes, deposits, readiness and balance requests, the fulfillment gate, the late-payment stock guard, the three languages, the owner workspace ("Atelier" menu). |
| `scripts/` | Reproducible development setup, demo seed data, local server, development-only mail sandbox and demo gateway. |
| `tests/` | Integration tests (inside WordPress, real database) and browser tests (Playwright). |
| `docs/` | Specification, decisions, commerce validation, owner guide, launch checklist, screenshots. |
| `.github/workflows/ci.yml` | Runs every test against MySQL on each push. |

WordPress core, WooCommerce and the database are **not** in the repository. The setup script downloads pinned versions.

## Run it locally

Requirements: PHP 8.1 or later with `gd`, `intl`, `zip`, `pdo_sqlite` (or `mysqli`), [WP-CLI](https://wp-cli.org), `curl`, `unzip`. Node.js 18 or later for the browser tests.

```bash
cp .env.example .env
```

```bash
scripts/setup-dev.sh
```

```bash
scripts/serve.sh
```

Open <http://localhost:8080>. Admin: <http://localhost:8080/wp-admin>, user `admin`, password from `.env`.

By default the site uses SQLite, so no database server is necessary. To use MySQL, set `DB_ENGINE=mysql` and the `DB_*` values in `.env`. To start again from nothing, run `scripts/setup-dev.sh --reset`.

Development-only helpers (never deploy `scripts/dev-mu-plugins/`):

- **Mail sandbox.** No email leaves the machine. Each email is saved as a JSON file in `.dev/mail/`.
- **DEMO payment gateway.** It shows a page labeled "DEMO PAYMENT – NO MONEY MOVES". Its Approve button sends a signed server-to-server callback, like a real provider. The order becomes paid only from that callback, never from the browser return.

## Run the tests

```bash
tests/run-integration.sh
```

```bash
npm install
```

```bash
npm run test:flows && npm run test:admin && npm run test:visual
```

The browser tests need the server from `scripts/serve.sh`. Screenshots go to `tests/e2e/output/`. Results of the last run are in [docs/commerce-validation.md](docs/commerce-validation.md).

## How the shop works (short)

- **Ready to ship** (also one-of-a-kind, stock 1): normal cart and checkout, full payment, preset shipping rates. WooCommerce reserves the stock during checkout.
- **Made to order**: the product page shows a request form instead of "Add to cart". The owner quotes delivery, the customer accepts and pays a deposit (default 50%), the owner marks the piece ready, the customer receives the balance link by email.
- **Bespoke**: the commission page has a brief form with private reference photos. The owner can also record requests from email or WhatsApp. Each bespoke quote has its own deposit amount.

The owner manages everything in WordPress admin: **Atelier** (requests, quotes, readiness, balances) and **WooCommerce** (products, orders, refunds). See [docs/owner-guide.md](docs/owner-guide.md).

## Deploy

1. Get WordPress hosting with PHP 8.1+, MySQL/MariaDB, HTTPS and daily backups (see costs in the launch checklist).
2. Install WordPress and WooCommerce (the versions in `.env.example` are the tested ones).
3. Copy `theme/jewelry-store` to `wp-content/themes/` and `plugins/jewelry-workflows` to `wp-content/plugins/`. Do **not** copy `scripts/dev-mu-plugins/`.
4. In `wp-config.php`, set `define( 'WP_ENVIRONMENT_TYPE', 'production' );` and `define( 'JW_PRIVATE_UPLOAD_DIR', '/path/outside/the/web/root' );`.
5. Activate the theme and the plugin. Then work through [docs/launch-checklist.md](docs/launch-checklist.md).

Code changes go through this repository: change, test, then copy the two folders to the server (or use the host's Git deployment). Content (products, prices, stock, photos, orders) is managed in WordPress admin and is not in Git.

## Backups and restore

The database and the `wp-content/uploads` folder and the private upload folder hold all shop data. The code in this repository can be deployed again at any time.

- Back up the database and both folders every day, keep at least 14 days, and keep one copy away from the host. Most hosts include this. A free plugin such as UpdraftPlus can also send backups to cloud storage.
- Test a restore on a staging copy before launch and after big updates.
- Restore: import the database dump, copy back the two folders, deploy the theme and plugin from this repository, then check one order page and one product page.
- Before you update WooCommerce or WordPress: back up, update on a staging copy, run the tests, then update the live site.

## Known limitations

See "Known limitations" in [docs/decisions.md](docs/decisions.md).
