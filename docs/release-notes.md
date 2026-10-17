# Release notes

One line per item. Everything here is on the `main` branch and **not pushed**; nothing was run against the live database.
What the system does not do, and what stays manual, is in [known-limitations.md](known-limitations.md); how to put it on the live
server is in [deploy-runbook.md](deploy-runbook.md) (114 migrations pending if the go-live of `2026_10_08` is live, 165 since
`2026_10_01`).

## FBR is a stub

**The FBR e-invoicing page is a stub. It stores settings and logs, sends nothing to FBR and the system is not FBR compliant.**
Do not tell a client otherwise ([known-limitations.md](known-limitations.md), first section).

## New in the last two rounds

* **Notification sound switch** in the top bar (per user, saved on the account, default on); the POS scan beep keeps its own setting.
* **Offline mode**: sales invoice, purchase, payment / receipt, expense, new customer / supplier and stock adjustment (sent as pending)
  can be entered with no internet, get a provisional `OFF-<device>-<n>` number, and are sent in order, once each, when the network
  returns, through the real document code (same validation, permissions, period lock, credit limit, stock and tax as online).
  A refused entry waits on the Offline entries page with the reason: edit and retry, or discard (reason, confirmation, audit log).
  Header chip: Online / Offline / Syncing / Conflicts. The Offline POS terminal uses the same engine. Design: `docs/offline-mode-design.md`.
* **Subscription Analytics is fast**: the page reads a daily snapshot (rebuilt by `customersubscriptions:refresh-analytics`, and
  dropped the moment a subscription, invoice or plan changes, so the figures are always the report's own) and four indexes serve the
  queries that remain.
  Query plans (SQLite, same index definitions): before every query was `SCAN customer_subscriptions` / `SCAN customer_subscription_invoices`
  (the two group-bys also `USE TEMP B-TREE`); after: summary counts, due-in-7-days, churn, MRR, LTV and monthly revenue are all
  `SEARCH ... USING (COVERING) INDEX cs_company_status_*_idx / csi_company_status_*_idx`.
* Earlier rounds, in short: crawler of every menu page for three roles (`docs/page-crawl.md`), list pages on the shared table component,
  keyboard-only till / purchase / journal flows, serial and batch prompts at the POS, holidays with a Pakistan import, an HR and an
  Operations sidebar group, header to-do list, custom invoice templates written as code, balance sheet stock line and the sell-return fix.

## What is not new / not done

* No real payment gateway for customers; no e-mail until `MAIL_*` is configured; no remote backup copy (S3 adapter not installed).
* Connections to couriers, WhatsApp / SMS, Stripe, bank feeds and exchange-rate feeds were never tested against the real service.
* Offline mode does not cache ordinary pages (only the Offline entries page, the POS terminal and the build files); stock, price and
  credit checks happen when an entry reaches the server, so an entry can be refused later; everything else is online-only and shows a
  friendly "needs internet" screen.

## What is manual

* Running the migrations on the live server (runbook section 3), after a backup and a verified restore.
* Granting the new pages to roles: the `/offline` and `/offline/resolve` menu rows exist after the migration, then run
  `php artisan roles:sync-presets <company> <branch>` or tick them on the Role Permission screen.
* Cron for `schedule:run` (new command: `customersubscriptions:refresh-analytics` daily 04:30).
* Setting `APP_URL`, `MAIL_*`, `SESSION_SECURE_COOKIE=true` and `PUBLIC_REGISTRATION_ENABLED=false` for a closed pilot.
* Payroll rates, tax rates and the FBR credentials are entered by the client's accountant.
* The migration rehearsal on a copy of the old schema (`docs/fresh-database-proof.md`).
