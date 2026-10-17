# Smoke test report

Made by `tests/Browser/SmokeTest.php` on 2026-10-17 21:21:35 (sqlite, `php artisan dusk --filter=SmokeTest`). A brand new company is signed up through the public form and driven through the product; screenshots are in `docs/smoke-test/`.

**Result: 21 of 21 steps passed, 0 failed.**

How to read "driven by": *browser* = typed or clicked in the browser; *endpoint calls* = the browser called the same endpoint the screen calls (real session, CSRF, validation, permission, posting), no form was typed; *commands* = an artisan command.

| # | Step | Driven by | Result | Screenshot | Notes |
|---|---|---|---|---|---|
| 1 | Sign up a new company | browser: public form | pass | [smoke-test/01-sign-up-a-new-company.png](smoke-test/01-sign-up-a-new-company.png) | company #2, 6 roles, 42 accounts |
| 2 | Sign out and sign in again | browser: login form | pass | [smoke-test/02-sign-out-and-sign-in-again.png](smoke-test/02-sign-out-and-sign-in-again.png) |  |
| 3 | Setup wizard | browser: page | pass | [smoke-test/03-setup-wizard.png](smoke-test/03-setup-wizard.png) | wizard opened |
| 4 | Create a supplier, a customer and products | endpoint calls, then the list pages in the browser | pass | [smoke-test/04-create-a-supplier-a-customer-and-products.png](smoke-test/04-create-a-supplier-a-customer-and-products.png) | 3 products, 1 supplier, 1 customer |
| 5 | Purchase and receive stock | endpoint calls, then the purchase list in the browser | pass | [smoke-test/05-purchase-and-receive-stock.png](smoke-test/05-purchase-and-receive-stock.png) | stock of rice 50, bill 11000 |
| 6 | Sell to the customer and take a payment | endpoint calls, then the sell list in the browser | pass | [smoke-test/06-sell-to-the-customer-and-take-a-payment.png](smoke-test/06-sell-to-the-customer-and-take-a-payment.png) | sale 2900 paid in cash |
| 7 | Customer returns two bags (sale return) | endpoint call, then the stock check | pass | [smoke-test/07-customer-returns-two-bags-sale-return-.png](smoke-test/07-customer-returns-two-bags-sale-return-.png) | rice stock 40 -> 42 |
| 8 | Record an expense voucher | endpoint call, then the expense list in the browser | pass | [smoke-test/08-record-an-expense-voucher.png](smoke-test/08-record-an-expense-voucher.png) | EXP-00001 approved |
| 9 | Journal entry and its approval | endpoint calls, then the journal list in the browser | pass | [smoke-test/09-journal-entry-and-its-approval.png](smoke-test/09-journal-entry-and-its-approval.png) | was pending, now approved |
| 10 | Bank statement reconciled | endpoint calls, then the page in the browser | pass | [smoke-test/10-bank-statement-reconciled.png](smoke-test/10-bank-statement-reconciled.png) | statement 1 reconciled |
| 11 | Trial balance, profit and loss and balance sheet agree | endpoint calls and the report pages in the browser | pass | [smoke-test/11-trial-balance-profit-and-loss-and-balance-sheet-agree.png](smoke-test/11-trial-balance-profit-and-loss-and-balance-sheet-agree.png) | ledger debit = credit = 45060; balance sheet difference 0 |
| 12 | Stock report | browser: report page | pass | [smoke-test/12-stock-report.png](smoke-test/12-stock-report.png) |  |
| 13 | Employee, attendance, leave and a payroll month | endpoint calls, then the HR page in the browser | pass | [smoke-test/13-employee-attendance-leave-and-a-payroll-month.png](smoke-test/13-employee-attendance-leave-and-a-payroll-month.png) | payroll 2026-09 posted |
| 14 | Manufacturing order | endpoint calls, then the page in the browser | pass | [smoke-test/14-manufacturing-order.png](smoke-test/14-manufacturing-order.png) | produced 5 combos |
| 15 | Custom invoice from template code, printed | endpoint calls, then the printed page in the browser | pass | [smoke-test/15-custom-invoice-from-template-code-printed.png](smoke-test/15-custom-invoice-from-template-code-printed.png) |  |
| 16 | Till: scanned code and a keyboard-only sale | browser: POS | pass | [smoke-test/16-till-scanned-code-and-a-keyboard-only-sale.png](smoke-test/16-till-scanned-code-and-a-keyboard-only-sale.png) | one scanned line sold as cash |
| 17 | Dashboard graphs | browser: dashboard | pass | [smoke-test/17-dashboard-graphs.png](smoke-test/17-dashboard-graphs.png) |  |
| 18 | Notification sound switch | browser: header | pass | [smoke-test/18-notification-sound-switch.png](smoke-test/18-notification-sound-switch.png) | was true, saved on and still so after a reload |
| 19 | Offline: sale and expense entered with no network, sent once | browser: Offline entries page, network switched off | pass | [smoke-test/19-offline-sale-and-expense-entered-with-no-network-sent-once.png](smoke-test/19-offline-sale-and-expense-entered-with-no-network-sent-once.png) | 2 entries queued offline, both posted exactly once |
| 20 | Demo companies build (pilot and history) | commands | pass | [smoke-test/20-demo-companies-build-pilot-and-history-.png](smoke-test/20-demo-companies-build-pilot-and-history-.png) | pilot company built with 30 documents; demo:history exit 0 |
| 21 | Create a role and a user; the limited user is kept to their pages | endpoint calls, then the browser as the new user | pass | [smoke-test/21-create-a-role-and-a-user-the-limited-user-is-kept-to-their-pages.png](smoke-test/21-create-a-role-and-a-user-the-limited-user-is-kept-to-their-pages.png) | cashier #6 reaches the till and is refused the role page |

## What the smoke test found and what was done about it

Bugs of the product (fixed, with tests):

* **Offline mode gave a company-level user (no branch of their own) an empty reference list** (no customers, no payment accounts) and would have refused their entries as "must belong to a branch": the `User` model answers an empty string for "no branch", which the sync code took for branch 0. Now a user without a branch sees the whole company and posts into the company's first branch (`tests/Feature/OfflineSyncTest.php`, last test).
* **The offline entries page showed a copy of the reference data up to 30 minutes old** (a customer or product added a minute ago was missing from the lists). Opening the page while online now always takes a fresh copy; the background copy is refreshed after 10 minutes.

Not bugs, but worth knowing (what the test had to allow for):

* The dashboard graphs of a company are kept for five minutes (the *Refresh* button recalculates); a company that opens the dashboard before its first sale sees empty graphs until then. The step calls the same recalculation the button does.
* A new company starts with journal approval on, so the journal entry of step 9 needed its approval.
* The bank reconciliation list shows the account, period, closing balance, matched count and status, not the note typed on the statement.

## Not run here

The migration rehearsal on a copy of the old schema needs a copy of the live backup, so it was only **prepared**: the commands are in `docs/fresh-database-proof.md` (section "Old-schema (staging) proof") and must be run by the owner.
