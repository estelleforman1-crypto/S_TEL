// Responsive and RTL checks with screenshots.
// Usage: node tests/e2e/visual.js [baseUrl]   (server from scripts/serve.sh must be running)
// Fails when a page has horizontal overflow, a missing <h1>, images without alt,
// or the wrong text direction. Screenshots go to tests/e2e/output/.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || process.env.SITE_URL || 'http://localhost:8080';
const OUT = path.join(__dirname, 'output');
fs.mkdirSync(OUT, { recursive: true });

const WIDTHS = [360, 390, 768, 1440];
const PAGES = [
	['home', '/'],
	['shop', '/shop/'],
	['product-ready', '/product/demo-signet-ring/'],
	['product-mto', '/product/demo-band-ring/'],
	['commissions', '/commissions/'],
	['cart', '/cart/'],
	['404', '/no-such-page/'],
];
const LANGS = [['en', ''], ['he', '/he']];

(async () => {
	const browser = await chromium.launch();
	const failures = [];
	for (const [lang, prefix] of LANGS) {
		for (const width of WIDTHS) {
			const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
			const page = await ctx.newPage();
			for (const [name, url] of PAGES) {
				const res = await page.goto(BASE + prefix + url, { waitUntil: 'load' });
				const status = res.status();
				const expected = name === '404' ? 404 : 200;
				const check = await page.evaluate(() => ({
					overflow: document.documentElement.scrollWidth - window.innerWidth,
					dir: document.documentElement.getAttribute('dir') || 'ltr',
					lang: document.documentElement.getAttribute('lang'),
					h1: document.querySelectorAll('h1').length,
					noAlt: [...document.querySelectorAll('img')].filter((i) => !i.hasAttribute('alt')).map((i) => i.src).slice(0, 3),
					smallTargets: [...document.querySelectorAll('header a, header button, .jwt-filters a, .jw-form button')]
						.filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && (r.height < 40); })
						.map((el) => (el.textContent || el.getAttribute('aria-label') || el.tagName).trim().slice(0, 30)),
				}));
				const id = `${lang}-${width}-${name}`;
				const problems = [];
				if (status !== expected) problems.push(`HTTP ${status}`);
				if (check.overflow > 0) problems.push(`horizontal overflow ${check.overflow}px`);
				if (check.h1 < 1) problems.push('no h1');
				if (check.noAlt.length) problems.push(`img without alt: ${check.noAlt.join(', ')}`);
				if (lang === 'he' && check.dir !== 'rtl') problems.push(`dir=${check.dir}, expected rtl`);
				if (lang === 'en' && check.dir === 'rtl') problems.push('unexpected rtl');
				if (width <= 390 && check.smallTargets.length) problems.push(`touch targets < 40px: ${check.smallTargets.join(' | ')}`);
				// Scroll so lazy images load before the full-page screenshot.
				await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 600) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } window.scrollTo(0, 0); });
				await page.waitForLoadState('networkidle').catch(() => {});
				await page.screenshot({ path: path.join(OUT, `${id}.png`), fullPage: true });
				console.log(`${problems.length ? 'FAIL' : 'ok  '} ${id}${problems.length ? ' – ' + problems.join('; ') : ''}`);
				if (problems.length) failures.push(id);
			}
			// Mobile menu: opens, is keyboard reachable, closes on Escape.
			if (width <= 390) {
				await page.goto(BASE + prefix + '/', { waitUntil: 'load' });
				await page.click('.jwt-menu-toggle');
				const open = await page.isVisible('#jwt-nav .jwt-nav__list a');
				await page.keyboard.press('Escape');
				const closed = !(await page.isVisible('#jwt-nav .jwt-nav__list a'));
				const ok = open && closed;
				console.log(`${ok ? 'ok  ' : 'FAIL'} ${lang}-${width}-menu open=${open} closedOnEscape=${closed}`);
				if (!ok) failures.push(`${lang}-${width}-menu`);
			}
			await ctx.close();
		}
	}
	await browser.close();
	if (failures.length) {
		console.error(`\n${failures.length} visual check(s) failed`);
		process.exit(1);
	}
	console.log('\nAll visual checks passed.');
})();
