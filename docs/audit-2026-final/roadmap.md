# Roadmap: next three rounds (Part E)

Rules for every round (carried from earlier rounds): no push, no live migration unless the owner says so in that prompt, period lock never bypassed, tenant isolation test per endpoint, a guarantee test for anything touching ledger or stock, `vendor/bin/pint --dirty`, targeted Pest only plus one full run at the end (`php -d memory_limit=-1 vendor/bin/pest`), Dusk only on sqlite with the `APP_URL` guard. Dependency changes only where the prompt names them.

## Round 1 - Live verification (1-2 weeks, mostly owner and client time)

Goal: turn Partial rows into Built with real outside accounts. No new features.
Prompts (paste one at a time):
1. **Restore drill and migrations rehearsal.** Scope: copy of live DB on a staging MySQL; run `migrate --pretend`, then migrate the 165 files; time lock-heavy ones; restore a backup into an empty DB and compare trial balance. Risks: data on staging; do not touch live. Tests: add a `docs/` evidence file, no code.
2. **Email + WhatsApp/SMS live check.** Scope: blocked-dossier sections 3-4 checklists with the client's credentials in staging `.env`. Risks: real messages to real people (use own numbers). Tests: screenshots + Communication log rows saved in `docs/live-verification/`.
3. **Stripe test mode.** Scope: dossier section 2 checklist. Risks: keys in logs. Tests: fixture tests updated with a real recorded webhook.
4. **FBR sandbox (only if the client is under DI/POS rules).** Scope: owner supplies the spec PDF and sandbox token; implement the real gateway behind `FbrInvoiceGateway`, validate/post, error mapping; keep the stub default. Risks: compliance claims - none to be made; production token never stored by us. Tests: recorded sandbox responses, idempotency, retry.
5. **Security hygiene.** Scope: `composer update` of the advisories with fixes, `npm audit fix` for fixable packages, replace or isolate `xlsx`, add global security headers middleware, webhook SSRF guard + retry/backoff, `.env.example` safe defaults. Needs explicit approval to change dependencies. Tests: header test, SSRF refusal test, retry test, full suite.
6. **Test the untested.** Scope: add Pest tests for `/api/hrm/shifts|roster|components|rates|tax-slabs`, POS parked sales (server-side persistence decision first), `/api/saas/addons`; then re-grade the rows. Tests: those.

## Round 2 - International P0 gaps (2-4 weeks)

1. **Money service and currency decimals.** Scope: one `Money` helper, per-currency decimals, replace the 953 `round(,2)` sites in bounded batches by module; Risks: ledger totals drift - guarantee tests comparing before/after on the demo companies. Tests: rounding matrix 0/2/3 decimals, balanced vouchers.
2. **Tax categories and country-pack skeleton.** Scope: tax category (standard/zero/exempt/out of scope) on lines, reason codes, country pack seed container (CoA template, tax seed, number formats). Risks: report changes; keep Pakistan pack identical (guarantee test).
3. **Foreign-currency documents.** Scope: invoice/bill/payment in a document currency with rate, base posting, realised FX via `FxSettlement`, period-end unrealised revaluation. Risks: biggest ledger change; off by default. Tests: FX scenarios, period lock.
4. **Translation layer.** Scope: choose the library (no new dependency without approval; the existing `t()` can be used), move the top 40 screens, POS, invoice, backend validation strings to `lang/`; RTL logical CSS. Risks: regressions in Dusk selectors that read text. Tests: key-coverage test (no hard-coded text in migrated files), RTL screenshot.
5. **Perpetual inventory option.** Scope: COGS posting per sale behind a company switch plus LIFO decision. Risks: stock/ledger divergence; Tests: guarantee tests both ways, valuation matrix.
6. **Privacy pack.** Scope: consent record, user DSAR export/anonymise, sub-processor and DPA templates in `docs/`, retention for business data as settings. Tests: export content, anonymise keeps ledger.
7. **Ops.** Scope: error tracking hook (log channel first), failed-job alert, backup encryption, restore script. Tests: command tests.

## Round 3 - International P1 (4-8 weeks)

1. **PEPPOL BIS 3.0 adapter** on a generalised `EInvoiceFormat` interface + `einvoice_submissions` table + queue job (spec to be read first; access-point account needed from the owner). Tests: UBL golden files validated with the official schematron where licence allows.
2. **Tax jurisdictions, reverse charge, import VAT, return templates** for one pilot country chosen by the owner.
3. **Bank files:** camt.053 import, pain.001 SEPA and ACH/NACHA export, second payment gateway through `PaymentGateway`.
4. **Scale:** MySQL load profile with seeded 100k and 1M ledger rows, materialised period balances, index work from `EXPLAIN`, Redis queue; target budgets agreed first. Tests: timing assertions as a non-blocking benchmark script.
5. **API:** token abilities, OpenAPI schemas from Form Requests, webhook delivery log + replay.
6. **Accessibility pass** of the 25 busiest pages with a scan script, then fixes; RTL Dusk test.
7. **Audit-log immutability** (append-only or hash chain) and a generic tenant-isolation fuzz harness over `route:list`.
