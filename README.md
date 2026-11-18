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

## Run it

```bash
npm install
```

```bash
npm start
```

The first `npm start` builds the development shop (WordPress, WooCommerce, demo data) and takes a few minutes. Later starts take seconds. The terminal prints the shop address, the admin address and the login. Press Ctrl+C to stop the server.

| Command | What it does |
|---|---|
| `npm start` | Build the shop if needed, then run it. If it already runs, show where. |
| `npm run reset` | Delete the development shop and its data, and build it again. |
| `npm test` | Integration tests (inside WordPress). |
| `npm run test:flows`, `test:admin`, `test:visual` | Browser tests (need the server running and, once, `npm run test:browser-install`). |

Requirements: Node.js 18+, PHP 8.1+ with the `pdo_sqlite`, `curl`, `mbstring` and `xml` extensions (`gd`, `intl`, `zip` recommended), `curl`, and `unzip` or the PHP zip extension. `npm start` checks these and says what is missing. WP-CLI is downloaded automatically if it is not installed.

### In GitHub Codespaces

[![Open in GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/estelleforman1-crypto/S_TEL)

1. Create the codespace **from the branch that contains `.devcontainer/`** (until it is merged into `main`: Code > Codespaces > "…" > New with options > Branch). A codespace made from an empty `main` has no shop and no PHP setup; if that happened, delete it and create a new one from the right branch.
2. The first build installs PHP, builds the shop and starts the server. The shop opens in a preview tab. To open it in a normal tab: **Ports** tab > port 8080 "Shop" > globe icon.
3. Later, if the page does not load, run `npm start` in the terminal.

Good to know:

- The forwarded address (`https://<name>-8080.app.github.dev`) is private to you. Keep the port private: the admin password is a development default.
- The demo shop lives inside the codespace, not in Git. Deleting the codespace deletes it; `npm start` builds it again.
- Stop the codespace when you are done to save compute hours. A repository admin can enable a **prebuild** (Settings > Codespaces) for faster starts.

### Options

Copy `.env.example` to `.env` to change the port (`SITE_URL`), admin login, or to use MySQL (`DB_ENGINE=mysql` and `DB_*`). Development-only helpers (never deploy `scripts/dev-mu-plugins/`):

- **Mail sandbox.** No email leaves the machine. Each email is saved as a JSON file in `.dev/mail/`.
- **DEMO payment gateway.** It shows a page labeled "DEMO PAYMENT – NO MONEY MOVES". Its Approve button sends a signed server-to-server callback, like a real provider. The order becomes paid only from that callback, never from the browser return.

The shell scripts behind the npm commands are in `scripts/` (`setup-dev.sh`, `serve.sh`, and `wp` for WP-CLI commands on the dev site, for example `scripts/wp option get home`). Test results of the last full run are in [docs/commerce-validation.md](docs/commerce-validation.md).

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
