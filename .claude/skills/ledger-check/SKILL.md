---
name: ledger-check
description: Run the accounting-critical test slice (journal/ledger tests plus account-balance reconciliation tests) and Larastan against currently-touched files. Use as a fast pre-commit gate for any change to journal, ledger, or chart-of-accounts logic — invoke with "/ledger-check".
disable-model-invocation: true
---

# Ledger Correctness Check

A fast, targeted gate for changes to `LedgerJournal`, `TAccount`, `JournalEntryController`, `ChartOfAccount*`, or anything under [ledger-accounting-rules](../ledger-accounting-rules/SKILL.md) — without paying for the full test suite.

## Steps

1. **Run the accounting-critical Pest tests**:
   ```bash
   php artisan test --compact --filter="Journal|AccountBalancesApi|ChartOfAccountLedgerBalance"
   ```
   This covers `PurchaseJournalTest`, `SellJournalTest`, `JournalEntriesApiTest`, `JournalEntryPagesTest`, `AccountBalancesApiTest`, and `ChartOfAccountLedgerBalanceTest` — the tests that assert debit/credit balance and ledger/chart-of-accounts reconciliation.

2. **Run Larastan on the touched files only** (not the whole app, to keep this fast):
   ```bash
   vendor/bin/phpstan analyse <touched-file-paths>
   ```
   Determine touched files from `git status --short` / `git diff --name-only` if not already known from the current session's edits. If nothing is staged/modified under `app/`, skip this step.

3. **Report results plainly**: which tests passed/failed, and any Larastan errors, without editing files. If a test fails, do not attempt a quick patch to make it pass — surface the failure and the balance mismatch it implies (this is financial data; a silenced test here is worse than a red one) and let the user or the main conversation decide the fix.

4. If both the filtered test run and Larastan pass, say so explicitly (e.g. "Ledger check passed: N tests, 0 Larastan errors on M files") so this can be used as a quick go/no-go before committing.

This command never runs the full `composer ci:check` — for that, ask the user or run it directly.
