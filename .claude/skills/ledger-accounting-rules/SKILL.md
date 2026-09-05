---
name: ledger-accounting-rules
description: Apply this skill when creating or modifying journal entries, ledger postings, chart-of-accounts logic, or account balance calculations (TAccount, TAccountDetail, LedgerJournal, JournalEntryController, or any *Journal*/ledger code). Enforces double-entry invariants (debits = credits), account-nature conventions, and this app's voucher/reversal conventions.
---

# Ledger & Accounting Rules

This app implements double-entry bookkeeping. `t_accounts` is the voucher header (one per transaction or manual journal), `t_account_details` holds the individual debit/credit legs. Two posting paths exist and must both stay balanced:

- **System-generated journals**: `App\Services\LedgerJournal::post()` — called when a `Purchase`/`Sell`/other `Transaction` is created or updated, to post the matching accounting legs automatically.
- **Manual journal entries**: `App\Models\TAccount::createJournalEntry()` / `updateJournalEntry()` — called from `JournalEntryController` for user-entered `JV`/`JE` vouchers.

## The core invariant: every voucher must balance

`SUM(debit) === SUM(credit)` for all `t_account_details` rows sharing a `t_account_id`. This is enforced in **three separate places** — when adding a rule or a new posting path, all three need to stay consistent:

1. `JournalEntryController::assertLinesAreBalanced()` — validates the raw request *before* anything is written: each line must have a debit **or** a credit but never both, and totals must match. Runs first so a bad request never reaches the DB.
2. `TAccount::assertBalanced()` — re-checks after `syncDetails()` persists the lines (belt-and-braces against the DB state, not just the request), and additionally rejects a zero-total journal (`$debits <= 0`).
3. `LedgerJournal::assertBalanced()` — the system-journal equivalent, called at the end of `post()` after all auto-generated lines (e.g. sale + tax + customer legs) are added.

All three compare `round($debits, 2) === round($credits, 2)` on **rounded** floats — never compare raw floats for equality when touching this code, and never skip the rounding step.

**When adding a new line to a journal (e.g. a new tax type, discount leg, or fee)**: the line must be paired with an offsetting line elsewhere in the same voucher before `assertBalanced()` runs, or the whole save throws a `ValidationException` and the surrounding `DB::beginTransaction()/rollBack()` unwinds everything.

## Account nature (debit-normal vs credit-normal)

Each `ChartOfAccount` has an `acc_nature` of `dr` (debit-normal) or `cr` (credit-normal) — this is stored on the account itself, not inferred from the transaction type. When posting a line, `acc_nature` on the `t_account_details` row is taken from the account (`$account->acc_nature`), falling back to `$debit > 0 ? 'dr' : 'cr'` only if the account has no nature set. Do not hardcode nature based on account name or code prefix — always read it from the `ChartOfAccount` record.

Typical postings seen in the test suite (see below): a purchase debits the purchase/expense account and credits the supplier (payable) account; a sale debits the customer (receivable) account and credits the sales/revenue account; tax legs post to input-tax (debit, on purchases) or output-tax (credit, on sales) accounts. Follow this shape for any new transaction type — don't invent a different debit/credit direction without checking `ChartOfAccountMapping` conventions first.

## Voucher numbering

`LedgerJournal::nextVoucherNo()` / `TAccount::voucherTypeFromNumber()` generate sequential, zero-padded, prefix-scoped voucher numbers (`JV-00001`, `JE-00001`, or a transaction-type prefix like the purchase/sale voucher prefix), scoped per `company_id` + `branch_id`. Never construct a voucher number manually — always go through `nextVoucherNo()` so numbering stays gap-free and collision-checked (it loops until it finds a number not already in use).

## Reversal / correction handling

There is no explicit "reversal" or "reversing entry" concept — corrections are handled by **rewriting the voucher in place**:

- `LedgerJournal::post()` is idempotent per transaction: it finds the existing `TAccount` for a `transaction_id` (`findFor()`) if one exists, deletes all its `details()`, and re-adds fresh lines from scratch, then re-runs `assertBalanced()`. Updating a purchase/sale amount therefore rewrites both journal legs rather than posting a delta or a reversing entry — confirmed by `PurchaseJournalTest`'s "updating a purchase rewrites both journal legs" case.
- `TAccount::updateJournalEntry()` follows the same delete-and-recreate pattern for manual journals via `syncDetails()`.
- Deleting the source transaction deletes its journal (`LedgerJournal::deleteFor()` / `deleteForIds()`), which cascades to `t_account_details` via `$journal->delete()`.
- `TAccount::duplicateJournalEntry()` clones a manual journal (replicating the header and every detail line) rather than reversing one — used for "copy this entry as a new draft," not for correcting a posted entry.

**Do not** invent a reversing-journal pattern (posting an equal-and-opposite entry) unless asked — this codebase's convention is delete-and-repost, and mixing the two approaches will produce duplicate or orphaned ledger lines.

## Source of truth: tests

Before changing posting logic, read (and keep passing):

- [tests/Feature/PurchaseJournalTest.php](../../../tests/Feature/PurchaseJournalTest.php) — balanced 2-leg and 3-leg (with tax) purchase postings, local vs export supplier account codes, update-rewrites-journal, delete-removes-journal, and the `journals:sync-documents` backfill command.
- [tests/Feature/SellJournalTest.php](../../../tests/Feature/SellJournalTest.php) — same shape for sales (local vs export customer, output-tax leg).
- [tests/Feature/ChartOfAccountLedgerBalanceTest.php](../../../tests/Feature/ChartOfAccountLedgerBalanceTest.php) — asserts opening + activity = closing balance reconciles between a contact's ledger (`/api/fetchledger`) and the chart-of-accounts summary (`/api/chart-of-accounts`), and that journal activity takes precedence over document totals when both exist.

Any change to `LedgerJournal`, `TAccount`, or `JournalEntryController` should be run against these three files (`php artisan test --compact --filter=Journal` plus `ChartOfAccountLedgerBalanceTest`) before considering the change done — a balance mismatch here means real financial data is wrong, not just a failing assertion.
