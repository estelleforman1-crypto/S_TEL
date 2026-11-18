#!/usr/bin/env node
// `npm start`: check requirements, build the development shop the first time,
// then run the local web server. Safe to run again at any time.
//   npm start            build if needed, then serve
//   npm start -- --reset start again from zero (deletes the dev site and its data)
'use strict';
const { spawnSync, spawn } = require('child_process');
const fs = require('fs');
const net = require('net');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
process.chdir(ROOT);

// .env values apply only when the variable is not already set.
if (fs.existsSync('.env')) {
	for (const line of fs.readFileSync('.env', 'utf8').split(/\r?\n/)) {
		const m = line.match(/^\s*([A-Z_][A-Z0-9_]*)=(.*)$/);
		if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
	}
}
const SITE_URL = process.env.SITE_URL || 'http://localhost:8080';
const PORT = Number(new URL(SITE_URL).port || 80);
const WP_DIR = process.env.WP_DIR || '.dev/wordpress';
const MARKER = path.join('.dev', '.setup-complete');
const reset = process.argv.includes('--reset');
const buildOnly = process.argv.includes('--build-only'); // Used by the dev container on creation.

function has(cmd) {
	return spawnSync('bash', ['-c', `command -v ${cmd}`], { stdio: 'ignore' }).status === 0;
}
function fail(msg) {
	console.error('\n  Cannot start: ' + msg + '\n');
	process.exit(1);
}

// ---- Requirements ----------------------------------------------------------
if (!has('bash')) fail('bash is required (Linux, macOS, WSL or GitHub Codespaces).');
if (!has('php')) {
	fail('PHP 8.1 or later is not installed.\n' +
		'  In GitHub Codespaces: open the Command Palette (Ctrl+Shift+P / Cmd+Shift+P) and run\n' +
		'  "Codespaces: Rebuild Container". If the codespace is in "recovery mode", that is the fix.\n' +
		'  On Debian/Ubuntu: sudo apt-get install -y php-cli php-sqlite3 php-gd php-intl php-zip php-curl php-xml php-mbstring');
}
const phpVersion = spawnSync('php', ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8' }).stdout.trim();
if (spawnSync('php', ['-r', 'exit(version_compare(PHP_VERSION, "8.1", ">=") ? 0 : 1);']).status !== 0) {
	fail(`PHP ${phpVersion} is too old. PHP 8.1 or later is required.`);
}
const modules = spawnSync('php', ['-m'], { encoding: 'utf8' }).stdout.toLowerCase().split(/\r?\n/);
const need = (process.env.DB_ENGINE || 'sqlite') === 'mysql' ? ['mysqli'] : ['pdo_sqlite'];
need.push('curl', 'mbstring', 'xml');
const missing = need.filter((m) => !modules.includes(m));
if (missing.length) fail(`PHP extension(s) missing: ${missing.join(', ')}.`);
for (const [mod, why] of [['gd', 'demo images are skipped'], ['intl', 'some formatting is simpler'], ['zip', 'the unzip tool is used instead']]) {
	if (!modules.includes(mod)) console.warn(`  Note: PHP extension "${mod}" is missing; ${why}.`);
}
if (!has('curl')) fail('curl is required to download WordPress and WooCommerce.');
if (!has('unzip') && !modules.includes('zip') && !modules.includes('phar')) fail('the "unzip" tool, or the PHP zip or phar extension, is required.');

// ---- Is the server already running? ----------------------------------------
function portOpen(port) {
	return new Promise((resolve) => {
		const s = net.connect({ host: '127.0.0.1', port }, () => { s.destroy(); resolve(true); });
		s.on('error', () => resolve(false));
		s.setTimeout(1000, () => { s.destroy(); resolve(false); });
	});
}

(async () => {
	const built = fs.existsSync(MARKER) && fs.existsSync(path.join(WP_DIR, 'wp-config.php'));
	if (!buildOnly && await portOpen(PORT)) {
		if (!built) fail(`a server is running on port ${PORT}, but the shop is not built yet. Stop that server first (pkill -f "php.*-S"), then run npm start again.`);
		if (reset) fail(`a server is already running on port ${PORT}. Stop it first (pkill -f "php.*-S"), then run npm start -- --reset.`);
		console.log(`\n  The shop server is already running on port ${PORT}.`);
		spawnSync('bash', ['scripts/dev-info.sh'], { stdio: 'inherit' });
		return;
	}

	// ---- Build the site the first time (or after --reset) -------------------
	if (reset || !built) {
		console.log(reset ? '\n  Rebuilding the development shop from zero...\n' : '\n  First run: building the development shop (WordPress, WooCommerce, demo data). This takes a few minutes...\n');
		const r = spawnSync('bash', ['scripts/setup-dev.sh', ...(reset ? ['--reset'] : [])], { stdio: 'inherit', env: process.env });
		if (r.status !== 0) fail('the setup did not finish. Read the messages above, fix the cause, then run npm start again.');
	} else {
		spawnSync('bash', ['scripts/dev-info.sh'], { stdio: 'inherit' });
	}

	if (buildOnly) return;

	// ---- Serve ---------------------------------------------------------------
	console.log('  Starting the web server. Press Ctrl+C to stop.\n');
	const server = spawn('bash', ['scripts/serve.sh'], { stdio: 'inherit', env: process.env });
	for (const sig of ['SIGINT', 'SIGTERM']) process.on(sig, () => server.kill(sig));
	server.on('exit', (code) => process.exit(code ?? 0));
})();
