# Module closeout, wave 5: Accounts & Finance, part A (11 items)

All eleven are built; the limits of each are stated. Everything is additive: a company that uses none of it sees the ledger,
payments and balances it saw before, and the tests pin that. Every voucher a feature makes goes through
`App\Services\AccountsVoucher`, i.e. the same path as a hand-entered journal, so the company's journal approval setting (a
pending voucher does not count until approved), the period lock and the voucher numbering all apply.

Live migrations to run, in this order (nothing has been run on the live database):

1. `2026_11_08_100000_create_accounts_closeout_tables`
2. `2026_11_08_110000_add_accounts_closeout_menus` (companyadmin is granted every new page; the Accountant preset gets the
   cash / register / terms pages, Manager gets Cash Flow Forecast, Loans and Guarantees read-only, **Year-End Close stays
   administrator-only**)

The code tolerates the new tables / columns being absent, so deploying before migrating does not break the app.
Tests: `tests/Feature/AccountsCloseoutTest.php` (25 tests), two year-close tests in `YearRolloverTest.php`,
`RolePresetAccessTest` (fixtures updated), Dusk `PageLoadTest` (9 new pages).

## 1. Bank accounts, petty cash (imprest): Built
`petty_cash_funds` + `petty_cash_entries`, `PettyCashController`, page `/pettycash`. A fund is a float in a cash account. *Spend*
posts Dr expense / Cr the fund's cash account and cannot take the fund below nil; *replenish* posts Dr cash / Cr the source bank or
cash account and cannot take it past the imprest amount (the default amount is exactly what restores it). The balance is what was
put in less what was spent, from the fund's own entries. Deleting an entry deletes its voucher (the period lock decides).
Tests: "a petty cash fund spends from its float, cannot overspend, and is replenished back to the imprest amount" (voucher lines
asserted), "petty cash vouchers follow the journal approval rule", "petty cash funds are company scoped and need their permissions".

## 2. Bank statement import (OFX, MT940, CSV): Built, with a stated limit
`BankStatementParser` + `POST /api/bank-reconciliations/parse`: reads an OFX / QFX (SGML or XML), MT940 or CSV file into lines,
period and balances; the bank reconciliation page has a file picker that fills the entry form for the user to confirm before it is
saved. **Limit:** tested on well-formed samples of each format; banks vary (MT940 `:61:` supplementary details, multi-currency
OFX), so an unreadable line is refused with the line quoted rather than guessed.
Tests: "an OFX statement is read into lines, dates and balances", "an MT940 statement is read ... and bad input is refused".

## 3. Bank charges from an unreconciled line: Built
`POST /api/bank-reconciliations/{id}/lines/{line}/charge`: an unmatched outgoing statement line is booked as a voucher Dr the chosen
expense account / Cr the bank account on the line's day and matched to it at once (when the journal rule approves vouchers
immediately, otherwise it can be matched once approved). A line already matched or booked, or of money in, is refused.
Test: "a statement line of money out is booked as a bank charge and matched to its voucher".

## 4. Cheque printing: Built, with a stated limit
`cheque_layouts` + `ChequePrintController`, page `/chequeprint`: positions in millimetres for payee, amount in words, amount and
date, size, font, "A/C Payee only"; a payment voucher (or typed payee / amount / date) is turned into a printable leaf with the
amount in words (`AmountInWords`, lakh / crore or international). Printing marks the voucher as printed and a second print needs
a deliberate reprint. **Limit:** positions are set per bank leaf by hand and printed through the browser; no PDF engine and no
bank-specific templates ship.
Tests: "printing a voucher cheque marks it printed and needs a reprint to print again", "amounts are written out in words ...",
"cheque layouts and vouchers of another company are not reachable".

## 5. Bank guarantee / letter of credit register: Built, with a stated limit
`bank_instruments`, page `/bankinstrument`: reference, bank, party, amount, currency, margin, charges, issue and expiry, status.
An open instrument past its expiry reads as expired, and the list can be limited to those expiring in 30 days.
**Limit:** a register; margin deposits and charges are not posted to the ledger by it.
Tests: "a guarantee past its expiry reads as expired and the expiring filter finds the ones about to", isolation test.

## 6. Loan & financing register: Built, with a stated limit
`loans` + `loan_schedule_rows`, `LoanSchedule`, page `/loan`: equal-instalment schedule at the periodic rate (monthly or quarterly),
last instalment absorbing rounding; optional disbursement voucher (Dr bank / Cr loan account); *pay instalment* posts Dr loan
account (principal) + Dr interest expense / Cr bank; the loan closes when the schedule is paid. **Limit:** fixed-rate annuity
only: no floating rate, prepayment, rescheduling or accrual of interest between instalments.
Tests: schedule arithmetic, "a loan is booked with a disbursement voucher, instalments post principal and interest, and it closes
when paid up", isolation (including another company's account).

## 7. Tax payments & challan tracking: Built, with a stated limit
`tax_payments`, page `/taxpayment`: tax, period, challan number (unique per company and tax), bank, amount, date; posts Dr the tax
payable account / Cr bank; totals by tax for a date range. **Limit:** records what was paid; it does not compute the tax due or file
a return (the return tax work is separate).
Tests: "a tax payment posts a voucher, a challan is recorded once, and deleting it removes the voucher", isolation test.

## 8. Payment terms & early-payment discounts: Built
`payment_terms` (net days, discount %, discount days), assigned to a customer or supplier (`contacts.payment_term_id`), page
`/paymentterm`. A document of such a party inside its discount window can have the discount **taken** by an explicit action: a sale
posts Dr discount account / Cr customer, a bill Dr supplier / Cr discount account (`Payment::createDocumentDiscount`), counted as a
payment on the document so its balance and status follow. Never automatic; once per document; the discount is the percentage of the
balance still due.
Tests: "an early-payment discount is only taken inside its window, posts a voucher and settles that part of the invoice" (guarantee:
a party with no term is not eligible and nothing is posted), the supplier-bill test, isolation test.

## 9. AR/AP matching & allocation engine: Built, with a stated limit
`AllocationEngine`, page `/allocation`: for a customer (advances kept by cash collections) or a supplier (advances kept by supplier
payments) it proposes the matching of the advance to open invoices / bills in the order chosen (oldest, earliest due, smallest
balance) and applies it through the same calls the advance screens use, so no cash moves and nothing new is posted. **Limit:**
it matches advances; matching bank lines to invoices stays with the bank reconciliation, and credit notes are not part of the pool.
Tests: "advances are matched to open invoices in the order the strategy names and applied without moving cash", "a supplier advance
is matched to bills by the same engine", "an allocation cannot take more than an advance holds or use another company's advance".

## 10. Cash flow forecast: Built, with a stated limit
`CashFlowForecast`, page `/cashflowforecast`: today's bank and cash balance, then weekly buckets (and an overdue bucket) of open
invoices, open bills and unpaid loan instalments on their due dates, the cash after each bucket and the lowest point.
**Limit:** only as good as the due dates; no sales forecast, recurring costs or budget are assumed; cheques are left out because the
ledger already holds the payment each was taken against.
Tests: "the forecast puts open invoices, bills and loan instalments into the week they fall due ...", isolation test.

## 11. Fiscal-year close & new-year activation: Built, by a stated design decision
`YearClose` over `YearRollover`, page `/yearclose`: one action runs the rollover (opening balances of the new year; it still needs the
books locked through the last day and the retained earnings account mapped), records the closing (`year_closings`: result, and per
branch the revenue / cost accounts brought to nil with the entry that would close each), and makes the new year the active one.
**Decision to confirm:** the closing is a record, **not a ledger voucher**. The statements take a year's profit from its date range
and the rollover already puts that profit into retained earnings through the new year's opening balances; a closing voucher dated
in the old year would zero that year's revenue and expense and count the profit twice. If the owner wants a posted closing voucher,
the reports would need a "closing entry" flag to exclude it from the profit and loss, and that is a change to every statement.
Tests (in `YearRolloverTest`): "closing a year runs the rollover, records the closing statement and activates the new year, posting
nothing" (ledger voucher count unchanged), "a year close waits for the lock and records nothing while it is blocked".

## Guarantee
"nothing here posts by itself: reading the pages and the forecast leaves the ledger, payments and balances as they were".

## Notes
* While reviewing wave 4 a tenant-isolation gap was found and fixed: four wave 4 endpoints merged the validated request into the new
  row, so a company user could send another company's id (cheque register, e-commerce connection, recurring invoice, sales route,
  and the route's rider / warehouse / salesperson). They now ignore `company_id` / `branch_id` from the body; test "a company user
  cannot write a record under another company by sending its id" in `SalesCloseoutTest`.
