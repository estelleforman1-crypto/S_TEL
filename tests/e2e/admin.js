// Owner workspace smoke test: log in, record a request, prepare and send a quote
// through the admin UI, and check every Atelier screen renders without PHP errors.
// Usage: node tests/e2e/admin.js [baseUrl]   (ADMIN_USER / ADMIN_PASSWORD from .env or defaults)
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || process.env.SITE_URL || 'http://localhost:8080';
const USER = process.env.ADMIN_USER || 'admin';
const PASS = process.env.ADMIN_PASSWORD || 'change-me-local-only';
const OUT = path.join(__dirname, 'output');
fs.mkdirSync(OUT, { recursive: true });

let pass = 0;
const failures = [];
function check(cond, msg) {
	if (cond) { pass++; console.log('  ok   ' + msg); } else { failures.push(msg); console.log('  FAIL ' + msg); }
}
async function clean(page, label) {
	const body = await page.textContent('body');
	check(!/(Fatal error|Warning:|Notice:|Deprecated:|There has been a critical error)/.test(body), `${label}: no PHP errors on screen`);
}

(async () => {
	const browser = await chromium.launch();
	const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
	await page.goto(BASE + '/wp-login.php');
	await page.fill('#user_login', USER);
	await page.fill('#user_pass', PASS);
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/);

	await page.goto(BASE + '/wp-admin/admin.php?page=jw-record');
	await clean(page, 'Record a request');
	await page.selectOption('#jw-type', 'bespoke');
	await page.selectOption('#jw-channel', 'whatsapp');
	await page.selectOption('#jw-lang', 'fr');
	await page.fill('#jw-name', 'WhatsApp Client');
	await page.fill('#jw-email', 'whatsapp@example.test');
	await page.fill('#jw-country', 'IL');
	await page.fill('#jw-brief', 'Asked on WhatsApp for a custom pendant.');
	await page.click('#submit');
	await page.waitForURL(/post\.php\?post=\d+/);
	check(await page.isVisible('text=Request recorded'), 'manual request recorded from a WhatsApp conversation');
	await clean(page, 'Request screen');
	await page.screenshot({ path: path.join(OUT, 'admin-request.png') });

	await page.click('text=Prepare quote');
	await page.waitForURL(/page=jw-quote/);
	await clean(page, 'Quote editor');
	await page.fill('#jwq-description', 'Custom pendant');
	await page.fill('#jwq-price', '1500');
	await page.fill('#jwq-shipping', '30');
	await page.fill('#jwq-lead_time', '6 weeks');
	await page.click('#submit');
	await page.waitForLoadState('load');
	check(await page.isVisible('text=Enter the deposit amount'), 'bespoke quote without a deposit is refused');
	await page.goBack();
	await page.fill('#jwq-description', 'Custom pendant');
	await page.fill('#jwq-price', '1500');
	await page.fill('#jwq-shipping', '30');
	await page.fill('#jwq-deposit', '400');
	await page.click('#submit');
	await page.waitForURL(/(wc-orders|post\.php).*(id|post)=\d+/);
	check(await page.isVisible('text=Quote saved as draft'), 'bespoke quote saved as draft');
	await clean(page, 'Order screen with workflow panel');
	const panel = await page.textContent('#jw-workflow');
	check(/400/.test(panel) && /1,530/.test(panel), 'workflow panel shows total 1,530 and deposit 400');
	await page.click('text=Send quote to customer');
	await page.waitForLoadState('load');
	check(await page.isVisible('text=Quote sent'), 'quote sent from the order screen');
	check(await page.isVisible('#jw-workflow .jw-copy'), 'quote link is shown for copy and paste');
	await page.screenshot({ path: path.join(OUT, 'admin-order-workflow.png'), fullPage: true });

	for (const [label, url] of [
		['Atelier overview', '/wp-admin/admin.php?page=jw-atelier'],
		['Requests list', '/wp-admin/edit.php?post_type=jw_request'],
		['Settings', '/wp-admin/admin.php?page=jw-settings'],
	]) {
		await page.goto(BASE + url);
		await clean(page, label);
		await page.screenshot({ path: path.join(OUT, 'admin-' + label.toLowerCase().replace(/\W+/g, '-') + '.png'), fullPage: true });
	}
	const overview = await page.goto(BASE + '/wp-admin/admin.php?page=jw-atelier').then(() => page.textContent('#wpbody-content'));
	check(/Quotes waiting for the customer/.test(overview) && /WhatsApp Client/.test(overview), 'overview lists the sent quote');
	check(/Needs attention: payment received after cancellation/.test(overview), 'overview surfaces late payments that need reconciliation');

	await browser.close();
	console.log(`\n${pass} passed, ${failures.length} failed`);
	if (failures.length) {
		console.error('Failures:\n- ' + failures.join('\n- '));
		process.exit(1);
	}
})().catch((e) => { console.error(e); process.exit(1); });
