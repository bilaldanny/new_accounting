# Documentation vs reality (Part D)

Checked 2026-10-18 against code, routes, `git`, `composer`/`npm` and a sample test run (6 files, 81 tests, 81 passed, 1,004 assertions, sqlite in memory; not the full suite, which takes about 21 minutes and was not re-run).

## False or stale claims

| # | Document | Claim | Reality | Fix |
|---|---|---|---|---|
| 1 | `known-limitations.md` (PDF row) | "There is no server-made PDF file." | `app/Services/Documents/PdfWriter.php` builds a PDF for the party statement, attached to the statement email (`ContactStatement::pdf`, `LedgerStatementDeliveryTest:64`). True for invoices, payslips, reports. | Say "only the account statement has a server PDF". |
| 2 | `tracker-sync-waves-1-6.md` line 40 | Balance sheet: "Inventory at cost is a memo line outside the totals (no inventory account in the ledger)." | Superseded: `tracker-sync-final.md` and `BalanceSheetStockTest` make closing stock an asset line and the profit the P&L net profit. The older file still says otherwise. | Mark the old file superseded or delete the row. |
| 3 | `full-reaudit-2026-10.csv` (repo root) | 119 rows "Missing", 81 "Partial" | Stale October snapshot: no row is missing now (`items.csv`). Anyone who sells from this file under-sells or mis-states the product. | Replace with `items.csv`. |
| 4 | `final-report-waves-1-8.md` / `tracker-sync-final.md` | Rows by Built/Partial/"Not built 0" and the headline "103 Built / 31 Partial" | Counts are for 134 rows only (modules 1, 8-19). The other 237 rows rest on module-closeout docs with no status table, so no single document shows all 371. | Use `items.csv` as the register. |
| 5 | `known-limitations.md` "dark theme covers the dashboard and the main lists" | Only 1 of 570 page files contains a `dark:` class (counted); dark mode must be done by CSS variables, which I could not verify visually here. | Unverified; screenshot test exists for the dashboard only (`DashboardScreenshotsTest`). | Check with a Dusk screenshot of 10 pages. |
| 6 | `release-notes.md` "Subscription Analytics ... figures are always the report's own" | Not contradicted; the snapshot is dropped on change (`CustomerSubscriptionAnalyticsSnapshotTest:66`). Rebuild is by the 04:30 schedule. | OK | none |
| 7 | `deploy-runbook.md` and `release-notes.md` migration counts | "114 pending / 165 since 2026_10_01" | **Correct**: 165 files from `2026_10_01`, 114 from `2026_10_09` (counted in `database/migrations`, 345 total). | none |
| 8 | `release-notes.md` "on `main`, not pushed" | **Correct**: `main` is 80 commits ahead of `origin/main`. | none |
| 9 | `.env.example` vs runbook | Runbook says set `PUBLIC_REGISTRATION_ENABLED=false` and secure cookies for a closed pilot; `.env.example` ships `APP_DEBUG=true`, `PUBLIC_REGISTRATION_ENABLED=true`. | A fresh deploy copied from the example is open and verbose. | Change example defaults or add a loud runbook step. |
| 10 | `known-limitations.md` "Menus and most screens are English; a small set of Urdu words" | **Understated**: `t()` is called from no Vue file; the Urdu dictionary has 26 keys. The Urdu comes through invoice printing words and a few labels only. | Say "Urdu is available for printed invoices only". |
| 11 | `final-closeout.md` and release notes on COGS | They state periodic COGS and no ledger posting plainly. **Correct**, but the item registers say Built; this audit downgrades #158 and #159 to Partial. | Reflect in the register. |
| 12 | `docs/tracker-sync-final.md` "Built = built and proven by a test" | 11 rows cite merged test names ("..."), which do resolve to existing tests (checked). But **no test touches the HR endpoints** for shifts, roster, salary components, statutory rates and tax slabs (`/api/hrm/shifts` is referenced in 3 files but `hrm/roster`, `hrm/components`, `hrm/rates`, `hrm/tax-slabs` in none), yet rows #175, #179, #182 are Built. Also parked POS sales (#90) have no test. | Downgraded in `items.csv`. |
| 13 | `security-sweep.md` limit | Admits `ContactLedgerWatchApiTest` fails when run alone (helper defined in another file). Not re-checked; I did not run it alone. | unchecked | Fix the helper placement. |

## Claims checked and found true

* FBR is a stub, sends nothing (`StubFbrGateway`); known-limitations first section.
* No online payment for customers; Stripe adapter exists on fixtures.
* Cash drawer setting is stored, the POS page has no reference to it.
* S3/Drive adapter packages are not installed (`composer.json`).
* Scheduler commands listed in release notes exist in `routes/console.php` (14 scheduled commands; the notes mention only the new one).
* Offline mode limits (500 entries, `OFF-` numbers, IndexedDB) match `OfflineModeTest`; browser-side numbers were not re-measured.
* Sample suite run passed; the "3,893 tests / 24,002 assertions" figure was not re-run. A static count finds 3,148 `it()`/`test()` declarations (datasets expand to more).
