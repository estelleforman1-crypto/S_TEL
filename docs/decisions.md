# Decisions log

Labels follow the GDD: **Confirmed** (chosen by the owner), **Proposed baseline** (implementation choice, reversible, not owner-confirmed), **Unresolved** (information not supplied; placeholder or disabled).

Later explicit owner decisions override this log. Add a new entry when a decision changes. Do not edit old entries; mark them superseded.

## Decisions

| ID | Date | Status | Decision | Reason / consequence |
|---|---|---|---|---|
| D-001 | 2026-11-18 | Confirmed (GDD) | WooCommerce for commerce. No custom commerce engine. No visual page builder. | From the GDD. |
| D-002 | 2026-11-18 | Proposed baseline | Classic custom theme + project plugin, not a separate React/Next.js storefront. | GDD default. Keeps native cart, checkout, sessions and gateway plugins. The repository was empty, so there was no earlier work to keep. |
| D-003 | 2026-11-18 | Proposed baseline | Deposits and balances are separate WooCommerce orders ("payment requests") linked to the quote order by `parent_id`, not the paid WooCommerce Deposits extension. | Works with any gateway through the native "order-pay" page. Payments and refunds stay WooCommerce records; paid and outstanding amounts are recomputed from them, so there is no second ledger. Avoids a paid annual license and the unclear reminder behavior noted in the GDD. Consequence: the order list shows the payment requests as separate orders, labeled "Deposit for order #N". The GDD asked to select a deposits integration "after a compatibility test": this is that choice; revisit if the owner prefers the extension. |
| D-004 | 2026-11-18 | Proposed baseline | A quote is a WooCommerce order in the custom status "Commission open". It cannot be paid in full by link. Quote price is a snapshot. | Later product price edits do not change an accepted quote. |
| D-005 | 2026-11-18 | Proposed baseline | Four separate dimensions: quote state, production state, payment state (computed), fulfillment (WooCommerce status). "Processing" means paid in full and ready to ship; "Completed" means shipped or collected. A quote order cannot move to Processing or Completed while a balance is unpaid. | GDD §5. |
| D-006 | 2026-11-18 | Proposed baseline | Mixed orders: ready-stock items go through the cart; made-to-order items are not purchasable through the cart (request form instead). | GDD §4.D. Made-to-order products cannot enter the cart, so a ready item is never held while a commission is discussed. One quote order is one fulfillment group: the balance is requested once, when the whole order is ready. |
| D-007 | 2026-11-18 | Proposed baseline | Three languages with a small built-in layer, not Polylang/WPML/TranslatePress. Default language without URL prefix, others under `/he/` and `/fr/`. Translated text is stored as post meta and term meta. One product, one stock, one price. | No license cost. Free versions of the common plugins do not cover WooCommerce products in three languages. Consequences: slugs are shared between languages; SEO plugins do not see the translations as separate pages (hreflang tags are output by the theme); interface strings come from WordPress/WooCommerce language packs plus our .po files. Revisit if the owner wants translated URLs. |
| D-008 | 2026-11-18 | Proposed baseline | Classic shortcode cart and checkout pages (`[woocommerce_cart]`, `[woocommerce_checkout]`), not the block checkout. | Styled by the theme. Gateways without block support still work. |
| D-009 | 2026-11-18 | Proposed baseline | Guest checkout. Accounts are optional. Reviews off. No wishlist, newsletter or search. | GDD §3. |
| D-010 | 2026-11-18 | Proposed baseline | Late payment guard: a payment that arrives after the stock hold expired and the piece was sold, or after the order was cancelled, does not reduce stock again and is flagged for the owner ("Needs attention" in Atelier). | WooCommerce would otherwise sell the last unit twice (stock −1). Found during testing. |
| D-011 | 2026-11-18 | Proposed baseline | Private commission photos: random file names, stored outside the web root (`JW_PRIVATE_UPLOAD_DIR`), shown only to logged-in shop managers through an authenticated handler. JPEG, PNG, WebP only; default 5 files of 8 MB; 5 form submissions per hour per visitor. | GDD §8. Limits are in Atelier > Settings. |
| D-012 | 2026-11-18 | Proposed baseline | No automatic tax on quotes; WooCommerce taxes off. | Tax rules are unresolved. Must be decided with an accountant before launch. |
| D-013 | 2026-11-18 | Proposed baseline | Development defaults: currency ILS, base country Israel, default language English, stock hold 15 minutes, sold pieces stay visible as "Sold", one demo shipping zone (Israel, ₪30 demo rate). Other countries have no shipping method, so they cannot check out (never free shipping). | All are labeled development defaults, not owner decisions. |
| D-014 | 2026-11-18 | Proposed baseline | Made-to-order deposit basis: 50% of the full quoted total incl. delivery, as a **labeled demo assumption**. On a production site, made-to-order quotes cannot be sent until the owner confirms the basis in Atelier > Settings. | GDD §4.B. |
| D-015 | 2026-11-18 | Proposed baseline | System fonts (Helvetica/Arial family with Hebrew fallbacks), no web fonts. Monochrome palette. | Fast and free. Replace with the brand typeface when supplied. |

## Design reference record

The Chanel Israel homepage could not be rendered in a headless browser from this environment (the site returned HTTP 403 "Access Denied" to automated browsers). The server HTML was readable. Patterns recorded from it and used:

- A sequence of full-width image blocks ("sliding hero" components). Each has a small category label, a short title and one "See more" / "Discover" link.
- Centered wordmark, a single row of top-level navigation, sans-serif type (Helvetica family), uppercase short headings, black on white.
- Footer with link groups (services, legal) and a location/language switcher.

Not reproduced: Chanel logos, images, copy, mega-menus, newsletter, store locator, autoplay video. This is **not** a pixel-accurate audit. A human visual review against the live site on desktop and mobile is still recommended.

## Unresolved (placeholders in the build)

Brand name and logo · real photographs and product text · wax/metal technique description · currency and default language for launch · payment provider and account · made-to-order deposit basis · bespoke deposit policy (entered per quote) · shipping zones, rates and pickup details · stock hold time · whether sold pieces stay visible · lead times and sizing · returns, cancellations, privacy and terms text · tax · hosting, domain, mail delivery, backups · maintenance owner · Shopify data export.

## Known limitations

- **Payment provider not integrated.** Flows were tested with the DEMO gateway. PayPlus (candidate) activates without errors next to this plugin, calls `payment_complete()` and supports the order-pay page, but it does **not** declare HPOS compatibility and was not tested with transactions (no sandbox credentials). See docs/commerce-validation.md.
- **Guest payment links** open WooCommerce's email confirmation step when the customer opens the link in a new browser session. This is a WooCommerce security feature.
- **Order list**: payment requests appear as separate orders. The owner works from the Atelier overview and the "Commission workflow" panel.
- **Order lookups in Atelier** filter in PHP (fine for a small shop). Switch to `meta_query` when only HPOS storage is used.
- **Translations** in `languages/*.po` are drafts for review. The owner must approve or rewrite them. WooCommerce's own strings come from its language packs.
- **Concurrent late payments** for the same last unit within milliseconds of each other are not serialized beyond WooCommerce's own stock update. Normal concurrent checkout is protected by WooCommerce's stock reservation (tested).
- The SQLite development database is not the production database. CI runs the same tests on MySQL 8.
