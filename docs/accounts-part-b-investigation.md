# Accounts & Finance, part B: investigation and plan (nothing built)

Status: **investigation only**, as agreed for wave 6. These are the nine items that post to the general ledger or change what
the statements say. Nothing here has been written; each section says what the codebase has today, the design I would build, the
risk to existing numbers, the guarantee test it would carry, and what I need decided first.

## What the codebase gives us (verified)

* **One voucher path.** `TAccount::createJournalEntry` (used by journal, expense, payment, deposit and fund-transfer vouchers, and
  since wave 5 by `AccountsVoucher`) handles the company's approval rule, the period lock (`GuardsLockedPeriods`) and numbering. A
  voucher that is not approved does not count in any statement.
* **A scheduled-posting pattern that already works.** `FixedAssetDepreciationEngine` (+ `assets:run-depreciation`, monthly):
  per-period, idempotent through a unique key, follows the journal approval rule, and **stops at the first locked month** without
  leaving a gap, so it can be re-run after a reopen. `RecurringInvoices` (wave 4) is the same idea for sales.
* **No currency on vouchers.** Sales, purchases and vouchers carry no currency; everything is stored in the company's base
  currency. `contacts.currency_id` and `currency_rates` (one current rate per company and currency, no history) are used only to
  *display* an invoice total in the customer's currency (`DisplayCurrency`).
* **Cost centers** exist on journal / expense lines, budgets and fixed assets (`cost_center_id`), not on sale or purchase lines.
* **Companies are separate tenants.** There is no company group or owner link between companies; isolation between them is
  deliberate and heavily tested.
* **Aging** exists (customer / supplier aging, party ledgers) and is the natural input for bad debt and dunning.
* **Messaging** exists since wave 4 (`MessageDispatcher`: email via mail settings, WhatsApp / SMS through a configured webhook
  provider), which dunning can reuse.

## Shared foundation (recommended first, small)

A **journal schedule** engine used by items 1, 2, 3, 4 and 5 below, copied from the depreciation engine's rules:
`journal_schedules` (company, branch, kind: `recurring | reversing | amortization | accrual`, template lines, frequency, start,
end / count, reversal rule, status) and `journal_schedule_runs` (schedule, period, voucher, **unique(schedule, period)**).
It posts through `AccountsVoucher` (so approval + lock apply), is run by a daily command like `invoices:generate-recurring`
(with a "run now" for one company), previews before posting, stops at a locked period and reports which month blocked it.
*Guarantee test:* with no schedules, nothing is posted; running twice for the same period posts once; a locked month stops the run
and the next run resumes there.

## The nine items

### 1. Recurring & reversing journal entries
* **Today:** a journal can be duplicated by hand; no recurrence, no auto-reversal (audit: Missing).
* **Design:** a schedule of kind `recurring` posts a template journal each period; kind `reversing` posts the entry once and its
  mirror on the first day of the following period (flagged `reversal_of`). Fixed amounts per line, with optional "last run" end.
* **Risk:** low (new vouchers only, through the normal rule).
* **Decision needed:** reversal date convention (first day of next month) and whether a reversal waits for approval like any
  journal (recommended: yes).

### 2. Recurring expenses
* **Today:** no schedule (audit: Missing). An expense is a voucher of the `expense` family with its own approval flag.
* **Design:** a `recurring` schedule whose template is an expense voucher (payee, expense account, paid-from account, amount,
  cost center), posted as the expense family so the expense approval setting applies; "due next" list on the page.
* **Risk:** low. **Decision:** amount fixed per run or editable before posting (recommended: fixed, with a pause / skip).

### 3. Prepaid & deferred expense amortization
* **Today:** none (audit: Missing); fixed-asset depreciation is the only periodic posting.
* **Design:** `amortization` schedule from a prepaid amount: Dr expense / Cr prepaid asset in equal monthly parts over N months
  (straight-line, last month absorbs rounding), starting from the month of the payment; the original payment is untouched.
  Preview, post-through-month, stop at a lock; cancelling stops future months only.
* **Risk:** low (new vouchers). **Decision:** which prepaid and expense accounts per schedule (chosen on the schedule); deferred
  *revenue* (Dr deferred revenue / Cr revenue) is the mirror and cheap to include: confirm if wanted.

### 4. Accrued expenses & accrual reversals
* **Today:** none (audit: Missing).
* **Design:** an `accrual` voucher (Dr expense / Cr accrued liability) dated month end with an automatic reversal on day one of the
  next month, built on item 1's reversing schedule; the accrued liability account is chosen per accrual.
* **Risk:** medium: a reversal that is not approved leaves the accrual standing. **Decision:** reversals auto-approved when the
  accrual was (recommended), or follow the rule independently.

### 5. Bad debt, write-off & allowance
* **Today:** none (audit: Missing). No allowance account concept; aging buckets exist.
* **Design:** two separate mechanisms the owner chooses between per company:
  * *Direct write-off:* a document that writes a chosen invoice (or its balance) off: Dr bad debt expense (or the allowance) /
    Cr customer account, counted as a payment of kind `write_off` so the invoice balance and status follow (the same shape as the
    early-payment discount of wave 5), with a reason and approval; reversible.
  * *Allowance (provision):* a period-end schedule that sets the allowance to a percentage of each aging bucket (rates per bucket
    in settings): Dr bad debt expense / Cr allowance for doubtful debts for the *change*, posted as a journal.
* **Risk:** medium: write-offs change receivable balances and customer ledgers (intended). **Decision:** direct, allowance or both;
  the accounts; bucket rates; who may write off (separate permission, approval recommended).

### 6. Dunning & collection management
* **Today:** none for invoices (the subscription dunning is for tenants only).
* **Design:** dunning *levels* (e.g. 7 / 21 / 45 days past due) with message text per level; a run lists overdue invoices per
  customer, sends the level's message through `MessageDispatcher` / email (or just records it when no provider), and logs each
  contact (`dunning_events`: invoice, level, channel, status, promised date). No ledger posting at all; optional late fee is out
  of scope unless asked.
* **Risk:** low (no posting). **Decision:** levels and wording; whether sending is automatic or a reviewed batch (recommended:
  reviewed batch first).

### 7. Multi-currency vouchers (the largest item)
* **Today:** single base currency; display-only conversion; one rate per currency with no history (above).
* **Design (phased, each phase releasable):**
  1. *Rate history:* `currency_rates` gains dated rows (keep the current rate working as "latest").
  2. *Foreign amount on voucher lines:* nullable `currency_id`, `exchange_rate`, `foreign_amount` on `t_account_details`; the base
     amount stays the posted truth, so every existing report is unchanged.
  3. *Foreign-currency receivables / payables:* a sale or bill in a customer's currency posts the base amount at the day's rate and
     remembers the foreign amount; a payment at another rate posts the **realised gain / loss** to an FX account.
  4. *Period-end revaluation:* a journal that revalues open foreign balances at the closing rate (**unrealised** gain / loss),
     reversing next period (item 4's mechanism).
* **Risk:** high: touches every posting path (`SellJournal`, `PurchaseJournal`, payments, returns, vouchers) and party statements.
* **Decisions needed before any work:** is this needed now or is display conversion enough; base currency per company (exists);
  the realised / unrealised FX accounts; how a foreign invoice's "paid" status is judged when the rate moved. I recommend
  building only phases 1-2 first (memo amounts, no new gains / losses) and stopping for review.

### 8. Project / job costing
* **Today:** none (audit: Missing); cost centers cover journal / expense lines, budgets and assets.
* **Design:** a `projects` master (customer, dates, budget, status) linked to a cost center, and tagging of *sale lines, purchase
  lines and expense lines* with a project so revenue, direct cost and gross margin can be reported per project; a project profit
  report (budget vs actual). Revenue and cost are already posted; tagging is a dimension, not a new posting.
* **Risk:** medium: sale / purchase lines need a project column and the journals must carry it onto the posted lines (touching
  `SellJournal` / `PurchaseJournal`), guarded by a test that totals are unchanged with no project.
* **Decision:** project = cost center with extras (recommended) or a separate dimension; whether stock issues / payroll are
  allocated (not in the first cut).

### 9. Inter-company transactions & consolidation
* **Today:** only branch consolidation inside one company; companies are isolated tenants.
* **Design:** needs a **company group** (companies under one owner) that does not exist, then: inter-company accounts
  (due-to / due-from) per pair, an inter-company voucher that creates the mirrored voucher in the other company, matched and
  flagged `intercompany`, and a consolidated statement with eliminations of matched inter-company balances.
* **Risk:** highest for isolation: it is the first deliberate cross-company write. It must be limited to groups the owner sets up and
  every mirrored voucher audited.
* **Decision:** whether companies in this installation really belong to one owner (a group), who can create the link (superadmin
  only is the safe start), and whether consolidation is read-only reporting first (recommended).

## Suggested order and size

| Step | Items | Size | Posts to ledger |
| --- | --- | --- | --- |
| A | Journal schedule engine, then 1 recurring / reversing, 2 recurring expenses, 3 prepaid amortization, 4 accruals | one build, about 25 tests | yes (new vouchers) |
| B | 5 bad debt / allowance | medium, about 10 tests | yes |
| C | 6 dunning | small, about 8 tests | no |
| D | 8 project costing | medium, about 12 tests | no new posting (dimension) |
| E | 7 multi-currency, phases 1-2 | large, needs sign-off | no change to base figures |
| F | 9 inter-company | large, needs the group concept first | yes (mirrored vouchers) |

Steps A to D can run without further design input beyond the decisions flagged above (reversal convention, account choices on
each schedule, allowance method). E and F should not start until the owner confirms the scope.

## What I need from you to proceed
1. Reversal convention and approval behaviour (items 1, 4).
2. Bad debt: direct write-off, allowance, or both; which accounts; bucket rates (item 5).
3. Dunning: levels, wording, automatic or reviewed batch (item 6).
4. Project = cost center with extras? (item 8).
5. Multi-currency: needed now? phases 1-2 only? FX accounts (item 7).
6. Inter-company: is there a real "group" of companies under one owner? (item 9).
