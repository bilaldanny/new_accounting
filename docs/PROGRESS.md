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
| 4 | Reports & analytics | not started |
| 5 | Documents & communication | not started |
| 6 | Settings & system setup | not started |
| 7 | HRM & payroll | not started |
| 8 | Manufacturing + advanced | not started |

## Next

Wave 4 (Reports & analytics, 17 items): comparative P&L / balance sheet with inventory line from the active valuation method, indirect
cash flow, sales by salesperson / warehouse / payment method, commission + target, forecasting, stock take, advance, withholding tax,
tax exemption, tax liability reconciliation, FBR audit trail (FBR stays STUB), profit by customer / branch / salesperson / warehouse,
dashboard analytics, custom report builder (whitelisted datasets), scheduled / emailed reports, saved filters + favourites.

## Shared pieces built so far (reuse them)

`AppNotifier` + `app_notifications` (in-app and email), `Dunning` levels and templates, `JournalScheduleEngine`, `AccountsVoucher`,
`MultiCurrency`, `CostSource`, `PosOfflineSync` + service worker, `MessageDispatcher` adapters (webhook, WhatsApp Cloud, Twilio),
`EnforceSecurity` middleware, `SecuritySettings`, `ApprovalWorkflowGate`, `EnforceTenantPlan` + `PlanFeatures`, `TenantLifecycle`,
`integration_accounts` (encrypted credentials) with `Http::fake` fixtures in `tests/Fixtures/integrations/`.

## Pitfalls met

* Heredocs containing an apostrophe break the Bash tool: write PHP files with the Write tool and scripts without apostrophes.
* A full Pest run takes about 21 minutes; Dusk needs `npm run build` first.
