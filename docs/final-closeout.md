# Final closeout: the last 13 items (Sales 3, Accounts 10, Inventory 2)

Everything here is additive and **off or unchanged by default**. A company that uses none of it sees the same ledger, stock,
costs, reports and balances as before; each feature that touches the ledger or stock has a guarantee test saying so (listed
per item). Every voucher goes through the journal approval rule and the period lock (`GuardsLockedPeriods`); nothing bypasses
the lock, and a recalculation or a schedule that meets a locked period stops and says so.

Nothing has been run on the live database (company 1 and the demo company 2 are untouched). The Dusk browser test ran on a
throw-away SQLite file on port 8099 with the inline environment and the `APP_URL` guard of `tests/DuskTestCase.php`.

Status words: **Built** = built and proven by tests here; **Partial** = built, but a stated part cannot be proven without an
outside account or was left out on purpose (the reason is given); **Not built** = not built (the reason is given).

## Test numbers

* Full Pest suite: **3,727 tests, 3,727 passed, 21,766 assertions** (`php -d memory_limit=-1 vendor/bin/pest`, about 21 minutes).
* New Pest tests in this closeout: 53 (advances 3, valuation 7, journal schedules 9, bad debt 3, dunning 4, projects 3, foreign
  currency 5, inter-branch 4, offline POS sync 7, channel adapters 8).
* Dusk (real Chrome, throw-away SQLite on port 8099): `OfflinePosTest` (network switched off at the browser; 35 assertions) and the
  page-load tests of the 9 new pages plus Messaging Settings (10 tests), all passed.
* `RolePresetAccessTest` fixtures and `live-menus.json` regenerated; Pint clean.
* The task listed 13 items (Sales 3, Accounts 10, Inventory 2); those numbers add up to 15, and all 15 are in the table at the end.

## Live migrations, in this order

None has been run live. The code tolerates the new tables / columns being absent, so deploying before migrating changes nothing.

| # | Migration | What |
|---|---|---|
| 1 | `2026_11_09_100000_create_advance_refunds_table` | refund of an unused customer / supplier advance |
| 2 | `2026_11_09_100100_add_advance_refund_menus` | two permissions (`/cashcollection/refund`, `/supplierpayment/refund`) |
| 3 | `2026_11_09_200000_create_inventory_valuation_tables` | valuation method history, standard costs, locked sale-line costs, recalculation runs |
| 4 | `2026_11_09_200100_add_inventory_valuation_menus` | Inventory Valuation page (+ method / standard / recalculate permissions) |
| 5 | `2026_11_10_100000_create_journal_schedule_tables` | journal schedules and runs; `t_accounts.reversal_of_id / reversed_by_id` |
| 6 | `2026_11_10_100100_add_journal_schedule_menus` | Journal Schedules page |
| 7 | `2026_11_10_200000_create_bad_debt_tables` | bad debt settings, write-offs, allowance provisions |
| 8 | `2026_11_10_200100_add_bad_debt_menus` | Bad Debt page (`/baddebt/approve` is separate from `/baddebt/add`) |
| 9 | `2026_11_10_300000_create_dunning_tables` | dunning levels, events, collection cases |
| 10 | `2026_11_10_300100_add_dunning_menus` | Dunning & Collections page |
| 11 | `2026_11_10_400000_create_projects_tables` | projects; `project_id` on sale lines, purchase lines, voucher lines |
| 12 | `2026_11_10_400100_add_project_menus` | Projects page |
| 13 | `2026_11_10_500000_create_multicurrency_tables` | switch + FX account on company settings, foreign fields on voucher lines, dated rates, settlements |
| 14 | `2026_11_10_500100_add_foreign_currency_menus` | Foreign Currency page |
| 15 | `2026_11_10_600000_create_interbranch_tables` | inter-branch accounts and transfers |
| 16 | `2026_11_10_600100_add_interbranch_menus` | Inter-Branch Transfers page |
| 17 | `2026_11_11_100000_create_pos_offline_sales_table` | offline POS sales (client UUID, status, conflict) |
| 18 | `2026_11_11_100100_add_pos_offline_menus` | Offline POS Sales review page |
| 19 | `2026_11_11_300000_add_options_to_messaging_settings` | provider options (template, language, country code) |

The menu migrations grant every new page to `companyadmin`. Presets: the Accountant gets the new finance pages (not
`/baddebt/approve`, which the approval pattern keeps with the Manager), the Manager gets the Offline POS review with resolve, the
Cashier is explicitly **not** given `/cashcollection/refund`.

---

## Task A: Customer and supplier advances (Accounts, 1 item): Built

What was checked: customer advance = a cash collection that keeps part of the cash (`Payment::createSellAdvance`), used on
invoices (`applyAdvance`), reversed (`reverse`). Supplier advance = a supplier payment that keeps part (`createPurchaseAdvance`),
used on bills (`applyAdvance`), reversed. **Gap found: neither side could pay an unused advance back** (the customer side only
through the separate store-credit balance, which does not know about a cash-collection advance; the supplier side not at all).

Closed: `POST /api/cash-collections/{id}/refund-advance` and `POST /api/supplier-payments/{id}/refund-advance` (own permissions
`/cashcollection/refund`, `/supplierpayment/refund`). A refund is the mirror of the advance: a customer refund posts Dr customer's
account / Cr cash or bank; a supplier refund posts Dr cash or bank / Cr supplier's account. It lowers the advance's remaining balance
(`advanceRemaining` = advance less used less refunded), cannot exceed it, goes through the period lock, and is removed when the
payment / collection is reversed. Buttons "Pay back advance" and "Refund advance" on the two pages.

Evidence: `tests/Feature/AdvanceRefundTest.php` (3 tests): partial refund, over-refund and over-use refused, reversal removes the
refund and every voucher, permission, other-company 404, locked period refused with nothing left behind.

## Task B: Inventory valuation and COGS (Inventory, 2 items): Built, with stated limits

Defaults used (they matched the investigation; no conflict found):

* **Method per company: weighted average (default, today's behaviour) / FIFO / standard cost.** **LIFO is not offered**: it is
  not permitted under IFRS (IAS 2). Choosing it is refused with that sentence; the page states it.
* **Effective-date model:** a method applies from its date forward. Earlier days keep the method they had; nothing is backfilled.
  The date must be after the books lock and the inventory close and after the previous change. The stock a variation holds the day
  before FIFO takes effect becomes its **opening layer**, at the weighted average of that day.
* **Negative stock** (more sold than layers): the excess is costed at the last known layer cost (conservative; no zero).
* **One cost source:** `Services\Valuation\CostSource` is asked by Stock Valuation, the Stock Report, Stock Ageing, write-offs
  and (through Stock Valuation) the profit-and-loss stock figures and the dashboard; Item Profit asks it per sale line. Under the
  weighted average every call is the `AverageCost` call it always was.
* **Standard cost:** per variation, company-wide, dated (`standard_costs`, append only); stock is valued at standard where one
  exists, else at average; the Inventory Valuation page has the standard-vs-actual variance (no ledger posting).
* **Recalculation engine:** preview (per sale line: weighted average or locked cost against the cost under the chosen method,
  totals, uncosted count) then commit, which **locks the cost on each sale line** (`sell_line_costs`). A range touching a locked
  period (books lock or inventory close) is **refused as a whole**; the override header does not apply. A lock is used only while
  the line keeps its date and base quantity, otherwise the cost is worked out again. Nothing is posted to the ledger.

Guarantee tests (`tests/Feature/InventoryValuationTest.php`, 7 tests): no setting and `average` set give byte-identical Stock
Report, Item Profit and P&L figures and no cost locks; FIFO from a date (sale before the date unchanged; the sale after takes the
oldest layers: 125 against the average's 150; stock 1,600 against 1,200); Stock Valuation = Stock Report = P&L closing stock under
each method (the **mismatch test**, also exposed as `/api/valuation/reconciliation` and "Check reports agree"); LIFO refused;
locked-period refusals; preview then lock; a stale lock is ignored; permissions and company isolation.

Stated limits:

* **Balance Sheet stock (updated after this closeout):** the balance sheet now carries the closing stock as an asset line and the
  P&L's net profit in equity (see `docs/known-limitations.md`, `BalanceSheetStockTest`). Still no inventory account is posted and no
  perpetual COGS entry exists: it is the periodic method computed in the reports, so closed periods were not restated.
* The cost lock is written by the recalculation action, **not at the moment of sale** (hooking the sale save path would touch
  the posting path). Between recalculations a sale line is costed on demand by the same method, so reports still agree.
* FIFO pools are per company and variation (like the average they replace); a transfer between branches moves no layer. A unit
  that returns with no cost of its own (sale return, positive adjustment) goes back on the oldest consumed layer. Opening stock
  that had no purchase cost enters the opening layer at zero.
* A perpetual COGS posting (Dr COGS / Cr Inventory) was **not built**: it is a separate decision (step 5 of the plan) and would
  restate every closed period.

## Task C: Accounts part B (9 items)

Common engine: `JournalScheduleEngine` (items 1-4), modelled on the depreciation engine: one voucher per occurrence through
`AccountsVoucher`, unique on (schedule, day, kind) so a second run posts nothing, stops at the first locked occurrence without
jumping a gap (reason kept on the schedule, resumes after a reopen), previewable, `journals:run-schedules` daily at 03:30, and a
"Run now" on the page.

1. **Recurring and reversing journals: Built.** Daily / weekly / monthly / yearly, with end date or run count; "post
   automatically" or "draft for approval" (the voucher is left pending whatever the company setting is). Reversal: first day of the
   next month, a separate voucher linked both ways (`reversal_of_id` / `reversed_by_id`; the original is flagged), made only once
   the original is approved and approved with it. Tests: `JournalScheduleTest` (9).
2. **Recurring expenses: Built.** Same engine, voucher type EXP, so the expense approval rule applies (test with the rule off:
   vouchers are pending). Fixed amount per run; pause / resume / cancel.
3. **Prepaid and deferred amortization: Built.** Straight line by month on month ends, the last month absorbs the rounding,
   stops at a locked month (the depreciation pattern) and continues after reopening; deferred revenue is the mirror (direction).
4. **Accrued expenses: Built.** Accrual voucher (Dr expense / Cr accrued liability) at month end with the automatic reversal above.
5. **Bad debt: Built, both methods.** *Direct write-off*: requested with a reason, **approved by someone else** (separate
   permission), posted as Dr bad debt expense (or the allowance) / Cr customer through the same call as an early-payment discount, so
   the invoice balance and status, the customer ledger and the aging follow; reversible. *Allowance*: set from the aging buckets at
   per-bucket rates, once a day, as one journal for the **change** (releases when debt falls). `BadDebtTest` (3): the aging total
   falls by the write-off and returns on reversal, the customer line is credited, locked period and other-company invoice refused.
6. **Dunning: Built, except live SMTP delivery.** Three levels, editable subject / body templates with placeholders, days-past-due
   rules, a reviewed batch (nothing is sent unselected), email through the existing branded mailable and the company's mail settings,
   a "log a manual follow-up" channel, a log of every attempt with the text, collection status (open / promised with a date /
   disputed / escalated / closed) that leaves a customer out of the batch. Separate from the tenant subscription dunning. Posts
   nothing (test: voucher count unchanged). **Not proven here:** delivery through a real SMTP server; the mail is queued through
   the same layer as invoices and asserted with `Mail::fake()`.
7. **Multi-currency vouchers: Partial.** Optional, **default off**; with it off currency fields are not even read (test: a line
   with currency fields stores none). On: a voucher line carries currency, rate (foreign per one base unit, the sense of Currency
   Rates) and foreign amount beside the base amount, which stays the posted truth and is checked to the cent; dated rates
   (`currency_rate_history`) with the current rate as fallback; **the realised gain / loss is posted only when a foreign line is
   settled**, to the exchange account, clearing the party's account. *Left out, on purpose:* sales and purchase documents still
   carry no currency (only voucher lines do), no **unrealised** period-end revaluation, and entry is on the Foreign Currency page
   and the journal API, not yet a field on the journal form.
8. **Project / job costing: Built, with a stated limit.** A project master apart from cost centers (optional link), a
   `project_id` dimension on sale lines, purchase lines and voucher lines (accepted through the document and voucher paths, and
   applied in bulk from the Projects page), profitability (revenue, direct cost, margin, margin %, against budget). A tag posts
   nothing (test: trial balance and voucher count unchanged), a locked period refuses a re-tag. **Limit:** the sale, purchase and
   expense forms have no project picker yet; the sale / purchase journals are not split by project (the report reads the lines).
9. **Inter-company: Partial, by design.** Built: transfers between the **branches of one company** (two vouchers, due-from /
   due-to clearing accounts, approval rule and lock apply), a branch user gives only from their own branch, and a consolidation
   that **eliminates** the clearing accounts (anything that does not cancel, e.g. a voucher still pending, is shown as unmatched).
   **Not built, as instructed:** transactions between separate tenant companies; that would be the first cross-company write and
   break tenant isolation.

Every Task C feature that posts has a test that the trial balance stays balanced and one that a locked period is refused.

## Task D: Offline POS (Sales, 1 item): Built, for the stated scope

Server (`PosOfflineSync`, `pos_offline_sales`): `GET /api/pos/offline/catalog`, `POST /api/pos/offline/sync`, review at
`/posoffline`. Rules as agreed:

* **Cash and card-slip-recorded sales only**; returns, credit sales (a sale must be paid in full), discounts, loyalty and gift
  cards, and closing the day / books stay online (refused with a reason; the terminal's close button is disabled offline).
* **Client UUID, idempotent:** a repeat returns what the first did and posts nothing (test: three sends, one sale, one payment).
  Sale and payment are one transaction.
* **Server-side authority:** a sale is parked as a conflict instead of forced when the catalog price moved (`price_changed`),
  a product is gone (`product_missing`), its day is now locked (`period_locked`) or the server total differs
  (`total_mismatch`); a supervisor accepts (posts it as sold, on today for a locked day) or discards it.
* **Oversell:** a sale that takes stock below zero is posted and flagged (`oversold`), even when the company blocks negative
  stock online (the online POS still refuses the same sale: tested).
* **Receipt numbers:** each terminal prints a provisional number `OFF-<device>-<seq>` (device id kept on the device), so two
  terminals cannot collide; the real invoice number is the server's, assigned at sync and shown beside it in the queue.
* **Browser:** `/sell/pos/offline` with `public/pos-sw.js` (page and build files cached), the catalog and the sale queue in
  IndexedDB, sync on reconnect, a queue with pending / synced / conflict / failed. Tested in a real browser (below).

Tests: `PosOfflineSyncTest` (7, server) and `tests/Browser/OfflinePosTest.php` (Dusk: catalog saved online, service worker
caches the page, network switched off at the browser, reload from cache, sale rung up and printed with `OFF-` number, nothing on
the server, network on, sale replayed once with payment).

Stated limits: sales are recorded **without sales tax lines** (the server's tax engine is not run from the cached catalog); no
price lists, customer-specific prices, kits or serial / lot items offline; the catalog is the first 5,000 variations; a token
that expired during a long outage needs a new sign-in (the queue is kept on the device).

## Task E: E-commerce and messaging (Sales, 2 items): both Partial

No live store or provider account was available, so neither is Built.

* **Shopify and WooCommerce adapters** rewritten to the published API behaviour (Shopify Admin REST `orders.json`: token header,
  `status=any`, 250 a page, `Link` rel next, 429 `Retry-After`, discount allocations netted into the unit price, cancelled
  orders flagged; WooCommerce `wc/v3/orders`: basic auth over HTTPS only, `per_page` / `page` with `X-WP-TotalPages`, `after`,
  cancelled / failed / refunded flagged, line `total` over quantity). Orders become **draft sales** once per external id; an
  unknown SKU is kept as failed and retried after the catalog is fixed. Fixed on the way: a second sync used to fail on a
  `CarbonImmutable` type. Contract tests against recorded fixtures: `tests/Feature/ChannelAdaptersTest.php`
  (`tests/Fixtures/channels/*.json`, written from the providers' documentation).
* **WhatsApp (Meta Cloud API) and SMS (Twilio) adapters** next to the generic webhook: request shape, template vs text,
  number normalisation (`PhoneNumber`, default country code), the provider's error code reported, "not configured" never reported
  as sent. Same fixture tests.

### Live test checklist (what only an account can prove)

Shopify: create a custom app in the store admin with `read_orders`; put the shop address and the Admin API access token in
the e-commerce connection; place one test order with a known SKU and one with a discount code; sync; check the draft sale
(customer, quantity, unit price after discount, shipping); cancel an order in Shopify and sync again (must be skipped); place
more than 250 orders to check paging; confirm the API version in `ShopifyAdapter::API_VERSION` is still served.

WooCommerce: enable HTTPS, create a REST key with read permission, put the store URL and the key pair in the connection; place a
test order with a coupon (the unit price must be after the coupon), one on hold and one cancelled; sync; check paging with more
than 100 orders.

WhatsApp: a Meta business account with a Cloud API phone number, its phone number id, a permanent system-user token with
`whatsapp_business_messaging`, and an **approved template** (a business may not open a conversation with free text); set the
template and language in Messaging Settings; send a receipt to a number that has opted in; check the `wamid` came back and the
error text for a number outside the 24-hour window without a template (code 131047).

SMS (Twilio): Account SID and Auth Token as `SID:token`, a sending number or Messaging Service SID; send to a verified number;
check the `SM...` sid and a deliberately invalid number.

---

## Known limitations (all of them, in one place)

1. ~~Balance Sheet has no inventory line~~ (now carries closing stock); still no perpetual COGS posting (Task B).
2. Sale-line cost locks are written by recalculation, not at the moment of sale (Task B).
3. Multi-currency: voucher lines only, realised gain / loss only at settlement, no unrealised revaluation (Task C7).
4. Project tag has no picker on the sale / purchase / expense forms (Task C8).
5. Inter-company between separate companies is not built, deliberately (Task C9).
6. SMTP delivery of dunning, Shopify, WooCommerce, WhatsApp and Twilio are not proven against live accounts (Tasks C6, E).
7. Offline POS records no sales tax lines and has no price lists or tracked items offline (Task D).
8. A live database needs the 19 migrations above, in order, and a rebuild of the front end (`npm run build`).

## Tracker sync table

| Module | Item | Status |
|---|---|---|
| Accounts | Customer & supplier advances (payment, allocation, refund) | Built |
| Accounts | Recurring & reversing journal entries | Built |
| Accounts | Recurring expenses | Built |
| Accounts | Prepaid & deferred expense amortization | Built |
| Accounts | Accrued expenses & accrual reversals | Built |
| Accounts | Bad debt, write-off & allowance | Built |
| Accounts | Dunning & collection management | Built (live SMTP delivery not proven) |
| Accounts | Multi-currency vouchers | Partial (see limit 3) |
| Accounts | Project / job costing | Built (no form picker, limit 4) |
| Accounts | Inter-company transactions & consolidation | Partial (branches of one company only) |
| Inventory | Valuation methods (FIFO, Weighted Average, Standard; LIFO not offered) | Built |
| Inventory | COGS calculation & recalculation engine | Built as a report calculation (periodic COGS, per-line cost on demand, recalculation action); **no COGS or inventory posting to the ledger** (limit 1, 2) |
| Sales | Offline POS | Built (limit 7) |
| Sales | Shopify / WooCommerce integration | Partial (no live store) |
| Sales | WhatsApp / SMS provider | Partial (no live account) |
