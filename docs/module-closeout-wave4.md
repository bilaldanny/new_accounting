# Module closeout, wave 4: Sales & POS (15 items)

Fourteen of the fifteen rows are built (three of them with a stated limit); offline POS was an investigation only, as agreed
(`docs/offline-pos-investigation.md`). Everything is additive: a company that uses none of it sees the sales, stock, payments and
ledger it saw before, and the guarantee tests below pin that.

Live migrations to run, in this order (nothing has been run on the live database):

1. `2026_11_07_100000_create_sales_closeout_tables`
2. `2026_11_07_110000_add_sales_closeout_menus` (companyadmin is granted every new page; `/salesorder/approve` follows the
   approval-menu rule: only roles given approvals, the Manager preset, hold it by default)

The code tolerates the new tables / columns being absent, so deploying before migrating does not break the app.
The scheduler runs `invoices:generate-recurring` daily at 03:00 (`routes/console.php`).
Tests: `tests/Feature/SalesCloseoutTest.php` (17 tests), `RolePresetAccessTest` (fixtures updated: Sales Representative gets
Import Sales, Manager gets Approve Sales Order), Dusk `PageLoadTest` (14 new pages).

## 1. Quotation expiry, proforma, conversion: Built
`sale_quotation_details` beside the existing `quotation` status: valid-until, proforma flag, what it converted to. A quotation
past its date shows as *expired* and cannot be converted until given a new date. Conversion makes a **new** sale (a sales order,
or a final invoice through `Transaction::createSell`, so credit limit, stock check and posting are the sale's own); the quotation
is kept and marked. Page `/quotation`, `QuotationController`.
Tests: "an expired quotation cannot be converted until it is given a new date, then converts once to a final invoice",
"a quotation of another company is not found".

## 2. Sales order as a document: Built
`sales_orders` + lines (separate tables; the sale statuses and `UNPOSTED_SELL_STATUSES` are untouched). Status flow
draft > pending approval > confirmed > partially invoiced > invoiced / cancelled; approval follows the company's sale approval
setting, by someone holding `/salesorder/approve`. Invoicing (all of it or chosen quantities) makes ordinary sales and tracks
invoiced quantity per line. An order posts **nothing** and moves no stock until invoiced. Page `/salesorder`.
Tests: "a sales order posts nothing, is approved by someone else, and invoices in two steps" (guarantee: ledger and stock
unchanged by the order), "sales orders are company scoped".

## 3. Refund payout and store credit: Built
`customer_store_credits` ledger (issue / refund / apply). A sale return already credits the customer's account; *issue* records
that the customer may take it later. *Refund* pays it out through `Payment::createSellRefund` (customer debited, cash / bank
credited, `is_return` = money going out, period lock respected). *Apply* settles an invoice from the credit without moving cash.
The balance can never go below zero. Page `/storecredit`.
Tests: "store credit is issued from a return, refunded in cash with a balanced voucher, and never over the balance" (asserts the
voucher lines: customer Dr 40, cash Cr 40), "store credit of another company is invisible".

## 4. Import sales: Built
`SaleImporter` (same pattern as the contact importer): one row per invoice line, rows sharing `invoice_ref` make one sale through
`SaleDocumentBuilder`; customers by code / mobile / name, products by variation SKU / barcode, inside the company only; **all or
nothing**, `dry_run` check, an invoice reference imported before is skipped. Page `/salesimport`, API `POST /api/sells/import`.
Test: "a sale import checks first, is all or nothing, and skips an invoice reference imported before".

## 5. Cashier master: Built
`cashiers` (code, hashed PIN, targets), `transactions.cashier_id` stamped on a POS sale (active cashier of the company only).
PIN check is throttled and locks a cashier for 15 minutes after 5 wrong tries. The POS top bar has a cashier sign-in; the till
keeps the cashier across sales. Per-cashier today / month totals against target on `/cashier`.
Test: "a cashier pin verifies, locks out after repeated failures, and a cashier of another company is not found".
Limit: the cashier is an attribution and a PIN gate on the POS screen, not a separate login; the signed-in staff user is still
who the system authenticates.

## 6. Cash rounding: Built
Company setting `cash_rounding` (`nearest|up|down:<step>`, off by default). Applied to the total of a **POS** sale only; the
difference is kept in `transactions.rounding_adjustment` and lands in that sale's revenue as the sale posts its final amount.
Test: "with no cash rounding rule a POS total is untouched; with a rule it is rounded and the difference is recorded"
(guarantee: no rule, and a non-POS sale with a rule, keep the exact total and a zero adjustment).

## 7. Rider / delivery dispatch board: Built
`riders`, `dispatch_assignments`; board of posted sales not yet delivered (last 60 days), assign, out, delivered, failed; the
sale's shipping status follows. Pages `/rider`, `/dispatch`. A sale held by an active layaway plan cannot be assigned.
Tests: "a layaway sale is held from dispatch until its plan is paid up", "dispatch cannot assign another company's sale or rider".

## 8. Commission, targets and KPI: Built
`sales_targets`, `commission_tiers`; statement per commission agent / salesperson / cashier for a month: gross, returns, net,
target, achievement %, rate (agent's own rate, replaced by a higher tier once net sales reach its threshold), commission.
Page `/saleskpi`. **Limit:** this is a statement; commission is not posted to the ledger or paid through payroll.
Test: "the KPI statement reads commission tiers and sales targets of this company only".

## 9. Van sales / routes: Built, with a stated limit
`sales_routes`, stops and visits; a day's visit sheet per weekday with visited / skipped / sold (a sold visit names the sale).
Page `/salesroute`. **Limit:** a route may name a van warehouse and rider, but van stock is moved with the existing stock
transfer; there is no automatic load / return reconciliation.
Test: "a sales route and its visit sheet are company scoped".

## 10. Recurring invoices: Built
`recurring_invoices` + runs, `App\Services\RecurringInvoices` (the Module 18 generator pattern), command
`invoices:generate-recurring`, and a manual "Generate now". Daily / weekly / monthly / quarterly / yearly, end date or max runs,
made as drafts or final invoices; a failing run is recorded and does not stop the others. Page `/recurringinvoice`.
Tests: "a due recurring invoice makes one invoice per run, moves forward, and ends at its run limit", "recurring invoices of
another company are not generated by this company and need their permission".

## 11. Installment / layaway: Built, with a stated limit
`installment_plans` + schedule on an ordinary posted sale: down payment, then equal instalments weekly / monthly; paid amounts
are read from the sale's payments (so a payment recorded any way counts), overdue rows are flagged. A **layaway** plan holds
the goods: the dispatch board will not assign the sale until the plan is paid up. Page `/installment`.
**Limit:** the hold is enforced on the dispatch board only; stock on a layaway sale is deducted at sale as for any sale.
Test: "a layaway sale is held from dispatch until its plan is paid up".

## 12. Cheque lifecycle: Built
`cheque_records`, a register over payments: received > deposited > cleared / bounced / cancelled, issued > cleared / bounced /
cancelled, only along `ChequeRecord::FLOW`. Bouncing a received cheque removes its payment through the ordinary payment removal
(voucher goes with it, the invoice is due again). Depositing and clearing are dated steps and post nothing extra (the payment
already posted to the account it was recorded against). Page `/chequemanagement`.
Test: "bouncing a received cheque removes its payment and makes the invoice due again; the flow cannot skip steps".

## 13. E-commerce sync: Partial (adapter and mock built; live platforms unverified)
`ecommerce_connections`, `ecommerce_orders`, `EcommerceAdapter` interface with `MockEcommerceAdapter`, `ShopifyAdapter`
(Admin REST) and `WooCommerceAdapter` (REST v3), `EcommerceSync` (orders pulled, idempotent by external id, each made into a
sale through `SaleDocumentBuilder`, failures kept for retry; credentials stored encrypted). Page `/ecommerce`.
**Why Partial:** the Shopify and WooCommerce adapters have not been run against a real store (the HTTP layer is faked in tests);
only the mock flow is proven end to end. Product / stock push to the store is not built; orders come in one way.

## 14. Digital receipts (link, email, WhatsApp, SMS): Partial (link and email built; WhatsApp / SMS need a provider)
`receipt_shares`: a random-token link to a public receipt page (`/r/{token}`, 30 days, no sign-in, shows that one sale only,
throttled); email through the app's mail settings; WhatsApp / SMS through `MessageDispatcher` and the company's `messaging_settings`
(provider interface with a **webhook** provider). The sale view page has Receipt link / Email / WhatsApp / SMS buttons.
**Why Partial:** with no provider configured a WhatsApp / SMS share is recorded as `not_configured` and nothing is sent; no
vendor API (Twilio, WhatsApp Business) is wired, and the webhook provider has not been exercised against a real gateway.
Test: "a receipt link shows only its own sale, a whatsapp share with no provider is recorded as not configured, and an unknown
token is 404".

## 15. Offline POS: Not built (investigation only, as agreed)
`docs/offline-pos-investigation.md`: how the POS saves today, what a replayed sale touches (stock rules, credit limit, period
lock, serials, gift cards), why an idempotency key is a prerequisite, and the six decisions needed from the owner.

## Honest limits, together
* E-commerce live platforms and WhatsApp / SMS providers are unverified / unconfigured (items 13, 14).
* Commission is a statement, not a posting (8). Van stock reconciliation is manual (9). Layaway hold is on dispatch only (11).
* Cash rounding applies to POS sales only; the cashier is an attribution, not a login (5, 6).

## Notes for whoever runs it
* `RecurringInvoice::nextAfter` and the generator accept any Carbon flavour (found by the test: the app's dates are immutable).
* `PartyLedgerReportTest` rollback list needs no change (this wave adds no report menu).
* Fixed after the first review: cheque register, e-commerce connection, recurring invoice and sales route no longer take
  `company_id` / `branch_id` from the request body (a company user could name another company), and a route's rider, van warehouse
  and salesperson must belong to the route's company. Test: "a company user cannot write a record under another company by sending its id".
