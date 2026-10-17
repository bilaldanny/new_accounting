# Offline POS: investigation (nothing built)

Status: **investigation only.** As agreed, no offline POS code was written. This note says what the POS does today, what
"working offline" would have to mean for this application, the risks, and what has to be decided before any build.

## What the POS does today

* `resources/js/pages/sell/addpos.vue` (about 2,600 lines) is an online-only page. A sale is one `POST /api/sells` with
  `is_pos: true`, and the payment is a second call, `POST /api/sell-payments`, made from the response of the first
  (`sell.id`, `sell.remaining_amount`). The receipt is fetched afterwards.
* Everything the cashier searches comes from the server on demand: customers (`fetchCustomers`), products
  (`sellSearchProducts`), company settings, document settings, payment accounts, loyalty balance, gift card validation.
* There is no service worker, no web manifest and no IndexedDB use anywhere in `resources/js` or `public`.
* The invoice number is assigned by the server on save (`Transaction::generateInvoiceNo`: prefix plus the highest id + 1,
  unique per company). A client cannot know its number in advance.

## What a sale touches on the server (why replay is not trivial)

A POS sale is not a row insert. In one request the server:

1. checks the customer's credit limit (`CustomerCreditLimit`) and, under the company's negative stock rule, the stock of
   every line (`NegativeStockRules`, reservations, kits / BOM components);
2. applies pricing (price lists, discount rules, loyalty redemption, gift card redemption) and cash rounding;
3. posts the sale and its cost lines to the ledger (`SellJournal`), subject to the period lock and the inventory close;
4. consumes serial / lot / batch numbers when the product is tracked;
5. for the payment, posts the receipt voucher.

Each of these depends on the *state of the server at the moment of the sale*. Replaying a sale made an hour ago, after other
terminals have sold the same stock, can legitimately fail (insufficient stock, a customer over their limit, a locked period, a
gift card already spent, a serial already sold).

## What "offline POS" would have to include

| Need | Notes |
| --- | --- |
| Installable shell + cached assets | service worker with a versioned precache of the Vite build; Inertia pages are fetched as JSON, so the POS page needs its own entry that does not depend on a server round trip |
| Local catalogue | products, variations, prices, taxes, customers, payment accounts, settings, snapshotted to IndexedDB with a version / timestamp; refresh policy and size limits for large catalogues |
| Local sale queue | sales stored with a client-generated UUID, replayed in order when online; the server needs an idempotency key so a retry never double-posts |
| Local receipt numbering | a provisional receipt number printed offline, mapped to the real invoice number after sync (or a per-terminal number series reserved up front) |
| Conflict handling | what to do when replay fails (stock, credit limit, period lock, gift card, serial): park the sale, show it to a supervisor, let it be edited or forced |
| Payments | cash only is realistic offline; card / online / gift card / loyalty need the server |
| Security | the queue holds sales and tokens on the device; cashier PIN check (`/api/cashiers/verify-pin`) is online only today; device loss and session expiry need a policy |
| Time | the sale date is the terminal clock; a wrong clock lands a sale in a locked or wrong period |

## Risks specific to this app

* **Stock integrity.** With `negative_stock_mode = block`, an offline terminal cannot know the stock. Either offline sales
  are allowed to go negative (and the discrepancy is found at sync) or offline mode is limited to companies on `allow`.
* **Ledger and period lock.** A queued sale for a day whose period has been locked in the meantime would be refused at replay;
  the business would then have a paid sale it cannot record without an override.
* **Duplicate posting.** `POST /api/sells` has no idempotency key today, and a retry after a lost response would create a
  second invoice and, through the payment call, a second voucher. An idempotency key is a prerequisite.
* **Two calls per sale.** Sale and payment are separate requests; the queue must replay them as one unit.
* **Document numbering and tax authority.** Where invoices must be numbered gap-free or reported to a tax authority
  (the FBR integration in this app), provisional numbers and delayed submission have legal weight.

## Decisions needed from the owner before any build

1. Which terminals / shops actually lose connectivity, and for how long (minutes vs a day)? A "retry queue for a flaky line"
   is a much smaller job than a full offline terminal.
2. May an offline sale go **negative on stock**, or must offline be limited to non-blocking companies?
3. Cash only offline, or also card slips / credit sales to known customers?
4. Is a **provisional receipt number** acceptable, or must each terminal get a reserved number block?
5. Who resolves a sale that fails at replay (supervisor review queue), and may they force it past the period lock?
6. Is FBR (or another tax) reporting of POS invoices required in real time?

## Suggested shape if it is approved (smallest useful first)

1. Server: an **idempotency key** on `POST /api/sells` and `POST /api/sell-payments` (new column + unique index, returns
   the original response on a repeat). Useful even without offline mode: it fixes double submits.
2. Client: a **retry queue** for a failed or timed-out save of the POS sale (same page, localStorage / IndexedDB), replayed
   with the key. Covers short outages with no catalogue caching.
3. Only then, a cached catalogue and a service worker for a true offline terminal, with the supervisor review queue.

No code for any of this exists in the repository.

## Update (final round)

The suggested shape above was built as a general offline engine (see `docs/offline-mode-design.md` and `docs/PROGRESS.md`, final round, wave 2): idempotent sync endpoint, a queue for six kinds of documents, reference data cache, conflicts screen and the Offline POS terminal on the same sync runner.
