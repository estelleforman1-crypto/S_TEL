# Owner guide

Everything day to day happens in WordPress admin (`/wp-admin`) on a computer. You do not need GitHub for prices, stock, photos or orders. GitHub is only for design and code changes.

There are two menus to know:

- **Atelier**: requests, quotes, production, balances. Start each day here.
- **WooCommerce** and **Products**: products, normal orders, refunds, shipping settings.

## 1. Products

**Add a piece:** Products > Add New.

1. Title and text in the default language (Atelier > Settings shows which one).
2. **Translations** box, lower on the page: title, short description and text in the other two languages. An empty field shows the default language.
3. **Product data** box > General:
   - *Regular price*.
   - *How this piece is sold*: "Ready to ship" or "Made to order".
   - *Production time (weeks)*: made to order only. Leave empty if each quote states it.
4. **Inventory** tab: tick "Manage stock" and enter the quantity. A one-of-a-kind piece has quantity 1.
5. **Product image** and **Product gallery** on the right. Add alt text to each photo (what the photo shows).
6. **Product categories** on the right. Categories with a photo appear as large panels on the home page (Products > Categories > edit > Thumbnail).
7. Publish.

**Sizes and metals (variations):** Products > Attributes holds the option lists (for example Ring size, Material). Click "Configure terms" to add values and their translations. Translate the option name itself in Atelier > Settings. On a product: Product data "Variable product" > Attributes > Variations, with one price (and stock, for ready pieces) per variation.

**Sold pieces:** stock 0 shows "Sold". To hide sold pieces instead: WooCommerce > Settings > Products > Inventory > "Hide out of stock items".

## 2. Ready-to-ship orders

They arrive in WooCommerce > Orders as **Processing** (paid). Pack and ship, then set **Completed**. The customer receives WooCommerce's "order complete" email.

If an order shows **"Needs attention: payment received after cancellation"** in Atelier, the payment arrived too late (the piece was released or sold). Stock was not changed. Refund the payment, or contact the customer about another piece.

## 3. Made-to-order requests

1. The customer chooses options on the product page and sends a request. You receive an email and the request appears in **Atelier > Overview > New requests**.
2. Open the request. Check the options and the delivery country. Contact the customer with the buttons if you have questions; set the state to "Needs clarification" while you wait.
3. Click **Prepare quote**. Enter the delivery cost for that destination, the delivery method name, the production time and any terms. The price is filled in from the product; you can change it. The deposit is calculated by the made-to-order rule (see Settings).
4. **Save quote as draft**. You are now on the order screen. Check the **Commission workflow** panel: total, deposit, outstanding.
5. Click **Send quote to customer**. The customer receives an email with a link to the quote. If the email fails, copy the link from the panel and send it by WhatsApp or email yourself.
6. The customer accepts and pays the deposit. You see "Deposit paid" in the panel and in Atelier.
7. Optional: click **Set: in production**.
8. When the piece is finished, click **Mark ready and request balance**. The customer receives one email with a payment link for the remaining amount. Clicking again does not send a second email; use **Retry email** if the customer did not receive it, or copy the link.
9. When the balance is paid, the order moves to **Processing** = paid in full, ready to ship. Ship or hand over, then set **Completed**. You cannot set Completed while money is outstanding.

**Change a quote** (before any payment): **Revise quote**, save, then send again. The old link and any unpaid deposit link stop working.
**Cancel a quote**: Cancel quote (only before any payment).

## 4. Bespoke commissions

Same as made to order, with two differences:

- Requests come from the **Commissions** page (with private reference photos) or from you: **Atelier > Record a request** for conversations on WhatsApp, email, phone or in person. Copy the important details; conversations are not synchronized automatically.
- In **Prepare quote** you write the piece description and price, and you must enter the **deposit amount** for this commission. There is no automatic 50% for bespoke work.

Reference photos are private. Open them from the request screen (you must be logged in).

## 5. Money

- Each deposit and balance is its own small order ("Deposit for order #N", "Balance for order #N"). The commission order shows the total, what is paid and what is outstanding. You do not need to calculate anything.
- **Refund** a deposit or balance: open that payment order (link in the workflow panel) > Refund. The commission order updates its paid and outstanding amounts automatically.
- Never mark an order paid by hand because a customer says they paid. Only the payment provider's confirmation marks payments.

## 6. Settings (Atelier > Settings)

- **Made-to-order deposit**: percentage, and whether it applies to the full total (with delivery) or to the items only. Tick "I confirm this basis" when you have decided. Until then, made-to-order quotes cannot be sent on the live site.
- Quote validity (days), default language, contact email and WhatsApp number (shown in the footer), photo limits, pickup.
- Pickup can only be switched on with a location and arrangements text.

Stock hold time (how long a checkout keeps a piece reserved): WooCommerce > Settings > Products > Inventory > Hold stock (minutes).

Shipping rates for ready pieces: WooCommerce > Settings > Shipping. Countries without a rate cannot check out. This prevents free shipping by accident.

## 7. Text and emails

- Page text (About, policies): Pages > edit, with the Translations box for the other languages.
- Email and button wording comes from translation files in the code (`languages/*.po`). The current Hebrew and French texts are **drafts**. Send your final wording, and it will be put into the files (an AI-assisted code change is fine).

## 8. Design changes

Design lives in code (`theme/jewelry-store/`). Ask for changes in plain words (for example "make the home page title larger on phones"). The change is made in the repository, tested, and deployed. There is no drag-and-drop editor by design.

## 9. Updates and backups

Before updating WordPress, WooCommerce or plugins: make a backup. Better: update a staging copy first. If something breaks after an update, restore the backup. See README "Backups and restore".
