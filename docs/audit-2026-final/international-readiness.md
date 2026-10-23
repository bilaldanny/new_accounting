# International readiness audit (Part C)

Audit date 2026-10-18. Read-only. Scores 0-5: 0 absent, 1 token effort, 2 partial/Pakistan-only, 3 usable for a small foreign client with workarounds, 4 good, 5 market-ready.
Effort: S under 2 days, M 3-8, L 9-30, XL over 30. Evidence is a file, a count I ran, or "not found by search" (a negative I searched for, not a proof).

## Scorecard

| # | Dimension | Score | Headline |
|---|---|---|---|
| 1 | Tax | 2 | Rates, groups, compound, exemptions, inclusive/exclusive, withholding exist; no reverse charge, no jurisdictions, rounding fixed at 2 decimals |
| 2 | E-invoicing | 1 | FBR is a stub; one gateway interface exists but is Pakistan-shaped |
| 3 | Multi-currency | 2 | Optional foreign-currency voucher lines, dated rates, FX settlement; documents (invoices, bills) are base-currency |
| 4 | Localisation | 1 | Framework exists, **zero Vue files call `t(`**, 26 Urdu keys, no plural, no per-user timezone formats on printed docs |
| 5 | Accounting standards | 2 | Periodic COGS, FIFO/LIFO absent, no CoA templates per country, no lease/revenue-recognition modules |
| 6 | Payments & banking | 2 | Stripe on fixtures, OFX/MT940/CSV import; no ACH/SEPA export, no gateways beyond Stripe |
| 7 | Compliance/privacy | 2 | Contact export + anonymise, retention for logs; no consent records, no DPA/sub-processor list, no residency options |
| 8 | Security | 3 | Strong tenant/permission discipline and sweeps; dependency advisories open; no global security headers; no webhook SSRF guard |
| 9 | Accessibility | 2 | 545 aria-labels, 0 images without alt; 254 form controls with neither id nor aria-label; no automated a11y test |
| 10 | Performance/scale | 2 | Payload budget test and a few indexes; no load profile; report queries unpaged by design; queue is `database` |
| 11 | Reliability/ops | 2 | `/up`, backups, scheduler; restore never drilled, no error tracking, webhook has no retry |
| 12 | API & integration | 3 | `/api/v1` public API with rate limit, OpenAPI+Postman generated, signed webhooks; no scopes, no retry |
| 13 | UX parity | 3 | Shared table, keyboard flows, empty states exist; many English-only hard strings |

---

## 1. Tax - score 2

Evidence: `app/Services/TaxCalculator.php` (exclusive/inclusive carve-out, compound groups `TaxesApiTest:190`, customer/item exemption `TaxExemptionTest:43`), `ReturnTax.php`, withholding (`WithholdingTaxTest:224`), input/output reconciliation report. Search for `reverse charge` / `jurisdiction` in `app` and migrations: **none found**. Rounding is `round(x, 2)` in 953 places in `app/` (counted).
Gaps: 
* P0 No per-line tax *category* (standard / zero-rated / exempt / out-of-scope) with reasons, which VAT/GST returns and every e-invoice format need. M.
* P0 Rounding policy (per line vs per document, currency decimals). Today fixed 2 decimals everywhere: wrong for JPY/KWD/BHD. L (touches 953 call sites; centralise through one `Money::round($amount, $currency)` first, behind tests).
* P1 Reverse charge and import VAT. M. P1 Multiple tax jurisdictions (rate by ship-to region, e.g. US states, Indian GST CGST/SGST/IGST). L. P1 Withholding as a country pack (today Pakistan-shaped accounts). M.
* P2 Tax return (VAT 201, GST 3B) report templates per country pack. L each.
Order: money-rounding service -> tax category on lines -> country pack container -> reverse charge.

## 2. E-invoicing architecture - score 1 (proposal only, nothing built)

Existing pattern worth reusing: `FbrInvoiceGateway` interface + `StubFbrGateway` + `FbrSubmitter` + a log table; `CourierAdapter`, `PaymentGateway`, `EcommerceAdapter` follow the same shape (interface, real class, mock, "not configured" exception).
Proposal: a **country pack** = {tax categories & rates seed, CoA template, number formats, e-invoice adapter, report set}. Add `EInvoiceFormat` interface (`build(Document): Payload`, `validate()`, `submit()`, `status()`), one table `einvoice_submissions` (document, format, payload hash, response, state, attempts) generalising the FBR log, a queue job with idempotency key = document id + format.
Adapters by effort: PEPPOL BIS 3.0 (UBL 2.1 XML + access-point API; M-L, needs a certified access point account), ZATCA (Saudi; signed XML, cryptographic stamp, onboarding CSID; XL), India GST e-invoice (IRP JSON, IRN, QR; L), Mexico CFDI (PAC provider, XML 4.0; XL), Italy SDI (FatturaPA XML via intermediary; L), Pakistan FBR (spec in blocked-dossier; M-L). **The specs of all of these except FBR were not checked in this audit - unverified.** Start with PEPPOL because it covers EU/UK/AU/NZ/SG in one format.
Prerequisite P0: tax categories (dimension 1) and decimals (dimension 3) or no format validates.

## 3. Multi-currency - score 2

Evidence: `MultiCurrency.php`, `FxSettlement.php`, `DisplayCurrency.php`, `currency_rates` with dated rates, `ForeignCurrency` page, tests (final-closeout: 5). Voucher lines carry foreign amount + rate; base is posted truth; realised gain/loss at settlement.
Gaps: P0 sales/purchase **documents and payments** in a foreign currency (invoice in EUR, paid in USD) - not built (known partial #110). L. P0 per-currency decimals (0/2/3) - not present. M. P1 period-end unrealised revaluation of open items. M. P1 historical rate on documents and report-in-currency selection. M. P2 rate-feed live test (fixture only). S.

## 4. Localisation - score 1

Evidence (counts I ran): `resources/js/lang/index.ts` has `en` empty and `ur` with 26 keys; **`t('` appears 0 times in 802 Vue files**; roughly 2,263 hard-coded English-looking string literals/text nodes by a crude regex over `.vue` files (indicative only); backend validation messages are Laravel defaults in English, no `lang/` directory exists. RTL: `dir` handled in the lang module only; no RTL layout test; `rtl` appears in two files. Number/date format setting exists per company (`WaveSixSystemTest:68`) but printed documents ignore it (`items.csv` #296). Menu names live in the database (not translatable). No plural rules. Timezone: company-level.
Gaps: P0 pick one translation layer and move strings (L-XL: ~800 pages; do the 40 most used screens + POS + invoice first, M each wave). P0 backend messages to `lang/` files (M). P1 RTL stylesheet pass with logical CSS properties (L) and an RTL Dusk test (S). P1 phone/address formats (country-aware, `libphonenumber` not installed; needs approval for a dependency). P2 plural rules (ICU) after the framework.

## 5. Accounting standards - score 2

Evidence: CoA bootstrap `app/Services/Company/CompanyBootstrap.php` (one Pakistan-style chart, 3-level numeric codes); no per-country template; costing: periodic weighted average, FIFO layers only from a date (`InventoryValuationTest:70`), LIFO not offered, no perpetual COGS posting (known-limitations); fixed assets with depreciation methods, revaluation/impairment (`FixedAssetLifecycleTest`); no lease (IFRS 16) module; no revenue-recognition schedules beyond prepaid/deferred journal schedules; fiscal year and period lock exist (`YearRolloverTest`); multi-GAAP: none.
Gaps: P0 perpetual inventory option with COGS posting (L). P0 CoA templates by country/GAAP selectable at company creation (M each, accountant-reviewed). P1 revenue recognition (deferred revenue schedules) M; lease accounting L; consolidation of separate legal entities L (inter-company partial #117). P2 multi-GAAP books XL.

## 6. Payments & banking - score 2

Evidence: `StripeGateway` (fixtures), `PaymentGateway` interface, `BankStatementParser` (CSV/OFX/MT940 tests), `HttpJsonBankFeed`, reconciliation with auto-match. No search hit for SEPA/ACH/camt/Plaid in `app/` except OFX/MT940 mentions.
Gaps: P0 a second/third gateway through the same interface (PayPal, Adyen/regional such as Razorpay, Paystack, M-Pesa - **their APIs not checked**); M each. P0 camt.053 + BAI2/ACH and SEPA pain.001 exports for supplier payment batches. M each. P1 Open Banking/Plaid feed adapters. L. P1 PK wallets/PayFast. M each (specs unverified). P1 payment reconciliation with gateway payout reports. M. PCI: no card data fields exist in the schema as far as `grep` shows; the Stripe flow is hosted Checkout (SAQ-A style) - confirm by review before claiming.

## 7. Compliance / privacy - score 2

Evidence: `WaveOneTest:274` contact export + anonymise (books keep the record), `system:apply-retention` for log tables only, `audit_logs` with field diffs (rows are ordinary table rows: **not immutable**; an admin with DB access can alter them; no hash chain), per-tenant `company_id` isolation (single database, shared schema).
Gaps: P0 consent records and a lawful-basis field; DSAR flow for users (employees, portal users), not only contacts. M. P0 sub-processor list + DPA template (documents, S, owner). P1 audit log immutability (append-only table privileges or hash chain, M). P1 data residency: only "run a separate deployment per region" is possible; document it (S) and parametrise storage (M). P1 retention for business data (accounting retention laws vary). M. P2 cookie banner on the public site (S).

## 8. Security - score 3

Done in repo: route sweep and 25-public-route review (`docs/security-sweep.md`), tenant scope tests per module, throttles (`throttle` 23 occurrences in routes), encrypted credential columns, upload type checks, template CSP on user-supplied designs, signed webhooks (HMAC SHA-256, `DispatchWebhook.php:48`), API keys act as the owner (menu permission check added).
Fresh findings (this audit):
* **Dependencies (ran `composer audit`, `npm audit --omit=dev`):** composer reports 16 advisories in 5 packages (including `laravel/framework` low, `league/commonmark` medium/high, `symfony/yaml` CVE-2026-109413); npm reports 8 (6 high): `vue`, `@vue/server-renderer`, `axios` (listed although "Axios removed" in Inertia v3 - still a dependency), `nanoid`, `source-map-js`, `moment`, `dompurify`, and `xlsx` (prototype pollution + ReDoS, **no fix available**). P0: update what has fixes (S-M, needs owner approval to change dependencies); replace or sandbox `xlsx` for imports (M). Per-package detail: rerun the commands.
* P0 No global security headers: CSP/HSTS/X-Frame/X-Content-Type are set only on a few document responses (search of `app`, `bootstrap`, `config`). Add a middleware (S) and test.
* P0 Webhook delivery has no SSRF guard (no private-address filter in `DispatchWebhook.php`) and **no retry/backoff** (`tries` not set). S-M.
* P1 `.env.example` ships `APP_DEBUG=true` and `PUBLIC_REGISTRATION_ENABLED=true`. S (change defaults).
* P1 2FA: Fortify supports it and login is tested; there is no "require 2FA for admins" policy (search of `EnforceSecurity`). S-M.
* P1 296 `DB::raw`/`whereRaw`/`selectRaw` call sites: not individually reviewed. M. 6 `v-html`/`{!!` uses: not reviewed, S.
* P1 Backup files are not encrypted by the app (no crypt call in `DatabaseBackups.php`). S.
* P1 Tenant isolation fuzz: tenant tests are per module; I did not run a generic per-endpoint cross-company fuzz. Proposed harness: iterate `route:list` (1,761 API routes), call each with a second company's ids as a non-admin, expect 403/404; M, high value.
* Session fixation: Laravel regenerates the session on login by default (not separately tested here).
* Pen-test readiness: 3 of 5: sweeps exist, no external test yet; fix the P0s above first.

## 9. Accessibility - score 2

Scan (grep, no new dependency): 545 `aria-label` uses; 0 `<img>` without `alt`; 254 `input/select/textarea` tags with neither `id` nor `aria-label` (may still be wrapped by labels - indicative). Keyboard flows are tested (`KeyboardOnlyFlowsTest`), focus traps/contrast untested; dark theme overrides exist but contrast is unmeasured. Proposal: a Dusk-free scan script using `@axe-core/playwright` is a new dependency (needs approval); until then a Node script over the crawled HTML in `docs/page-crawl` for missing labels/landmarks (S). P1 WCAG 2.1 AA pass of the 25 most-used pages (L). P2 accessible tables (header scope) via `theTable` once (S).

## 10. Performance / scale - score 2

Evidence: `PagePayloadBudgetTest`, `docs/page-payload-sizes.md` (a 150 KB shared prop was cut), four analytics indexes, ledger-based reports compute live. 345 migrations; an index-count regex found few explicit `->index(` forms (my regex was blunt - verify with `SHOW INDEX`). Queue is `database` driver, one `DispatchWebhook` job; 14 scheduled commands run in-process (sequential per tick).
Estimates (reasoned, **not measured**): 100 tenants x 1M ledger rows = 100M rows in `t_account_details` in one schema; the trial balance, ledgers and balance sheet are live aggregations and will exceed web time limits for the large tenants unless account balances are materialised by period. P0 materialised period balances (L). P0 composite indexes `(company_id, ...)` on the big tables from `EXPLAIN` of the five heaviest reports, on a MySQL copy (M). P1 N+1 sweep with `Model::preventLazyLoading` in tests (M). P1 move queue to Redis + Horizon (S ops). A local load profile was **not run** in this audit: a faithful one needs MySQL with seeded volume; the sqlite test DB would mislead. I recommend `php artisan` seeders for 100k/1M rows then timing the 10 slowest reports (M).

## 11. Reliability / ops - score 2

Evidence: `/up` health route, backups (`BackupApiTest`), scheduler list in `routes/console.php`, idempotent offline sync keyed by client UUID (`OfflineSyncTest`), runbook with lock-heavy migration list (`docs/deploy-runbook.md` marks `contacts`, `purchase_lines` etc.). Gaps: P0 restore drill never run (blocked-dossier 8). P0 error tracking/alerting (Sentry-like; dependency or log shipping). S-M. P1 failed-job handling: `failed_jobs` config exists, no alert. S. P1 zero-downtime migrations: use online schema change for the listed lock-heavy ones (contacts tag columns, purchase_lines landed cost); M. P1 webhook retry (see 8). P2 upgrade path doc for the 165 pending migrations exists.

## 12. API & integration - score 3

Evidence: `routes/api.php:1950` `v1` group (auth:sanctum, `throttle:public-api`, request log), `ApiDocGenerator` produces OpenAPI 3.0.3 and Postman (request bodies free-form, tracker note), `PublicApiTest:111` rate limit, API keys per owner. Gaps: P1 token scopes/abilities (search for `tokenCan`/abilities: none) - keys act with the owner's full menu rights. S-M. P1 OpenAPI request/response schemas from Form Requests. M. P1 webhook retry + delivery log UI + replay. M. P2 OAuth2 client credentials for partner apps. L. P2 SDK generation after schemas. S.

## 13. UX parity - score 3

Evidence: shared `theTable.vue`, empty-state handling, keyboard shortcuts, first-run Setup Wizard (`WaveSixSystemTest:564`), import undo for three entities, crawler of every menu page (`docs/page-crawl.md`). Gaps: P1 undo/restore beyond trash for edits; P1 mobile layouts (crawler measures overflow; no touch test); P1 error-message quality review (backend strings in English only); P2 onboarding checklist per country pack.

---

## Order of work (what to do, how big, in which sequence)

1. **Round 2 (P0):** dependency + header + webhook hardening (S-M) -> money rounding service and per-currency decimals (M-L) -> tax category on lines (M) -> document-level foreign currency (L) -> translation layer on top-40 screens + backend messages (L) -> perpetual inventory COGS option (L) -> consent/DSAR/sub-processors (M) -> restore drill + error tracking (S-M).
2. **Round 3 (P1):** country-pack container with CoA templates -> PEPPOL adapter -> tax jurisdictions/reverse charge -> RTL -> bank file exports (camt/pain.001/ACH) -> materialised balances + index work with a MySQL load profile -> token scopes + webhook retry -> audit-log immutability.
3. **Later (P2):** other e-invoice formats (ZATCA, India, CFDI, SDI), multi-GAAP, OAuth2, SDKs.
