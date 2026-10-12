# Closeout wave 6: Settings and system setup (22 items)

Evidence: `tests/Feature/WaveSixSystemTest.php` (19 tests, including the worked example of RFC 8291 byte for byte), fixtures in
`tests/Fixtures/system/` (rate feed answer, Tally ledger export, QuickBooks contact list) and the page-load Dusk tests of
`/businesssettings`, `/setup`, `/help`, `/franchise` and `/systemcenter`.

Migrations to run live, in order (none has been run):

1. `2026_11_17_100000_create_system_setup_tables` (`branches.parent_id`, `business_settings`, `notification_templates`,
   `push_subscriptions`, `system_settings`, `import_runs`, `data_retention_rules`, `company_groups` + members, `help_articles`)
2. `2026_11_17_100100_add_system_setup_menus` (Business Settings + manage + import, Setup Wizard, Help Center, System Center,
   Franchise Overview)

Scheduler: `currency:sync-rates` daily 07:00, `system:apply-retention` daily 02:30 (the server's cron must call `schedule:run`).

## What changes for existing screens

* Every page now receives the company's `numberFormat` and `locale` as shared props. The front end `formatNumber` follows them
  (the default is the format used before: nothing changes for a company that never saves one).
* `ContactImporter` rows carry the new `contact_id` and the customer / supplier import records a history run on a real import
  (a dry run records nothing). `TenantLifecycle::purge()` became public (used to remove a demo company).
* `AppNotifier` consults the company's notification template of that kind before storing a notification.
* Customer, supplier and product pages show a Custom fields panel when the company defined fields for them.

## The items

1. **Branch hierarchy: Built.** Parent per branch, tree and move API, cycle and cross-company moves refused. Reports, permissions and
   numbering are unchanged and still per branch (no roll-up by subtree yet; the branch add / edit forms do not show the parent).
2. **Exchange rate sync: Partial.** HTTPS JSON feed (URL with `{base}`, optional encrypted token, dotted path to the rates), writes the
   display rate and a dated history row, skips unknown / zero / non-numeric rates, and a failed feed changes nothing and records why.
   Tested against a recorded answer only; a real provider has not been called.
3. **Number formats: Built, with a stated limit.** Decimals, separators, western or indian grouping, minus or brackets, per
   company; applied by `formatNumber` on screens and `BusinessSettings::format` on the server. Printed documents and pages
   that format numbers on their own still use the standard format.
4. **Multi-language framework (English + Urdu): Partial.** `resources/js/lang`, `t()`, direction switch, company default and a
   per-browser choice, and an Urdu word list for common words. Menu names come from the database and almost every page is
   still English; invoices already print in Urdu (wave 5).
5. **POS settings consolidation: Built.** One Business Settings page holds the POS extras and summarises (with links) the other
   settings; the existing pages keep their own settings.
6. **Cash drawer setting: Partial.** The setting (never / cash sale / every sale, pulse command) is stored and validated. The POS does
   not read it yet and no hardware pulse was tested.
7. **Notification templates: Built.** Wording per notification kind with `{title}` `{body}` `{url}` `{company}`.
8. **SMS / WhatsApp gateway settings: Built.** Already in place (Messaging Settings with webhook, WhatsApp Cloud, Twilio adapters
   and fixtures from the earlier rounds); not changed here.
9. **Web push (VAPID): Partial.** Own VAPID keys (private key encrypted), subscription storage, RFC 8291 message encryption (matches
   the RFC's worked example exactly), RFC 8292 JWT (signature verified), sending with removal of gone subscriptions, a push
   service worker and the browser subscribe flow. Not run against a real browser vendor's push service.
10. **Import / export center: Built.** CSV and Excel export of customers, suppliers, products and the chart of accounts; CSV and Excel
    column templates of the importers; links to the sale and purchase importers.
11. **Import history with undo: Built for customers and suppliers.** Create-only runs; undo removes only untouched records
    (sales, purchases, payments and accounting entries keep a record) and reports what it kept. Other importers are not hooked.
12. **Cloud backup (S3 / Google Drive): Partial.** The superadmin names any configured remote filesystem disk (local and public are
    refused) and copies a backup to it. S3 and Google Drive adapter packages are not installed (a dependency change needs approval).
    Restore (superadmin, typed `RESTORE <file>`, safety backup first, stops if it fails) is tested with an injected runner; the
    real `mysql` client run has not been tried.
13. **Retention / archiving: Built.** Audit, API, sign-in, notification and reminder logs only; company or platform rule, minimum 30
    days, optional gzipped CSV archive before deletion; never a business document, voucher or stock row.
14. **System / error log viewer: Built.** Superadmin only; newest first, level and text filters, secrets masked, traces cut.
15. **Custom fields and forms builder: Built (fields), no layout builder.** Definitions with type, options, required and print flag;
    values on sales, purchases, contacts and products; panels on the sale, customer, supplier and product pages.
16. **Franchise / multi-owner group: Built.** Separate tenants stay separate; the named owner sees per-company month figures
    (no query spans companies); a total only when every member uses one currency; a company is in one group at most.
17. **Consolidated business settings: Built, with a stated limit.** Number format, POS and language are edited here; company,
    regional, document and messaging settings are shown as a summary with links and still edited on their own pages.
18. **Setup wizard: Built.** The onboarding steps plus currency, tax, print template, messaging and number format, each with its
    page; skips are remembered.
19. **Data migration tool: Built for the template; Tally and QuickBooks Partial.** The mappers read the usual columns of a Tally ledger
    export (Debtors / Creditors, Dr / Cr) and a QuickBooks contact list, but those layouts vary by version and report; the tool
    lists unmatched columns and checks before it saves. Contacts only (no products or opening stock yet).
20. **Demo / sandbox company: Built.** A separate `DEMO-` company through the normal sign-up path with sample customers,
    suppliers and products and no transactions; the password is shown once; only a `DEMO-` company can be removed.
21. **Help center and support tickets: Built.** Published articles with search (plain text, markup stripped), written by the
    superadmin; questions go to the existing support tickets.
22. **FBR settings (STUB): Built, unchanged.** The existing Tax Registration card and FBR settings stay a stub; nothing is sent to FBR.

## Live checks

* Point a company at one real rate provider and run `php artisan currency:sync-rates --company=<id>`.
* Subscribe one browser (Business Settings > Turn on for this browser) and use the test button.
* Configure one remote disk and copy a backup; restore only on a copy of the database first.
* Export a real Tally ledger list and a QuickBooks contact list and use "Check the file".

## Known limitations

* No branch roll-up in reports, no parent field on the branch form.
* Document prints and pages with their own number code ignore the company number format.
* The language framework covers a small word list.
* Cash drawer: stored only.
