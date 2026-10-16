# Progress of the remaining-modules closeout (waves 1-8)

Resume here in a new session. Rules of the round are at the top of the request in `docs/final-closeout.md` and the earlier wave docs:
no live migrations, period lock never bypassed, tenant isolation on every endpoint, a guarantee test for anything touching GL / stock,
menu migration + RolePresets + fixtures (`tests/Fixtures/live-menus.json` via the `sync-menus` helper, `role-preset-menus.json` regenerated
with a throw-away test), `npm run build`, Dusk only with the inline sqlite env, no push.

## Where it stands

| Wave | Scope | State |
|---|---|---|
| 1 | CRM, Customer Subscription, User Mgmt, Dashboard | built, see `closeout-wave1.md` (full regression pending at the time of writing) |
| 2 | Approval Center | built, see `closeout-wave2.md` |
| 3 | SaaS billing + integrations | built (couriers, gateway, bank feed, custom domain Partial), see `closeout-wave3.md` |
| 4 | Reports & analytics | built (FBR audit trail Partial = stub, scheduled email delivery Partial), see `closeout-wave4.md` |
| 5 | Documents & communication | built (PDF, QR, live delivery Partial), see `closeout-wave5.md` |
| 6 | Settings & system setup | built (rate sync, push, remote backup, cash drawer, language, Tally / QuickBooks Partial), see `closeout-wave6.md` |
| 7 | HRM & payroll | built (device sync, payslip email, bank file, gratuity Partial), see `closeout-wave7.md` |
| 1b | Dashboard rows left open by wave 1 + code-only Partials | built (daily summary delivery Partial), see `closeout-wave1b.md` |
| 8 | Manufacturing + advanced | built (late-charge invoice document, PWA install / offline, storefront payment Partial), see `closeout-wave8.md` |

## Next

All waves, including 1b, are done. `docs/tracker-sync-final.md` has every row with its status (Built / Partial, none left Not built);
the Partial rows are external services (SMTP, WhatsApp / SMS providers, FBR, couriers, payment gateway, bank, web push vendor, S3 /
Drive, devices, DNS) or named gaps (language coverage, cash drawer in the POS, form layout builder, free-position canvas, server PDF).
Live work: run the migrations of every wave in order (the closeout docs list them), then each wave's live-check list.

## Shared pieces built so far (reuse them)

`AppNotifier` + `app_notifications` (in-app and email), `Dunning` levels and templates, `JournalScheduleEngine`, `AccountsVoucher`,
`MultiCurrency`, `CostSource`, `PosOfflineSync` + service worker, `MessageDispatcher` adapters (webhook, WhatsApp Cloud, Twilio),
`EnforceSecurity` middleware, `SecuritySettings`, `ApprovalWorkflowGate`, `EnforceTenantPlan` + `PlanFeatures`, `TenantLifecycle`,
`integration_accounts` (encrypted credentials) with `Http::fake` fixtures in `tests/Fixtures/integrations/`, `InsightReportCatalog` (add a
report key there + `composables/report.ts` + menu row), `ReportBuilder` whitelist, `ReportScheduler`, `SalesKpiStatement`, `Documents\*` (`DocumentBlocks` whitelist, `DocumentRenderer`,
`DocumentTemplates`, `MessageTemplates`, `DocumentDelivery`, `DocumentReminders`, `CustomFields`, `QrCode`, `XlsxWriter`),
`Manufacturing\*` (`BomService`, `WorkOrderService`, `ProductionPlanner`, `ManufacturingPosting`), `Rental\*`, `Service\ServiceJobs`,
`Hrm\*` (`HrSettings`, `AttendanceService`, `LeaveService`, `PayrollEngine`, `PayrollPosting`, `PayslipRenderer`),
`System\*` (`BusinessSettings`, `BranchTree`, `ExchangeRateSync`, `WebPush`, `DataTransfer`, `ImportHistory`, `DataMigration`, `BackupTools`,
`DataRetention`, `SystemLogs`, `FranchiseGroups`, `SetupWizard`, `DemoCompany`, `NotificationTemplates`).

## Pitfalls met

* Heredocs containing an apostrophe break the Bash tool: write PHP files with the Write tool and scripts without apostrophes.
* A full Pest run takes about 21 minutes; Dusk needs `npm run build` first.

## UI + polish round (12 items, 6 waves) - resume here

Rules: no push, nothing on the live DB (new migrations are listed, not run), Dusk only with the inline sqlite env, `php -d memory_limit=-1 vendor/bin/pest`, Pint `--dirty`, `npm run build`, one commit per wave, payload budget green.

### Wave 1 - built (commits 225ae9e, bf04798, aa3d3de + POS / keyboard commits)

| # | Item | State |
|---|---|---|
| 1 | Invoice template CODE editor | Done: `App\Services\Documents\TemplateCode\*` (logic-less engine, HTML + CSS allowlist sanitizers, renderer, 5 ready-made templates, `docs/invoice-template-codes.md`), `document_templates.mode/code_html/code_css` + `document_template_versions` (last 10), endpoints `document-templates/code/validate`, `{id}/versions`, preview/print in a sandbox CSP; editor in Document Center with placeholders list, live preview, validate, "Use a ready-made template". Tests: `tests/Unit/TemplateCodeTest.php`, `tests/Feature/TemplateCodeTest.php` |
| 2 | POS barcode scanner | Done: scanner settings (min length, prefix, Enter/Tab suffix, key speed, beep) kept per device in localStorage, camera fallback with `BarcodeDetector` (no dependency), rescan = qty+1, unknown-code message + low tone. Partial: batch / serial pick on scan is not asked (the scan adds the product line; serial entry stays on the line) |
| 3 | Keyboard | Done: focus ring everywhere, rows of `theTable` (Up/Down/Home/End/Enter/Space), sidebar arrows, POS F2/F3/F4 payment/F8 hold/F9 new sale/F10 cash, +/-/Delete/Esc; global `/`, `?`, `g`+letter, `n`, Ctrl+S, Ctrl+K already existed. Partial: no Dusk test completes a sale + purchase + journal entry with no mouse at all |
| 6 | HRM group | Done: migration `2026_11_26_100100` renames the group HRM (Employees, Attendance, Leave Requests, Payroll, Loans and Advances, Shifts, Employee Documents, 4 HR reports, HR Settings), new addresses `/hrm/leave|loans|shifts|documents` open the hub tab and scroll to the section, "Operations" group (Manufacturing, Service Jobs, Rental, Projects, Expense Claims, Online Shop), HR Manager preset, fixtures |
| 7 | Header tools | Done: `HeaderToolsController` (`/api/header/*`), `HeaderTools.vue`: date, Today Profit, POS, Calculator, Calendar (follow-ups, leaves, due invoices, to-dos), To-Do (new `user_todos`), Application Tour. Partial: no holiday table exists, so the calendar has no holiday dots |

New migrations to run live, in order: `2026_11_26_100000_add_code_mode_to_document_templates`, `2026_11_26_100100_hrm_group_and_operations_group_menus`, `2026_11_26_100200_create_user_todos_table`.

### Wave 2 - built

| # | Item | State |
|---|---|---|
| 5 | Dashboard cards | Done: `GET /api/dashboard-extras` (`DashboardExtrasController`: today vs yesterday, receipts by payment method, branch comparison, HR headcount / attendance / leave waiting; permission gated per part, company + branch scoped, 5 minute cache) and `DashboardExtras.vue` (skeleton, empty states, responsive grid); dark theme rules for every dashboard card. The 12-month sales vs purchases, profit trend, aging, cash/bank, best sellers, top customers, expenses and approvals cards already existed |
| 4 | Demo history | Done: `php artisan demo:history [--fresh|--remove] [--force]` builds company `DEMO-HISTORY` through the real API: 12 months with Ramzan / Eid / wedding / summer seasons, monthly rent + utilities (approved), payroll, attendance (CSV import), leave requests, leads; refuses production without `--force`; `--remove` takes only that company. Screenshots: `docs/dashboard-screens/dashboard-light.png`, `dashboard-dark.png` (captured by `tests/Browser/DashboardScreenshotsTest.php`, run with `CAPTURE_DASHBOARD_SCREENSHOTS=1`) |

No new migration in wave 2.

### Round 2 (BAQI KAAM) - built

| # | Item | State |
|---|---|---|
| 1 | Crawler | `tests/Browser/MenuCrawlerTest.php` (`CRAWL=1`), table in `docs/page-crawl.md` (see its last section for the result) |
| 2 | Raw tables | 26 more list pages on theTable / ClientTable (footer slot added to theTable); 3 left in the allow-list of `NoRawTableInListPagesTest` with reasons (opening-balance grid of inputs, POS offline queue, public landing page) |
| 3 | Keyboard-only | `tests/Browser/KeyboardOnlyFlowsTest.php`: a sale at the till, a purchase and a journal entry with no mouse; the product search on 4 forms now takes the arrow keys and Enter; the result list now closes on blur (it used a template `setTimeout` that never worked) |
| 4 | POS serial / batch | the line asks for serials (typed / scanned / picked) or a batch when added and when the quantity goes up; a final sale of a serial line without enough serials reopens the prompt; `tests/Browser/PosTrackingTest.php` |
| 5 | Holidays | table `company_holidays`, HR Settings > Holidays, one-click Pakistan import (Islamic dates approximate, 2026 and 2027), dots in the header calendar; `tests/Feature/HolidaysTest.php` |
| 6 | CRUD matrix | `docs/crud-matrix.md`; the matrix found one real bug: a save on another company's voucher (and 56 other controllers) answered 500 instead of 404 - fixed |
| 8 | Known limitations | updated |

New migration to run live after the three of wave 1: `2026_11_27_100000_create_company_holidays_table` (also adds the Holidays menu row, granted to the company admin).
