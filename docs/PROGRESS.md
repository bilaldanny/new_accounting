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
| 8 | Manufacturing + advanced | built (late-charge invoice document, PWA install / offline, storefront payment Partial), see `closeout-wave8.md` |

## Next

All eight waves are done. What is left is code work that the tracker audit of waves 1-6 exposed: the six Dashboard rows (role dashboards,
date / branch / warehouse filters, widget builder, notification centre, daily business summary, universal search), an approver picker
and department trigger in the Approval Center, price-list approval in the workflow registry, a drag-and-drop canvas for the
document designer, language coverage, the cash drawer read by the POS. They are listed in `docs/tracker-sync-waves-1-6.md`
("Follow-up the table exposes") and none was started. Live work: run the migrations of every wave in order (the closeout docs list them),
then each wave's live-check list.

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
