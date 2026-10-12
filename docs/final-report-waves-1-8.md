# Final report: closeout of the remaining modules (waves 1-8 and 1b)

Scope: every Partial or Missing row of modules 1 and 8-19 of the October re-audit (`full-reaudit-2026-10.csv`): 134 rows. No migration
was run on the live database (company 1 and the demo company 2 are untouched), nothing was pushed, Dusk ran only on the throw-away
SQLite file with the `APP_URL` guard of `tests/DuskTestCase.php`.

## 1. Test numbers

| Check | Result |
|---|---|
| Pest, full suite (`php -d memory_limit=-1 vendor/bin/pest`) | **{{PEST_TESTS}} tests, {{PEST_PASSED}} passed, {{PEST_ASSERTIONS}} assertions** |
| Pest tests added by this closeout | W1 11, W2 8, W3 17, W4 21, W5 17, W6 19, W7 22, W8 38 (manufacturing 17, service 7, rental 6, projects / claims / shop 8), W1b 13: 166 |
| Dusk (real Chrome, throw-away SQLite, port 8099), full suite | {{DUSK}} |
| `npm run build` | passes (rolldown, one chunk-size warning that was there before) |
| Pint (`vendor/bin/pint --dirty`) | clean |

The Pest count before this closeout was 3,727 (end of the final closeout of 2026-10-12); the suite grew by about 150 tests, none was
deleted or changed to pass.

## 2. Commits (all on `main`, none pushed)

| Commit | Wave |
|---|---|
| `466a321` | Waves 1-3: CRM, customer subscription billing, security center, dashboard insights, approval center, SaaS billing, integrations |
| `1436ce0` | Wave 4: reports and analytics |
| `99465e0` | Wave 5: documents and communication |
| `588afcf` | Wave 6: settings and system setup |
| `cabe1fa` | Wave 7: HR and payroll |
| `b29ba97` | Wave 8, part 1: manufacturing |
| `92cd719` | Wave 8, part 2: repair and field service, the field app |
| `5376d47` | Wave 8, part 3: rental, project work, expense claims, storefront |
| {{COMMIT_1B}} | Wave 1b: dashboard rows left open, approval center gaps, drag and drop, product import history |

Each wave has `docs/closeout-wave<N>.md` (wave 8: `closeout-wave8.md`, wave 1b: `closeout-wave1b.md`) and `docs/PROGRESS.md` is current.

## 3. Migrations to run live, in this order

None has been run. Earlier rounds' migrations that are also not live (the 19 of `docs/final-closeout.md`, the module closeout and
contacts closeout lists) come first; the code tolerates absent tables, so deploying before migrating changes nothing.

| # | Migration | Wave |
|---|---|---|
| 1 | `2026_11_12_100000_create_wave1_core_tables` | 1 |
| 2 | `2026_11_12_100100_add_wave1_menus` | 1 |
| 3 | `2026_11_13_100000_create_approval_center_tables` | 2 |
| 4 | `2026_11_13_100100_add_approval_center_menus` | 2 |
| 5 | `2026_11_14_100000_create_saas_wave3_tables` | 3 |
| 6 | `2026_11_14_100100_add_saas_wave3_menus` | 3 |
| 7 | `2026_11_15_100000_create_report_tools_tables` | 4 |
| 8 | `2026_11_15_100100_add_report_tools_menus` | 4 |
| 9 | `2026_11_16_100000_create_documents_communication_tables` | 5 |
| 10 | `2026_11_16_100100_add_documents_communication_menus` | 5 |
| 11 | `2026_11_17_100000_create_system_setup_tables` | 6 |
| 12 | `2026_11_17_100100_add_system_setup_menus` | 6 |
| 13 | `2026_11_18_100000_create_hrm_payroll_tables` | 7 |
| 14 | `2026_11_18_100100_add_hrm_payroll_menus` | 7 |
| 15 | `2026_11_19_100000_create_manufacturing_tables` | 8 |
| 16 | `2026_11_19_100100_add_manufacturing_menus` | 8 |
| 17 | `2026_11_20_100000_create_service_field_tables` | 8 |
| 18 | `2026_11_20_100100_add_service_menus` | 8 |
| 19 | `2026_11_20_200000_create_rental_tables` | 8 |
| 20 | `2026_11_20_200100_add_rental_menus` | 8 |
| 21 | `2026_11_20_300000_create_project_work_claims_storefront_tables` | 8 |
| 22 | `2026_11_20_300100_add_project_work_claims_storefront_menus` | 8 |
| 23 | `2026_11_21_100000_create_dashboard_tools_tables` | 1b |
| 24 | `2026_11_21_100100_add_dashboard_tools_menus` | 1b |

Scheduler additions (the server cron must call `schedule:run`): `tenants:run-billing` 05:00, `reports:send-scheduled` 06:00,
`currency:sync-rates` 07:00, `documents:send-reminders` 08:00, `hrm:document-alerts` 08:30, `system:apply-retention` 02:30,
`customersubscriptions:run` 04:00, `approvals:notify-pending` hourly, `crm:send-reminders` hourly, `dashboard:daily-summary` hourly.
New public files to deploy: `public/manifest.webmanifest`, `public/field-sw.js`, `public/icons-field-192.png`, `public/icons-field-512.png`,
`public/push-sw.js` (wave 6).

## 4. Per-item status

All 134 rows with their exact tracker names, status, the reason when it is not Built and the test that proves it are in
`docs/tracker-sync-final.md` (built from `tracker-sync-waves-1-6.md`, `closeout-wave7.md` and `closeout-wave8.md`). Totals:

| Module | Rows | Built | Partial | Not built |
|---|---|---|---|---|
{{TOTALS}}

**Built** means built and proven by a test here. **Partial** means built, but a stated part cannot be proven without an outside
account, device or live service, or was left out on purpose (reason on every row). **Not built**: none.

## 5. Known limitations

* **Nothing was run against a live service.** Email (SMTP), WhatsApp / SMS providers, Stripe, the bank feed, PostEx / Leopards, Shopify
  / WooCommerce, the exchange-rate feed, web push vendors, S3 / Google Drive, FBR, attendance devices, DNS / SSL for custom domains
  and a physical QR scan are tested against recorded fixtures or faked clients only. Each wave's closeout doc ends with its live checklist.
* **FBR** stays a stub: nothing is sent to FBR and the audit trail says so.
* **Statutory payroll rates and tax slabs are entered by the accountant** (the application ships none); the UI says to verify them.
  Gratuity is a configured accrual, not the legal formula; the bank salary file layouts are generic.
* **Ledger effects are off by default** for payroll, manufacturing and rental and need mapped accounts; a company with no inventory
  account in its ledger keeps manufacturing posting off and uses the work-order cost figures.
* **Not written**: server-side PDF (the browser's Save as PDF from the `@page` HTML is the PDF), language coverage beyond a small
  Urdu word list, the cash drawer pulse in the POS, a form layout builder for custom fields, a free-position invoice canvas,
  an invoice document for rental late and damage charges, offline writes in the field app, online payment in the storefront,
  automatic card charge and retry in billing, machine capacity planning in manufacturing.
* Pages take customer, product and account ids as numbers in several wave 8 screens (no pickers yet).
* The full Pest run takes about 8 minutes; the Dusk suite needs `npm run build` first.

## 6. Tracker sync

`docs/tracker-sync-final.md` is the tracker table (module, item name, status, reason, test) for all 134 rows. The CSV itself was not
edited; this table is the update to apply to it.
