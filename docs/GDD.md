# Jewelry Store: Design and Development Document

Version 1.0 · 18 November 2026

**Purpose:** A practical specification for an AI developer to build the website in a GitHub repository. This is a website product and technical design document, called the GDD for this project. It is not an instruction to build a game.

**Working title:** Jewelry Store. The brand name has not been provided.

## 1. Product direction

Create a shopping-focused website for an independent jewelry maker based in Israel. She sells ready-to-ship pieces, one-of-a-kind work, made-to-order designs, and bespoke commissions. Her work involves wax and metal. Whether wax is a finished material or part of a casting process is unresolved. Do not invent descriptions of her technique.

The website should use the Chanel Israel homepage as its main design reference: https://www.chanel.com/il-en/. Translate its image-led presentation and restrained content into a small jewelry shop using the maker's own brand and imagery.

The owner wants control over the site's code and design through her own GitHub repository. WooCommerce should handle commerce. There will be no custom visual website editor or editor freedom selector. Design changes happen in code, including through AI-assisted development. Routine product and order management remains available through an administrative interface.

## 2. Authority and decision status

Use this document as the initial source of truth. Later explicit owner decisions override it. Preserve accepted decisions during iteration and record substantive changes in a decision log. Existing code demonstrates current behavior, not necessarily intended behavior.

Status labels have distinct meanings:

- **Confirmed:** explicitly chosen by the user during discovery.
- **Proposed baseline:** an implementation or design recommendation that makes the brief actionable. Proceed with reversible baseline choices and record them, but never describe them as owner-confirmed.
- **Unresolved:** information not yet supplied. Use explicit development placeholders or disabled configuration. Do not invent production facts.

### Confirmed requirements

| Area | Decision |
|---|---|
| Business | Jewelry maker based in Israel |
| Initial catalog | Up to 20 product listings |
| Sales | In-stock designs, one-of-a-kind pieces, made-to-order designs, bespoke commissions |
| Website purpose | Browsing and purchasing jewelry |
| Ownership | Most project-specific implementation belongs in her GitHub repo |
| Commerce | WooCommerce |
| Website editing | No visual page builder or custom editor |
| Administration | One dashboard for orders, commissions, statuses, and customer details |
| Owner's comfort | Visual tools, step-by-step instructions, and AI-assisted code changes |
| Management device | Mainly a computer |
| Languages | Hebrew, English, French. Owner writes the content in each language |
| Ready stock | Full online payment with automatic stock reservation |
| Made-to-order | One shared 50% deposit rule, remaining balance before shipping or handover |
| Bespoke | Request/discussion, quote, deposit, remaining balance before shipping or handover |
| Balance request | Marking a piece ready automatically emails a payment link |
| Ready-stock shipping | Preset prices |
| Other shipping | Individual shipping quote before initial payment |
| Commission entry | Photo-upload request form, email/WhatsApp conversation, and configurable products where appropriate |
| Budget | Running costs as close to free as practical |
| Design reference | Chanel Israel homepage |

Domestic shipping, international shipping, and local pickup are desired possibilities. Actual launch destinations and pickup arrangements are not confirmed. The current Shopify shop is private or not live. Its configuration, data quality, and subscription status have not been inspected.

### Rejected or superseded

- Custom visual page editor, drag-and-drop builder, and Simple/Layout/Advanced freedom selector.
- Shopify as the chosen commerce backend. The current direction is WooCommerce.
- Building a complete custom commerce engine or duplicating WooCommerce's product/order database.
- Preset shipping rates for every order, or individually quoted shipping for every order. The final rule depends on sale type.
- Requiring the owner to use GitHub for routine price, stock, product-photo, or order updates.

### Proposed technical baseline

Use a custom code-driven WordPress/WooCommerce theme plus a small project plugin. This was recommended in discussion, but the user did not explicitly select a theme over a separate API-driven frontend. Treat the integrated theme as the default because it keeps native cart, checkout, sessions, and payment extensions together while retaining control over presentation.

Do not quietly switch to a separate React/Next.js storefront. If an existing repo already uses one, inspect it, explain the implications, and preserve existing work unless a change is justified. The brief's user-facing requirements remain the same either way.

## 3. Experience and visual design

### Reference interpretation

The inspected Chanel page presents successive collection images, short headings, and simple discovery links. A full visual and interaction audit has not yet been performed. Before implementing, inspect the live reference on desktop and mobile if browser access is available. Record the useful patterns. If access is blocked, use the direction below and report the limitation instead of claiming a pixel-accurate reproduction.

**Proposed visual direction:** a restrained monochrome interface, prominent wordmark, generous space, image-led collection panels, precise typography, and subtle interactions. These are design proposals, not a verified inventory of every detail on the reference page.

Use the maker's brand, photography, copy, and products. Do not import Chanel logos, product images, campaign text, or brand identifiers. A custom theme need not look like a standard WordPress template.

### Design principles

1. Jewelry and photographs carry the visual emphasis. Avoid excessive cards, decorative badges, gradients, and invented luxury copy.
2. Keep shopping clear. Price, availability, size/material choices, production time, and payment expectations must be easy to find.
3. Scale the reference to a small catalog. A few strong sections are preferable to many empty collection panels.
4. Make the website usable on phones even though the owner manages it on a computer.
5. Treat Hebrew as a complete right-to-left layout. Preserve correct number, price, email, and mixed-language rendering.
6. Use motion sparingly. Respect reduced-motion preferences. Do not require autoplay video to communicate essential information.

### Proposed homepage order

| Section | Purpose and behavior |
|---|---|
| Header | Brand wordmark, Shop, Collections, Commissions, About, language selector, shopping bag |
| Opening image | One strong photograph, brief collection title, clear link to shop |
| Available pieces | A compact product selection with prices and sale/availability information |
| Collection panels | Large editorial photographs linking to meaningful product groups |
| Made-to-order / commissions | Short explanation and direct route to the appropriate ordering process |
| Maker introduction | Brief factual introduction and link to About |
| Footer | Contact, delivery information, care, policies, and language links |

All section names and order above are proposed. Do not invent empty collections, testimonials, press coverage, sustainability claims, or mailing-list functionality to fill space.

### Required pages

- Home.
- Shop and collection listings. Useful filters based on real catalog data. Avoid a large filter system for 20 items.
- Product details with a gallery, price or truthful quote requirement, description, options, material/care information, lead time where applicable, and correct purchase action.
- Commission request page with reference-photo upload.
- About and contact.
- Cart, checkout, order confirmation, and remaining-balance payment pages.
- Delivery/pickup information, returns/cancellations, privacy, and other owner-approved policy content needed for launch.
- Clear unavailable-product, invalid-payment-link, and not-found states.

Proposed default: guest checkout. Customer accounts are optional and must not block ordering. Wishlist, loyalty, subscriptions, reviews, marketing automation, and elaborate search are outside initial scope unless separately requested.

### Responsive and accessibility acceptance

Verify representative layouts at 360, 390, 768, and 1440 CSS pixels. These are test widths, not fixed designs. No accidental horizontal scrolling. Mobile controls should have comfortable touch targets, approximately 44 px where practical. Support keyboard navigation, visible focus, semantic headings, labeled controls, accessible validation messages, sufficient contrast, and meaningful alt text. Galleries and menus must work without hover.

Use responsive images with explicit dimensions. Load below-the-fold media lazily and prioritize the actual opening image. Prefer real mobile crops to cutting the jewelry out of the frame.

## 4. Sales and checkout flows

### A. Ready-to-ship and one-of-a-kind pieces

Browse → choose available variation → add to cart → select enabled delivery/pickup option → see preset shipping and full total → pay → receive confirmation.

Use WooCommerce's authoritative inventory. Reserve finite stock during payment using an appropriate temporary hold, then commit the stock change on verified payment. Release stock according to the configured failure/expiry policy. Adding an item to the bag must not reserve it indefinitely.

For a quantity-one product, two concurrent buyers must not both complete successful orders for the same stock. Show an intelligible sold-out or stock-changed message to the unsuccessful buyer. Do not rely only on a disabled frontend button.

**Unresolved:** stock-hold duration, treatment of delayed payment callbacks, and whether sold pieces remain visible as an archive. Implement configurable behavior and test it.

### B. Made-to-order pieces

Browse → choose size/material options → provide destination or request pickup → submit order request → owner quotes delivery and confirms the total → customer accepts and pays 50% deposit → production → owner marks piece ready → automatic email requesting the balance → customer pays → shipping/handover.

Because shipping is quoted before payment, initial submission is a request, not a completed purchase. Preserve the chosen product, variation, quantity, destination, and quoted terms. The payment link must refer to the accepted quote, not silently use a later edited product price.

Clearly show the full agreed total, amount due now, and remaining balance before initial payment. Do not imply immediate dispatch for made-to-order work.

**Unresolved:** whether 50% applies to merchandise only or the entire tax/shipping-inclusive total. Keep the calculation explicit and configurable. For development fixtures only, use half of the final quoted total and label that as a demo assumption. Live deposits remain disabled until the owner confirms the basis.

### C. Bespoke commissions

Customer submits a brief with optional reference photos or starts an email/WhatsApp conversation → owner records the request in the dashboard → discussion → owner issues a quote including delivery/pickup details and deposit terms → customer accepts and pays → production → ready → automatic balance-link email → balance paid → shipping/handover.

Proposed request fields: name, email, optional phone/contact preference, brief, optional relevant size/material preferences, destination when delivery is needed, optional desired date, and optional photos. Do not require every field when it is irrelevant. A desired date is a request, not a delivery promise.

For conversations originating outside the form, provide a manual request-entry action in the same admin area. Do not imply WhatsApp or email conversations automatically synchronize. Contact buttons open the owner's configured contact channel.

The form must confirm receipt without saying a commission has been accepted. Quotes should contain scope, item/options, price, delivery cost, lead time, deposit terms, and version. Quote expiry and revision rules are configurable, not invented promises.

**Unresolved:** bespoke deposit percentage or amount. Do not automatically apply the 50% made-to-order rule to bespoke work. Permit an explicit deposit per accepted quote.

Configurable designs that can be specified by product options should use the made-to-order flow. Open-ended requests use the bespoke flow. Do not make an unknown commission purchasable at a fabricated fixed price.

### D. Mixed order handling

**Proposed launch rule:** ready-stock checkout and quote-required requests use separate submissions. A ready-stock item should not be held indefinitely while an unrelated commission is discussed. Explain the separation clearly and preserve the customer's selections. Record this as an implementation baseline, not an owner-confirmed preference.

Multi-piece production readiness is unresolved. Proposed launch behavior: treat a quoted made-to-order or bespoke order as one fulfillment group and request its balance only when all pieces in that group are ready. Do not generate separate full-balance requests for each piece.

## 5. Order management and automation

Extend WordPress/WooCommerce administration so the owner can reach products, requests, quotes, orders, customer details, and outstanding balances from one workspace. A standalone admin application is not required.

Keep production progress distinct from financial status and shipment status. A paid deposit is not a paid-in-full order, and a ready piece is not a shipped piece.

| Dimension | Illustrative states |
|---|---|
| Request / quote | New, needs clarification, quote sent, accepted, declined, expired |
| Production | Not started, in production, ready |
| Payment | Unpaid, deposit paid, balance due, paid in full, failed, refunded/partially refunded |
| Fulfillment | Not dispatched, shipped, collected, cancelled |

These labels are proposals. Map them cleanly onto WooCommerce's supported order and payment records rather than replacing its financial logic with a second ledger.

### Mark-ready action

1. Confirm the order/fulfillment group is eligible and calculate the actual unpaid balance from authoritative records.
2. Mark production ready without marking the whole order paid or fulfilled.
3. Generate or reuse one balance-payment request linked to the original order and quote.
4. Queue an email in the customer's recorded language containing the amount and payment link.
5. Record generation and delivery attempts in the admin timeline.
6. If the action is repeated, reuse the valid request. Never create duplicate payable balances or silently send duplicate emails.
7. If email delivery fails, surface the failure and offer retry/copy-link actions. A recorded send attempt is not proof of delivery.
8. On verified balance payment, update the order and enable fulfillment. Do not automatically ship it.

A link should not permit changing the amount or accessing unrelated orders. Handle already-paid, expired, cancelled, and replaced links gracefully. Verified gateway events determine payment success, never a browser return URL alone.

### Communications

Support order confirmations, request receipts, quotes/payment requests, deposit confirmations, ready/balance requests, and fulfillment updates. The owner supplies final text in all three languages. Use a mail sandbox during development. Do not send real emails or WhatsApp messages while testing.

### Shipping and pickup

Ready stock uses configured destination rates. Other shipped work receives a quoted delivery amount before initial payment. Pickup may be enabled only when location, contact, and arrangements are supplied. Never offer an unconfigured country free shipping. Do not assume all countries, all currencies, or tax rules from the owner's location alone.

## 6. Architecture and repository

### Proposed implementation

- WordPress and WooCommerce on hosting that supports their runtime and database.
- A custom classic theme with code-owned templates and styling. No visual builder dependency.
- A project plugin for commission requests, quote metadata, workflow rules, production readiness, and integrations.
- A verified deposits extension or equivalent supported WooCommerce integration. Select it after a compatibility test.
- A payment gateway supporting the owner's Israeli business and chosen currency.
- A multilingual implementation selected after testing products, variation identifiers, URLs, checkout, emails, and Hebrew RTL behavior.

GitHub stores source and reproducible setup, not the live database. Hosting, backups, the domain, mail delivery, and payment-provider accounts still exist outside the repo. Keep customer records, private reference photos, database dumps, credentials, and real order data out of version control.

### Proposed repository paths

| Path | Responsibility |
|---|---|
| `README.md` | Local startup, development, deployment, owner operations, known limitations |
| `docs/GDD.md` | This specification |
| `docs/decisions.md` | Confirmed changes, implementation choices, unresolved decisions |
| `docs/commerce-validation.md` | Exact tested versions, payment/deposit results, open issues |
| `docs/owner-guide.md` | Product entry, quotes, readiness, balances, refunds, updates |
| `docs/launch-checklist.md` | Production inputs and launch verification |
| `theme/jewelry-store/` | Custom theme, templates, assets, locale resources |
| `plugins/jewelry-workflows/` | Business workflow extension, admin UI, integration code |
| `scripts/` | Reproducible setup, development seed data, deployment helpers |
| `tests/` | Financial, stock, workflow, language, and end-to-end checks |
| `.env.example` | Variable names and safe example values only |

Adapt paths to an existing repo when necessary. Do not overwrite unrelated work or copy WordPress/WooCommerce core into custom project code. Pin or record the versions actually tested. Document any paid dependency and its license requirement. A normal checkout flow must remain operable independently of decorative animations.

### Authoritative data

WooCommerce owns product prices, inventory, variations, orders, taxes, shipping totals, and payment records. The project plugin adds workflow data using supported APIs. Avoid direct assumptions about legacy order tables. Preserve compatibility with the selected WooCommerce order storage mode.

Request and quote records should identify the linked customer/order, language, selected product/variation or bespoke description, quote revision, delivery option, totals, deposit rule, paid amounts, readiness, and communication history. Attachments need controlled access. Do not duplicate complete customer profiles across independent systems.

## 7. Languages, content, and assets

Support Hebrew, English, and French from the first implementation. The owner writes each language. Do not silently machine-translate final product or policy copy. Draft UI translations may be supplied as clearly marked review material.

Locale changes should retain the equivalent product/page and shopping context when possible. Translate titles and descriptions without creating separate inventory for each language. Prices, availability, variants, and quote identifiers must stay consistent. Record the customer's chosen communication language on requests and orders.

The default language, currency, and URL scheme are unresolved. Proposed URL format: language-prefixed routes. Use English and ILS only as explicitly labeled local development defaults, with no implication that multi-currency selling has been approved.

Provide an asset checklist: brand name/wordmark, opening photograph, collection photographs, product galleries, detail shots, maker portrait if wanted, and complete factual product descriptions. Use neutral, obviously labeled demo assets when real assets are unavailable. Do not present demonstration products or sales as genuine.

## 8. Reliability and security requirements

Use WooCommerce and the chosen gateway's supported mechanisms for checkout, payment verification, refunds, and stock handling. Never store raw card details. Verify webhook signatures/authentication and make retries safe. Validate prices, balances, stock, and permissions on the server.

Protect admin changes with appropriate capabilities and request validation. Sanitize inputs and escape rendered output. Apply reasonable form throttling and upload controls. Accept only intended image formats, impose configurable file/count limits, and keep commission reference files private. A public media URL must not expose a customer's private reference upload.

Use a real database and commerce service in integration tests. Mock gateways and seed products are appropriate for local development only when clearly labeled. Never show a simulated payment as a production success.

Record recoverable failures and give the owner a clear action for failed mail, payment reconciliation, expired quotes, and unavailable products. Keep logs free of card data and unnecessary personal information. Include backup and restore instructions for both files and the database.

## 9. Implementation sequence

### Phase 1: Establish the repo and validate commerce

Inspect the existing repo and any applicable project instructions. If none exists, scaffold the proposed theme/plugin structure with reproducible WordPress/WooCommerce setup. Carry forward confirmed behavior and document the proposed baseline.

Before polishing the storefront, demonstrate one ready-stock purchase, one 50% made-to-order deposit with quoted shipping, and one bespoke quote with its own deposit rule. Demonstrate balance-link generation and payment after marking ready. Use test-mode payments and sandbox mail.

Verify a supported Israeli payment provider, deposits implementation, and multilingual strategy together. PayPlus is a candidate from prior research, not a selected provider. Do not assume that generic plugin compatibility proves this combination works.

If provider credentials or paid licenses are missing, continue with all independent implementation and provide an explicit integration blocker. Do not silently replace the required flow, declare production readiness, purchase services, or activate live payments.

### Phase 2: Implement the visual experience

Inspect the Chanel reference, build the homepage and product/listing templates, and connect them to real WooCommerce product records. Implement mobile layouts, language navigation, RTL, forms, galleries, cart, and checkout styling. Keep commerce behavior intact while customizing presentation.

### Phase 3: Complete owner workflows

Implement requests, manual entry, quote revisions, deposit tracking, readiness, balance email, and fulfillment gating in the admin workspace. Add clear error and recovery states. Complete owner instructions.

### Phase 4: Validate and hand over

Run the acceptance checks, provide desktop/mobile screenshots and test results, document recurring costs and unresolved inputs, and deliver a reproducible repo. A working development build is a valid handoff while production inputs are missing, but it must be labeled accordingly.

## 10. Acceptance tests

| Test | Required result |
|---|---|
| Ready-stock purchase | Correct variation, quantity, preset shipping, and full total reach checkout and the order |
| Quantity-one race | Concurrent buyers cannot both successfully buy the same single item |
| Abandoned/failed payment | Stock hold follows configured release policy and order stays unpaid |
| Late or duplicate callback | Payment is reconciled once, no duplicate stock change or confirmation |
| Made-to-order quote | Selected options and destination survive submission, no payment before shipping quote |
| 50% deposit | Due-now and remaining amounts match the configured basis and accepted quote |
| Bespoke deposit | The quote's explicit amount is used, not an assumed 50% |
| Mark ready | Generates one valid balance request and queues one localized email |
| Repeated ready action | Does not create duplicate balances or unwanted repeated emails |
| Mail failure | Failure is visible and retry can reuse the same payment request |
| Balance payment | Verified payment reduces the outstanding amount correctly and permits fulfillment |
| Unpaid balance | Standard fulfillment action is blocked or explicitly prevented by the workflow |
| Quote revision/cancellation | Old links cannot collect an obsolete or cancelled quote without reconciliation |
| Refund | Paid/refunded/outstanding totals remain consistent and stock behavior is explicit |
| Mixed order types | Separate checkout/request behavior is clear and selections are preserved |
| Language change | Same product and inventory, appropriate content, no broken cart or payment links |
| Hebrew/mobile | Correct RTL, readable mixed content, functional menus/forms, no overflow |
| Private upload | Another visitor cannot retrieve private commission images |
| Missing production data | No invented rates, contact details, policies, materials, or active live checkout |
| Reproducible setup | A fresh checkout of the repo can run locally from documented instructions |

Tests are implementation requirements, not claims that this document has already validated a running shop.

## 11. Open inputs and launch gates

| Input | Handling before supplied |
|---|---|
| Brand name, logo, domain, contact channels | Explicit placeholders in development, no fabricated identity |
| Real images, product details, wax process | Demo assets and neutral descriptions only |
| Base currency, default language | Configurable demo defaults, owner confirmation for launch |
| Payment provider/account and credentials | Sandbox or clearly labeled mock integration |
| Made-to-order deposit calculation basis | Explicit demo assumption, live deposits disabled |
| Bespoke deposit policy | Required per-quote entry before a payable quote is issued |
| Shipping zones/rates and pickup details | Unconfigured destinations cannot proceed as free shipping |
| Lead times, sizing, returns, cancellations, tax and policy content | Owner-supplied configuration/content, no invented legal or business claims |
| Hosting, mail delivery, domain and backups | Document required services and costs |
| Maintenance responsibility | Named owner/process before live operation |
| Shopify data migration | Inspect/export only when access is supplied, no assumed existing data |
| Budget for plugins/services | Report complete costs before paid activation |

The original reason for leaving Shopify remains unknown, but WooCommerce is now the user's chosen commerce direction. Do not reopen platform selection without a concrete blocker.

Near-zero recurring cost is a goal, not a verified promise. Report hosting, domain, email, payment fees, plugin licenses, translation integration, and maintenance separately. Prefer a small number of maintained dependencies over many overlapping plugins. Do not promise a custom replacement is free simply because its source code has no license fee.

## 12. Required developer handoff

Deliver the theme/plugin source, reproducible setup, safe demo data, working supported flows, automated test evidence, a manual visual review, dependency/version list, environment-variable guide, owner guide, cost inventory, and launch checklist. Clearly distinguish implemented, mocked, verified, and blocked features.

Do not stop at a static homepage and call the project complete. Do not claim transactions work without exercising the payment lifecycle. If deployment, live services, or credentials are unavailable, finish independent work and state exactly what is needed for live operation.

## 13. Copy-ready AI build prompt

> Build the jewelry storefront described in the attached Jewelry Store GDD. Treat its confirmed requirements as authoritative, its proposed baselines as documented defaults, and its unresolved fields as explicit configuration or launch blockers.
>
> First inspect the repository and its project instructions. Preserve existing work and explain meaningful conflicts. In a new repo, use the proposed custom WordPress/WooCommerce theme plus project workflow plugin. Keep design and custom business code in the repo. Use WooCommerce for commerce and its admin for product/order operations. Do not build a visual page editor or revive the rejected editor-level selector.
>
> Follow the Chanel homepage reference for image-led presentation using the maker's own identity. Inspect desktop and mobile if accessible. Build a shopping-first experience with Hebrew, English, French, and proper RTL. Mark missing brand/content assets explicitly rather than inventing them.
>
> Validate the commerce foundation first: ready-stock payment and stock reservation, made-to-order shipping quote plus 50% deposit, bespoke quote plus configurable deposit, and mark-ready triggering a localized balance-payment email. Preserve production, payment, and fulfillment as distinct concerns. Make callbacks and repeated readiness actions safe against duplicates.
>
> Continue through the implementation phases and acceptance tests. Use test payments and sandbox email. Missing real credentials must not stop independent work, but mocks must remain obvious and live checkout must not be presented as ready. Do not purchase services or activate real payments as part of development.
>
> Finish with a reproducible repo, screenshots, test results, owner instructions, recurring-cost breakdown, and a clear list of remaining launch inputs. Record material decisions in docs/decisions.md. Avoid substituting a visual-only prototype for the required functional implementation.

## 14. Technical references

Sources consulted during discovery on 18 November 2026. Re-check versions and compatibility during implementation. These references support feasibility, not a claim that the full integration has been tested.

- Chanel Israel homepage: https://www.chanel.com/il-en/
- WooCommerce classic-theme development: https://developer.woocommerce.com/docs/theming/theme-development/classic-theme-developer-handbook
- WooCommerce template structure: https://developer.woocommerce.com/docs/theming/theme-development/template-structure/
- WooCommerce Store API, relevant if evaluating a separate frontend: https://developer.woocommerce.com/docs/apis/store-api/
- WooCommerce Deposits documentation: https://woocommerce.com/document/woocommerce-deposits/
- WooCommerce Deposits product/compatibility information: https://woocommerce.com/products/woocommerce-deposits/
- PayPlus WooCommerce integration candidate: https://www.payplus.co.il/wordpress
- WooCommerce update and backup workflow: https://woocommerce.com/document/how-to-update-woocommerce/
- WooCommerce cost categories: https://woocommerce.com/pricing/

**Source limitation:** the Deposits product page and detailed documentation differed about reminder-email availability during research. The required event here is a production-readiness trigger, not a calendar reminder. Verify the installed version's behavior and implement the readiness integration deliberately.
