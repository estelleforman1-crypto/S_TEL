# Launch checklist

The current build is a **development build**. It must not take real orders until every "Required" item below is done.

## Owner inputs (required)

- [ ] Brand name, wordmark/logo files, domain name.
- [ ] Contact email and WhatsApp number (Atelier > Settings).
- [ ] Opening photograph, collection photographs, product galleries (portrait and landscape crops), maker portrait if wanted.
- [ ] Real products: names, descriptions, materials, care, sizes, prices, stock, production times, in all three languages. Delete every "Demo …" product and demo image.
- [ ] Wax/metal process description in your words (About page).
- [ ] Base currency and default language (decide the default language **before** writing content).
- [ ] Made-to-order deposit basis: full total with delivery, or items only. Then tick "I confirm" in Atelier > Settings.
- [ ] Bespoke deposit policy (entered per quote; decide your usual rule).
- [ ] Shipping: destinations, rates for ready pieces, carriers. Delete the "Israel (DEMO RATE)" zone.
- [ ] Pickup: location and arrangements, or leave off.
- [ ] Stock hold time; whether sold pieces stay visible.
- [ ] Policies: delivery, returns and cancellations (incl. made-to-order and bespoke), privacy, terms. Have them checked for Israeli consumer-protection law.
- [ ] Tax/VAT treatment with an accountant (WooCommerce taxes are currently off; quotes have no tax).
- [ ] Final email and interface wording in Hebrew, English and French (replace the draft .po translations).
- [ ] Named person responsible for updates, backups and incidents.

## Technical (required)

- [ ] Hosting with PHP 8.1+, MySQL/MariaDB, HTTPS, daily backups. Set `WP_ENVIRONMENT_TYPE` to `production`.
- [ ] `JW_PRIVATE_UPLOAD_DIR` set to a folder outside the web root; check that a reference photo URL is not reachable.
- [ ] Do **not** deploy `scripts/dev-mu-plugins/` (mail sandbox and DEMO gateway). It also switches itself off on production.
- [ ] Payment provider account (for example PayPlus) for an Israeli business, sandbox first. Run the tests in docs/commerce-validation.md "What to test when credentials exist". Check its HPOS compatibility.
- [ ] Transactional email delivery: SMTP or an email service, with SPF/DKIM on the domain. Send a test of each email type.
- [ ] Real cron (server cron calling `wp-cron.php`) so unpaid orders are cancelled on time.
- [ ] Backups tested by a restore on a staging copy.
- [ ] Shopify: export products/customers if wanted (access needed), then close the Shopify subscription.
- [ ] Final run of all tests and a manual review on a phone in Hebrew.
- [ ] Remove the development banner automatically (it disappears when `WP_ENVIRONMENT_TYPE` is `production`).

## Recurring costs (to confirm before buying anything)

No service has been bought. Prices change; get current quotes.

| Item | Needed? | Notes |
|---|---|---|
| Hosting (WordPress + MySQL + backups + HTTPS) | Yes | Managed or shared WordPress hosting. The largest fixed cost. Choose one with daily backups and staging. |
| Domain | Yes | Yearly renewal. |
| Transactional email | Yes | Many hosts include it; otherwise an email service (free tiers exist for low volume). |
| Payment provider | Yes | Per-transaction fees and possibly a monthly fee. Compare PayPlus with at least one other Israeli provider. |
| WordPress, WooCommerce, this theme and plugin | Yes | No license fee. |
| Multilingual plugin | No | Built in (decision D-007). |
| WooCommerce Deposits extension | No | Built in (decision D-003). |
| Backups to off-site storage | Recommended | Free plugin + cloud storage, or included in hosting. |
| Maintenance (updates, monitoring, fixes) | Yes | Time or a paid person/service. Not free even when the code has no license fee. |
