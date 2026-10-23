# Audit summary (2026-10-18)

Read-only audit. Only files under `docs/audit-2026-final/` were written. Nothing pushed, no migration, live DB untouched, no Dusk run. Files: `items.csv`, `blocked-dossier.md`, `international-readiness.md`, `docs-vs-reality.md`, `roadmap.md`.

## Counts (371 menu items)

| | Built | Partial | Missing |
|---|---|---|---|
| October re-audit CSV (stale) | 171 | 81 | 119 |
| Latest claims in docs/tracker | 336 | 35 | 0 |
| **This audit** | **318** | **53** | **0** |

**18 claims downgraded** Built -> Partial (reasons per row in `items.csv`). 80 random "Built" claims were sampled (seeded); I read the test assertions for about 61 of them and test names plus assertion counts for 19 more. Result: most tests assert real values (ledger totals, DB rows, status transitions); the downgrades came from untested endpoints, features whose named part is missing, and one browser-only feature. Caveat: for the 237 rows outside the closeout tracker, evidence links are matched by name and are strongest where I read the test; a few automatic links are weak and marked in the CSV. Not re-run: the full suite (a 6-file sample of 81 tests passed).

## Top 10 risks

1. Open dependency advisories: composer 16 (5 packages), npm 8 (6 high), `xlsx` with no fix.
2. No global security headers; webhook delivery has no SSRF guard and no retry.
3. Fresh `.env.example` is open (`APP_DEBUG=true`, public registration on).
4. Restore from backup has never been drilled; backups not encrypted; 165 migrations never run live (about 22 runbook lines flagged lock-heavy).
5. FBR is a stub; any claim of tax compliance would be false.
6. Every outside integration (email, WhatsApp/SMS, Stripe, couriers, bank feed, push, S3) is proven only on recorded fixtures.
7. No perpetual COGS; LIFO named but absent; stock valuation is a report calculation.
8. Rounding is hard-coded to 2 decimals in 953 places; no per-currency decimals.
9. HR set-up endpoints (shifts, roster, components, statutory rates, tax slabs) and POS parked sales have no tests (downgraded).
10. Stale registers: the root `full-reaudit-2026-10.csv` and `tracker-sync-waves-1-6.md` contradict the code; a false "no server PDF" line; the audit log is not tamper-proof.

## Top 10 gaps

1. Documents (invoices/bills/payments) in a foreign currency, revaluation. 2. Tax categories, reverse charge, jurisdictions. 3. Translation: `t()` is used by no Vue file; 26 Urdu keys; no RTL layout test. 4. Country packs (CoA, tax, formats, e-invoice). 5. E-invoice adapters beyond the FBR stub (PEPPOL first). 6. Server PDF for invoices/payslips. 7. Gateways other than Stripe; SEPA/ACH/camt files. 8. Privacy pack (consent, DSAR for users, DPA, residency). 9. Scale work: materialised balances, MySQL load profile, queue on Redis. 10. API token scopes, OpenAPI schemas, webhook replay.

## What I need from you (accounts, credentials, decisions)

* **Decisions:** is the first client under FBR DI/POS rules (ask the tax adviser); approve dependency updates (composer/npm advisories) and any new PDF or S3 package; target countries/languages for round 2; perpetual inventory yes/no and LIFO yes/no; whether to rotate and force-push per the earlier security note.
* **Accounts/credentials (staging only):** SMTP; Meta WhatsApp Business or Twilio; Stripe test keys; a Shopify or Woo dev store; PostEx/Leopards merchant keys; one bank's statement/file spec; a domain with DNS and TLS; a tablet for POS touch checks; PRAL sandbox registration and the official FBR spec for the client's sector; MySQL staging copy for restore and load tests.
* **Sample files:** a real Tally/QuickBooks export; a biometric device model.

## What you can sell today

* **Pakistani SME (yes, with conditions):** sales, purchases, stock, accounts, reports, approvals, payroll with accountant-entered rates, POS and offline entry are built and tested. Conditions: say plainly that FBR integration is **not** built; run the staging restore drill and the migration rehearsal first; the client's accountant checks tax and payroll rates; switch off public registration; email/WhatsApp are unverified until the client's accounts are tested. Pilot with one or two clients.
* **International small business (not yet):** no foreign-currency documents, no VAT categories or reverse charge, no localisation beyond English, no country pack, no e-invoice format, one gateway on fixtures, open dependency advisories. Round 2 is the minimum.
* **Enterprise (no):** no audit immutability, SSO, token scopes, load evidence, restore proof, or pen test; revenue recognition, leases, multi-GAAP and consolidation of separate entities are absent.
