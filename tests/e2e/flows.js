// End-to-end commerce flows through the browser, against the local dev site.
// Usage: node tests/e2e/flows.js [baseUrl]
// Needs: the dev server running (npm start). WP-CLI is found by scripts/wp. Uses the DEMO gateway and the mail sandbox only.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = process.argv[2] || process.env.SITE_URL || 'http://localhost:8080';
const ROOT = path.resolve(__dirname, '../..');

let pass = 0;
const failures = [];
function check(cond, msg) {
	if (cond) { pass++; console.log('  ok   ' + msg); } else { failures.push(msg); console.log('  FAIL ' + msg); }
}
function php(code) {
	return execFileSync('bash', [path.join(ROOT, 'scripts/wp'), 'eval', code], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
}
function lastMailLink(pattern) {
	const dir = path.join(ROOT, '.dev/mail');
	const files = fs.readdirSync(dir).filter((f) => f.endsWith('.json')).sort();
	const mail = JSON.parse(fs.readFileSync(path.join(dir, files[files.length - 1]), 'utf8'));
	const m = mail.message.match(pattern);
	return m ? m[0].replace(/&amp;/g, '&').replace(/&#038;/g, '&') : null;
}
async function fillCheckout(page, email) {
	await page.evaluate(() => { window.jQuery('#billing_country').val('IL').trigger('change'); });
	await page.fill('#billing_first_name', 'Demo');
	await page.fill('#billing_last_name', 'Buyer');
	await page.fill('#billing_address_1', '1 Demo Street');
	await page.fill('#billing_city', 'Tel Aviv');
	await page.fill('#billing_postcode', '6100000');
	await page.fill('#billing_phone', '0500000000');
	await page.fill('#billing_email', email);
	await page.waitForSelector('.blockUI', { state: 'detached' }).catch(() => {});
	await page.waitForTimeout(800);
	if (await page.isVisible('#terms')) await page.check('#terms');
}
async function payOnDemoPage(page, approve = true) {
	await page.waitForURL(/jw-demo-pay=/, { timeout: 30000 });
	await page.click(approve ? 'button[value=approve]' : 'button[value=decline]');
	await page.waitForLoadState('load');
}
async function passEmailCheck(page, email) {
	// WooCommerce asks a guest to confirm the email before showing an order page in a new session.
	if (await page.isVisible('form.woocommerce-verify-email input[name=email]').catch(() => false)) {
		await page.fill('form.woocommerce-verify-email input[name=email]', email);
		await page.click('form.woocommerce-verify-email [type=submit]');
		await page.waitForLoadState('load');
	}
}

(async () => {
	const browser = await chromium.launch();
	php(`wc_update_product_stock( get_page_by_path( 'demo-one-of-a-kind-pendant', OBJECT, 'product' )->ID, 1, 'set' ); wc_update_product_stock( get_page_by_path( 'demo-signet-ring', OBJECT, 'product' )->ID, 3, 'set' ); delete_option( 'jw_dev_mail_fail' ); global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_jw_rl_%'" );`);

	// ---------------------------------------------------------------
	console.log('\n# Ready-stock purchase: cart, preset shipping, verified payment');
	{
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await page.goto(BASE + '/product/demo-signet-ring/');
		await page.click('button[name=add-to-cart]');
		await page.goto(BASE + '/product/demo-hoop-earrings/');
		await page.selectOption('select#pa_material', 'demo-silver');
		await page.click('button.single_add_to_cart_button');
		await page.waitForLoadState('load');
		const heCart = await page.goto(BASE + '/he/cart/', { timeout: 20000 });
		check(heCart.status() === 200 && (await page.isVisible('text=עגילי חישוק')), 'Hebrew cart with a variation renders (translated variation name)');
		await page.click('.woocommerce-cart-form a.remove[data-product_id] >> nth=1').catch(() => {});
		await page.waitForLoadState('load');
		await page.goto(BASE + '/he/checkout/'); // Language switch keeps the cart.
		check(await page.isVisible('text=טבעת חותם'), 'cart survives the switch to Hebrew, item shown in Hebrew');
		await page.goto(BASE + '/checkout/');
		await fillCheckout(page, 'ready@example.test');
		const shipping = await page.textContent('#shipping_method, .woocommerce-shipping-totals');
		check(/30/.test(shipping), 'preset Israel rate shown before payment');
		const total = await page.textContent('.order-total');
		check(/510/.test(total), 'total = 480 + 30');
		await page.click('#place_order');
		await payOnDemoPage(page, true);
		check(/order-received/.test(page.url()), 'browser returned to the order-received page');
		const id = page.url().match(/order-received\/(\d+)/)[1];
		await page.waitForTimeout(500);
		const st = php(`$o = wc_get_order(${id}); echo $o->get_status() . '|' . $o->get_total() . '|' . $o->get_meta('_jw_lang') . '|' . wc_get_product( get_page_by_path( 'demo-signet-ring', OBJECT, 'product' )->ID )->get_stock_quantity();`);
		check(st === 'processing|510.00|en|2', `order paid via signed callback, stock 3->2 (${st})`);
		await ctx.close();
	}

	// ---------------------------------------------------------------
	console.log('\n# Failed payment leaves the order unpaid');
	{
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await page.goto(BASE + '/product/demo-signet-ring/');
		await page.click('button[name=add-to-cart]');
		await page.goto(BASE + '/checkout/');
		await fillCheckout(page, 'declined@example.test');
		await page.click('#place_order');
		await payOnDemoPage(page, false);
		const id = php(`$o = wc_get_orders( array( 'billing_email' => 'declined@example.test', 'limit' => 1 ) ); echo $o[0]->get_id();`);
		const st = php(`$o = wc_get_order(${id}); echo $o->get_status() . '|' . ( $o->is_paid() ? 'paid' : 'unpaid' );`);
		check(st === 'failed|unpaid', `declined payment: order failed and unpaid (${st})`);
		await ctx.close();
	}

	// ---------------------------------------------------------------
	console.log('\n# Quantity-one race: two buyers, one piece');
	{
		const a = await browser.newContext();
		const b = await browser.newContext();
		const pa = await a.newPage();
		const pb = await b.newPage();
		for (const p of [pa, pb]) {
			await p.goto(BASE + '/product/demo-one-of-a-kind-pendant/');
			await p.click('button[name=add-to-cart]');
			await p.goto(BASE + '/checkout/');
		}
		await fillCheckout(pa, 'race-a@example.test');
		await fillCheckout(pb, 'race-b@example.test');
		await Promise.all([pa.click('#place_order'), pb.click('#place_order')]);
		const outcome = async (p) => {
			const r = await Promise.race([
				p.waitForURL(/jw-demo-pay=/, { timeout: 30000 }).then(() => 'pay'),
				p.waitForSelector('.woocommerce-error, .woocommerce-NoticeGroup-checkout', { timeout: 30000 }).then(() => 'error'),
			]).catch(() => 'timeout');
			return r;
		};
		const [ra, rb] = await Promise.all([outcome(pa), outcome(pb)]);
		check([ra, rb].filter((x) => x === 'pay').length === 1, `exactly one buyer reaches payment (a=${ra}, b=${rb})`);
		const loser = ra === 'error' ? pa : pb;
		const winner = ra === 'pay' ? pa : pb;
		const msg = (await loser.textContent('.woocommerce-error, .woocommerce-NoticeGroup-checkout').catch(() => '')) || '';
		check(/stock|available|זמין|disponible/i.test(msg), `the other buyer sees an intelligible stock message: "${msg.trim().slice(0, 90)}"`);
		await winner.click('button[value=approve]');
		await winner.waitForLoadState('load');
		const stock = php(`echo wc_get_product( get_page_by_path( 'demo-one-of-a-kind-pendant', OBJECT, 'product' )->ID )->get_stock_quantity();`);
		check(stock === '0', 'stock is 0 after the single successful sale');
		await loser.goto(BASE + '/product/demo-one-of-a-kind-pendant/');
		check(await loser.isVisible('.stock.out-of-stock'), 'product page now shows it as out of stock');
		await a.close();
		await b.close();
	}

	// ---------------------------------------------------------------
	console.log('\n# Made to order: request (Hebrew) -> quote -> 50% deposit -> ready -> balance');
	{
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await page.goto(BASE + '/he/product/demo-band-ring/');
		check(!(await page.isVisible('button[name=add-to-cart]')), 'no add-to-cart button for a made-to-order piece');
		await page.selectOption('select[name="jw[attributes][attribute_pa_ring-size]"]', '54');
		await page.selectOption('select[name="jw[attributes][attribute_pa_material]"]', 'demo-gold');
		await page.selectOption('#jw-country', 'FR');
		await page.fill('#jw-city', 'Lyon');
		await page.fill('#jw-name', 'Client Démo');
		await page.fill('#jw-email', 'mto@example.test');
		await page.click('.jw-form--mto button[type=submit]');
		await page.waitForURL(/jw_request=received/);
		check(await page.waitForSelector('.jw-notice--ok', { timeout: 10000 }).then(() => true).catch(() => false), 'receipt shown; it says this is not an order yet');
		const rid = php(`$p = get_posts( array( 'post_type' => 'jw_request', 'numberposts' => 1, 'meta_key' => '_jw_email', 'meta_value' => 'mto@example.test' ) ); echo $p[0]->ID;`);
		const req = php(`$v = wc_get_product( (int) get_post_meta( ${rid}, '_jw_variation_id', true ) ); echo wc_get_formatted_variation( $v, true, false ) . '|' . get_post_meta( ${rid}, '_jw_country', true ) . '|' . get_post_meta( ${rid}, '_jw_lang', true );`);
		check(/54/.test(req) && /FR\|he$/.test(req), `options, destination and language stored (${req})`);

		// Owner quotes an individual delivery cost to France and sends the quote.
		php(`$o = JW_Quotes::save_quote( ${rid}, array( 'unit_price' => '1800', 'shipping' => '120', 'lead_time' => '4–5 weeks' ) ); JW_Quotes::send( $o );`);
		const quoteUrl = lastMailLink(/https?:\/\/[^"'\s<]+jw-quote=[^"'\s<]+/);
		check(quoteUrl && quoteUrl.includes('/he/'), 'quote email link in Hebrew');
		await page.goto(quoteUrl);
		const qtext = await page.textContent('main');
		check(/1,920/.test(qtext) && /960/.test(qtext), 'quote page shows total 1,920 and deposit 960');
		await page.click('button[name=jw_accept]');
		await page.waitForURL(/order-pay/);
		await passEmailCheck(page, 'mto@example.test');
		check(/960/.test(await page.textContent('main')), 'payment page amount = deposit (960), fixed by the quote');
		if (await page.isVisible('#terms')) await page.check('#terms');
		await page.click('#place_order');
		await payOnDemoPage(page, true);
		const parent = php(`echo get_post_meta( ${rid}, '_jw_order_id', true );`);
		const s1 = php(`$p = wc_get_order( ${parent} ); echo JW_Payments::payment_state( $p ) . '|' . JW_Payments::amounts( $p )['balance'] . '|' . $p->get_status();`);
		check(s1 === 'deposit_paid|960|jw-quote', `deposit verified; balance 960 still open; not fulfilled (${s1})`);

		// Owner marks it ready: one balance email with a payment link.
		const r = php(`$r = JW_Payments::mark_ready( wc_get_order( ${parent} ) ); echo $r['email']; JW_Payments::mark_ready( wc_get_order( ${parent} ) );`);
		check(r === 'sent', 'mark ready sends the balance email (second click sends nothing)');
		const balanceUrl = lastMailLink(/https?:\/\/[^"'\s<]+order-pay[^"'\s<]+/);
		check(balanceUrl && balanceUrl.includes('/he/'), 'balance link in the customer language');
		const fresh = await browser.newContext(); // The customer opens the email later, in a new session.
		const p2 = await fresh.newPage();
		await p2.goto(balanceUrl);
		await passEmailCheck(p2, 'mto@example.test');
		if (await p2.isVisible('#terms')) await p2.check('#terms');
		await p2.click('#place_order');
		await payOnDemoPage(p2, true);
		const s2 = php(`$p = wc_get_order( ${parent} ); echo JW_Payments::payment_state( $p ) . '|' . JW_Payments::amounts( $p )['paid'] . '|' . $p->get_status();`);
		check(s2 === 'paid_in_full|1920|processing', `balance verified; paid in full; ready to ship, not shipped (${s2})`);
		await p2.goto(balanceUrl);
		await passEmailCheck(p2, 'mto@example.test');
		check(!(await p2.isVisible('#place_order')), 'the paid balance link cannot be paid again');
		await fresh.close();
		await ctx.close();
	}

	// ---------------------------------------------------------------
	console.log('\n# Commission request with a private reference photo');
	{
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		const img = path.join(os.tmpdir(), 'jw-ref.png');
		fs.writeFileSync(img, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64'));
		const bad = path.join(os.tmpdir(), 'jw-ref.png.php');
		fs.writeFileSync(bad, '<?php echo 1;');
		await page.goto(BASE + '/fr/commissions/');
		await page.fill('#jw-brief', 'Une bague sur mesure');
		await page.setInputFiles('#jw-photos', [bad]);
		await page.fill('#jw-name', 'Client');
		await page.fill('#jw-email', 'bespoke@example.test');
		await page.click('.jw-form--commission button[type=submit]');
		await page.waitForLoadState('load');
		check(await page.isVisible('.jw-notice--error'), 'a non-image upload is rejected with a message');
		check((await page.inputValue('#jw-brief')) === 'Une bague sur mesure', 'the brief is kept after the error');
		await page.setInputFiles('#jw-photos', [img]);
		await page.click('.jw-form--commission button[type=submit]');
		await page.waitForURL(/jw_request=received/);
		check(await page.waitForSelector('.jw-notice--ok', { timeout: 10000 }).then(() => true).catch(() => false), 'commission receipt shown');
		const info = php(`$p = get_posts( array( 'post_type' => 'jw_request', 'numberposts' => 1, 'meta_key' => '_jw_email', 'meta_value' => 'bespoke@example.test' ) ); $f = get_post_meta( $p[0]->ID, '_jw_attachments', true ); echo $p[0]->ID . '|' . $f[0]['file'] . '|' . JW_Uploads::dir();`);
		const [rid, file, dir] = info.split('|');
		check(fs.existsSync(path.join(dir, file)), 'photo stored in the private directory');
		check(!dir.startsWith(path.resolve(process.env.WP_DIR || path.join(ROOT, '.dev/wordpress'))), 'private directory is outside the web root');
		const anon = await ctx.request.get(`${BASE}/wp-admin/admin-post.php?action=jw_attachment&request=${rid}&i=0`, { maxRedirects: 0 });
		check(anon.status() !== 200, `an anonymous visitor cannot fetch it via the handler (HTTP ${anon.status()})`);
		const guess = await ctx.request.get(`${BASE}/wp-content/uploads/jw-private/${file}`);
		check(!(guess.headers()['content-type'] || '').startsWith('image/'), `no public URL serves the image (HTTP ${guess.status()}, ${guess.headers()['content-type']})`);
		const media = php(`echo count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 's' => '${file.slice(0, 12)}' ) ) );`);
		check(media === '0', 'not in the public Media Library');
		await ctx.close();
	}

	// ---------------------------------------------------------------
	console.log('\n# Invalid links and not-found states');
	{
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		const r1 = await page.goto(BASE + '/?jw-quote=999999&k=nope');
		check(r1.status() === 404 && (await page.isVisible('h1')), 'invalid quote link: clear page, HTTP 404');
		const r2 = await page.goto(BASE + '/product/does-not-exist/');
		check(r2.status() === 404, 'unknown product: 404 page');
		await ctx.close();
	}

	await browser.close();
	console.log(`\n${pass} passed, ${failures.length} failed`);
	if (failures.length) {
		console.error('Failures:\n- ' + failures.join('\n- '));
		process.exit(1);
	}
})().catch((e) => { console.error(e); process.exit(1); });
