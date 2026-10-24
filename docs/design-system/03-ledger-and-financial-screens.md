# Part 3: ledger and financial screens, current vs recommended

How to read: **Current** is what the code does today (file and line evidence in brackets); "not found" means the code has no such thing. **Recommended** is what an accountant expects on that screen. Sources: `resources/js/composables/report.ts` (config of all 79 reports), `pages/report/index.vue` (859 lines, the one page behind every report), `pages/report/ledger.vue` (589), `pages/contact/{customer,supplier}/view.vue`, `app/Http/Controllers/Reports/*`.

## Verdict first

| Question | Answer |
|---|---|
| Are all ledger and financial screens on one standard? | **No.** There are **3 ledger implementations** (account ledger inside the generic report table; party ledger in `report/ledger.vue` with its own raw `<table>`; and the same party ledger re-built in the customer and supplier view pages). Their columns carry different names for the same thing (Voucher vs Voucher No, Narration vs Description, Location vs Branch). |
| Do the financial statements look like statements? | **No.** Profit & Loss, Balance Sheet, Trial Balance and Cash Flow are the generic report table (flat rows, summary tiles above) with columns Code / Account / Amount. No indentation, no section headings in the table, no subtotals inside the table, no print layout. |
| Is there drill-down? | **Not found.** Voucher numbers and account names are plain text in every report (`report/index.vue` has no router link from a row). |
| Is the period lock visible on reports? | **Not found.** Period lock exists (`PeriodLockCard.vue`, `/period-lock`), but no report shows it. |
| Is Dr/Cr written? | Columns named Debit / Credit exist; the **running balance is one signed number with no Dr / Cr suffix**. |

## One standard to adopt

Three templates cover all ten screens:

1. **Ledger template** (account ledger, party ledger / statement, day book, cash book, bank book, item ledger): Opening row, entries with running balance, period totals row, closing row. Same column order and names everywhere.
2. **Statement template** (trial balance, P&L, balance sheet, cash flow): grouped sections with indented accounts, subtotals, optional comparative column, a "difference" or "check" banner.
3. **Matrix template** (aging): party rows by bucket columns, totals row, expandable to open documents.

Shared rules for all three: dates `dd-MMM-yyyy`; amounts right aligned, tabular figures, company number format; negative per company setting; Dr/Cr suffix on every balance; every voucher number, account and party is a link; period-lock chip and branch / currency chips in the header; export (CSV, Excel, PDF) and Print in one split button; Email and WhatsApp on party statements; phone layout = one card per entry.

---

## a. Account ledger (GL)

| | Current | Recommended |
|---|---|---|
| Where | `/report/account-ledger` -> `report/index.vue`, config `report.ts:846` | same route, ledger template |
| Filters | company (required), account selector, date range (`FiscalYearDateRange`), branch (role based), search (voucher / reference / description) | account (searchable, shows code + name + nature), date range with fiscal-year presets, branch, **voucher type**, **status** (approved only is fixed today), "include child accounts" toggle for group accounts |
| Columns, in order | Date, Voucher No, Reference, Account (code), Account Name, Description, Branch, Debit, Credit, Balance | Date, Voucher No (link), Voucher type, Reference, Narration, Counter account, Debit, Credit, Balance **Dr/Cr**; Branch only when more than one branch |
| Opening / closing | summary tiles above the table: Opening balance, Debit, Credit, Closing balance | **opening row inside the table** (first line) and closing row (last line) so the print matches the screen; tiles stay as a header summary |
| Running balance | computed on the server ("balance on the side the account normally carries") | keep server side; show Dr / Cr suffix |
| Dr/Cr convention | separate Debit / Credit columns, single signed balance | add suffix; contra balances in red |
| Grouping / subtotal | none; a group account is the sum of the accounts below it, listed together | group by account when a group is chosen, with a subtotal per account |
| Totals row | not in the table (tiles only) | sticky totals row: total debit, total credit, closing |
| Drill-down | **not found** | voucher no -> voucher view; counter account -> that account's ledger |
| Export / print / email / WhatsApp | table export CSV / Excel / PDF / copy (`show-export`); no print layout; no email / WhatsApp | same export, real print layout with company header and filters used |
| Period lock indicator | not found | chip "Locked up to 31-Jul-2026" next to the dates |
| Branch / currency | branch filter; no currency | base-currency chip; foreign-currency accounts show the foreign column (Foreign Currency module exists) |
| Empty / long list | server pagination, default 10 per page; empty text from `theTable` | pagination 50 / page, jump to date, opening row stays when the range is empty |
| Mobile | `theTable` responsive columns; on phones now one card per row (new mobile CSS) | card: date + voucher link on top, narration, Dr / Cr amounts, balance on the right; sticky closing balance |

## b. Customer / supplier (party) ledger and statement

| | Current | Recommended |
|---|---|---|
| Where | **three places**: `/report/ledger` (`report/ledger.vue`, 589 lines), the Ledger tab of `contact/customer/view.vue` (2,156 lines) and of `contact/supplier/view.vue` (2,198 lines) | one component used in all three places, plus a standalone route |
| Filters | company, branch, party (customer or supplier toggle), date range | party picker with balance preview, date range, branch, "show only unpaid", document type |
| Columns | Date, Voucher, Reference, Narration, Location, Status (paid / partial / due), Debit, Credit, Balance, Method, Cheque (11) | Date, Document (link), Reference, Narration, Debit, Credit, Balance Dr/Cr, plus Status chip; Method / Cheque only for payment rows (second line) |
| Opening / closing | opening row and **period totals** row (`tfoot`) and a closing tile; present in the report page and the customer tab | keep, add a closing row and **aging footer** (current, 30, 60, 90+) under the closing balance |
| Running balance | **computed in the browser** (`ledgerRows` loop, `acc_nature` decides the sign) | server side, so exports and PDFs match the screen |
| Dr/Cr convention | signed number, no suffix; customer balance positive = receivable | "Dr" for receivable, "Cr" for advance / payable, written |
| Totals | period totals row (debit, credit, closing) | keep |
| Drill-down | **not found** (the customer tab has a "reviewed up to here" checkbox per row, `ledgerWatch`) | document link, payment link; keep the reviewed-up-to mark (accountants use it) |
| Export / print / email / WhatsApp | on the customer / supplier tab only: Download PDF, **Email (PDF attached), WhatsApp (link to the PDF)** via `LedgerStatementActions` (253 lines); none on `/report/ledger` | same action bar on every instance; add "Send reminder" using Dunning |
| Period lock | not found | chip |
| Branch / currency | branch select; currency not found | party currency chip; statement in party currency with base equivalent column |
| Empty / long list | "No ledger entries for this period." row; the **whole period is loaded and rendered at once** (no pagination) | server pagination or virtual rows; opening row sticky |
| Mobile | raw table in `table-responsive` (sideways scroll, 11 columns) | cards as in (a); the Method / Cheque detail on a second line |

## c. Day book / cash book / bank book

| | Current | Recommended |
|---|---|---|
| Where | **Not found as dedicated screens.** Closest: `/report/vouchers` "Receipts & Payments Report" (`report.ts:895`), the Account Ledger on a cash or bank account, and the POS register report (shift based) | three presets of the ledger template |
| Receipts & Payments report | filters: party, voucher type; columns Date, Voucher No, Type, Party, Accounts, Bank / Cash, Reference, Cheque No, Cheque Date, Branch, Amount (single amount column); tiles Receipts, Payments, Net; **no opening balance, no running balance** | -- |
| Recommended | -- | **Day book**: every approved voucher of the range grouped by date, type filter, Debit / Credit columns, daily total, no running balance. **Cash book / Bank book**: account ledger fixed to the chosen cash or bank account, opening balance, receipts and payments with running balance, closing cash, with a "two-column" print (receipts left, payments right) for those who want it. Bank book adds cheque no / date and a "reconciled" tick (bank reconciliation exists at `/bankreconciliation`) |

## d. Trial balance

| | Current | Recommended |
|---|---|---|
| Where | `/report/trial-balance` -> `report/index.vue`, `report.ts:871` | statement template |
| Filters | company (required), account groups, date range | company, branch, date range or "as of", hide zero accounts, level (group / ledger) |
| Columns | Code, Account, Class, Opening Dr, Opening Cr, Debit, Credit, Closing Dr, Closing Cr | Code, Account, Opening Dr / Cr, Period Dr / Cr, Closing Dr / Cr; class shown as a group heading, not a column |
| Opening / closing | in the columns | same |
| Running balance | n/a | n/a |
| Grouping / subtotal | **none** (a flat list; class is a text column) | group by class (Assets, Liabilities, Equity, Revenue, Expenses), subtotal per group, grand total |
| Totals row | tiles: Accounts, Closing debit, Closing credit, **Difference** (accent) | sticky grand-total row; green "Balanced" or red "Difference 1,250.00" banner |
| Drill-down | not found | account -> account ledger with the same dates |
| Export / print | table export; no print layout | print layout, "as of" stamp, preparer line |
| Period lock | not found | chip |
| Mobile | cards | group accordion; Closing Dr / Cr only, others on expand |

## e. Profit & loss

| | Current | Recommended |
|---|---|---|
| Where | `/report/profit-loss` (`report.ts:921`) and a separate `/report/profit-loss-comparative` (`report.ts:963`) | one screen with a "Compare with" toggle |
| Filters | company (required), start / end date | company, branch, period presets (month, quarter, year to date, last year), compare (previous period / same period last year), cost-centre |
| Columns | Code, Account, Amount; tiles: Revenue, Opening stock, + Purchases, - Closing stock, = Cost of goods sold, Gross profit, Expenses, **Net profit**, Products without cost | Account (indented), Amount, **% of revenue**, and, when comparing, Previous, Change, Change % |
| Structure | classes by the first digit of the account code; the stock-based cost of goods sold arithmetic (opening stock + purchases - closing stock) appears **only as tiles**, not as rows of the statement | Revenue / Cost of sales (opening stock + purchases - closing stock) / **Gross profit** / Operating expenses / **Operating profit** / Other income & expense / **Net profit before tax** / Tax / **Net profit**, each a heading with a subtotal row |
| Dr/Cr | amounts signed | statement uses natural sign (revenue positive, expenses positive in their section), no Dr / Cr |
| Drill-down | not found | account -> ledger for the same period; tile "Products without cost" -> product list |
| Export / print | table export | print layout (title, period, branch, currency, page numbers) |
| Period lock | not found | chip; "Draft: period not locked" watermark when open |
| Mobile | cards | collapsed sections with the subtotal visible, tap to expand |

## f. Balance sheet

| | Current | Recommended |
|---|---|---|
| Where | `/report/balance-sheet` (`report.ts:944`) and `/report/balance-sheet-comparative` (`report.ts:986`) | one screen, compare toggle |
| Filters | company (required), as-of date | company, branch, as-of date with presets (month end, year end, last year end), compare date |
| Columns | Code, Account, Amount; tiles Total assets, Liabilities, **Equity (with profit)**, **Difference** | Account (indented), Amount, optional Previous / Change |
| Structure | flat list; closing stock valued with the costing method of that day and shown as an asset; profit of the year to date is folded into equity | **Assets** (current / non-current) then **Liabilities** (current / non-current) then **Equity** (capital, reserves, **current-year profit as its own line**); totals "Total assets" equals "Total liabilities and equity" with a check banner |
| Drill-down | not found | account -> ledger as of; inventory -> stock report |
| Export / print | table export | print layout; wide screens may show assets left and liabilities + equity right |
| Mobile | cards | stacked sections |

## g. Cash flow

| | Current | Recommended |
|---|---|---|
| Where | `/report/cash-flow` (direct method, `report.ts:1698`) and `/report/cash-flow-indirect` (`report.ts:1009`); a forecast page `/cashflowforecast` (separate workspace) | one statement screen with a method toggle, forecast stays separate |
| Columns | Section, Account, Counter Account, Cash In, Cash Out, Net; tiles Operating, Investing, Financing, Net change, Opening cash, Closing cash | Operating / Investing / Financing as headings, lines inside, subtotal per section, **Net change, Opening cash, Closing cash** as the last three rows |
| Reconciliation | tiles only | line "Closing cash agrees with the bank and cash accounts: yes / difference" |
| Other items as Trial balance | | |

## h. Stock ledger / item ledger

| | Current | Recommended |
|---|---|---|
| Where | `/report/stock-movement-history` (`report.ts:1897`), `/report/stock` (balance per product on a day), `/report/price-history`, `/report/warehouse-stock`, `/report/stock-ageing`. **A true item ledger (one product, opening, running balance) is not found.** | ledger template for one product |
| Movement report columns | Date, Document (link text), Movement, Product, SKU, Branch, Warehouse, Qty In, Qty Out, Unit; tiles Movements, In, Out, Net | Date, Document (link), Type, Party, Warehouse, **In, Out, Balance qty**, Unit cost, Value; with valuation on, a closing value |
| Opening / closing | none per product | opening quantity and value row, closing row |
| Filters | warehouse | product (required for the ledger view), warehouse, date range, batch / serial |
| Drill-down | not found | document link; serial / batch trace (`/report/serial-traceability` exists) |
| Mobile | cards | card per movement with in / out chip and running balance |

## i. Aging reports (receivable and payable)

| | Current | Recommended |
|---|---|---|
| Where | `/report/customer-aging`, `/report/supplier-aging` (one factory `agingReport`, `report.ts:288`); also `/report/payment-age` and `/report/stock-ageing` (different configs) | matrix template; the four share one look |
| Filters | party (customer / supplier), as-of date | party, branch, as-of, "by due date / by invoice date", hide zero |
| Columns | Customer / Supplier, Code, Invoices, Oldest (days), Not yet due, 0-30, 31-60, 61-90, 90+, Total | Party, Not yet due, 1-30, 31-60, 61-90, 90+, Total, % overdue; bucket header colours from green to red |
| Totals | tiles above (Count, each bucket, Total) | sticky totals row inside the table |
| Drill-down | not found | row expands to open invoices; party name -> party ledger; bucket cell -> those invoices |
| Actions | none | "Send reminder" (Dunning exists: `/dunning`), export, print |
| Mobile | cards (11 fields each) | card with party, total, a stacked bucket bar; tap for detail |

## j. Voucher entry screens

Journal, payment, receipt (the `deposit` module), expense, fund transfer, sales invoice, purchase bill.

| | Current | Recommended |
|---|---|---|
| Journal | `journalentry/Fields.vue` (615 lines) + `JournalLinesEditor.vue` (433); attachments, totals, Debit / Credit columns, print and approval in `view.vue` | one voucher shell |
| Payment / receipt / expense / fund transfer | `payment/Fields.vue` (697), `deposit/Fields.vue` (680) + `DepositLinesEditor` (335), `expense/Fields.vue` (626) + `ExpenseLinesEditor` (356), `fundtransfer/Fields.vue` (602) + `FundTransferLinesEditor` (335): **four near-copies** | same shell, line grid configured by columns |
| Sales invoice | `sell/Fields.vue` (1,427) + `LineItemsEditor.vue` (1,033, serial / batch tracking, keyboard) | same shell, product-line variant of the grid |
| Purchase bill | `purchase/Fields.vue` (1,306) + `LineItemsEditor.vue` (1,015), approval flow, attachments | same |
| Multi-line grid | 10 different editors; keyboard handling in each, none shared | **one LineGrid** component: Enter / Tab to next cell, Ctrl+Enter save, Insert adds a line, Delete removes, Up / Down move rows, paste rows from a sheet |
| Totals footer | per editor (`is-amount is-debit`); Dr = Cr check in the journal | sticky footer: sub-total, discount, tax, total; for journals Debit total, Credit total, **Difference** that turns green at zero |
| Attachments | present in journal, payment, deposit, expense, fund transfer, purchase forms; **not found in the sales invoice form** | AttachmentBox on every voucher |
| Approval status | shown on `view.vue` of 6 voucher types (`VoucherApprovalActions`) | status chip in the header of both form and view (Draft, Pending, Approved, Posted, Rejected), timeline in a side panel (`ActivityTimeline` exists) |
| Print preview | `window.print()` from `view.vue` in 8 places; each its own layout; invoice has template codes (`docs/invoice-template-codes.md`) | one print header and footer component, print preview inside the voucher view |
| Chrome | 27 `*FormChrome.vue` copies | one `FormChrome` |
| Period lock | not found on the forms | warning banner when the voucher date falls in a locked period |
| Mobile | forms stack (new mobile CSS), the grids scroll sideways | each line becomes a card with an expand-to-edit sheet; totals sticky at the bottom |

## Which screens have more than one implementation (one standard each)

| Screen | Implementations today | One standard |
|---|---|---|
| Party ledger | 3 (report page, customer tab, supplier tab) | `PartyLedger` component |
| Account ledger vs party ledger | 2 layouts, different column names | the ledger template with a per-type column set |
| Balance sheet / P&L / cash flow | plain + comparative (+ direct / indirect) = 6 routes for 3 statements | 3 screens with a compare toggle |
| Voucher chrome | 27 + Simple + none | one `FormChrome` |
| Voucher line grid | 10 | one `LineGrid` |
| Aging | 4 configs (`customer-aging`, `supplier-aging`, `payment-age`, `stock-ageing`) | matrix template, 2 variants |
