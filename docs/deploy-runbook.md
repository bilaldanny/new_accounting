# Deploy runbook

Scope: putting everything after the last live deploy on the live server. Nothing here was run against the live database; it was
written from the migration files, `routes/console.php`, `config/` and the closeout docs.

## 0. What is pending: ask the database, not this document

The authority is the live `migrations` table. On the live server (after the backup of section 3):

```bash
php artisan migrate:status --pending      # exact list, in the order they will run
php artisan migrate --pretend --force     # prints the SQL of every pending migration without running it
```

The tables below list every migration from `2026_10_01` onward in filename order, which is the order `migrate` runs them. Per the
repository docs, the go-live work of `2026_10_08` was live; the closeout docs say migrations from `2026_11_03_100000` onward were
**never run live**. Files between (`2026_10_09` .. `2026_11_02`) were built after the go-live check: expect most or all of them to
be pending too. Menu migrations (`add_*_menus`, `grant_*`) only insert or update `menus` / `permissions` rows and are idempotent;
they skip a row whose anchor is missing and never delete a permission that exists.

Legend: **lock** = rewrites or indexes a table that is large in production (`transactions`, `sell_lines`, `purchase_lines`,
`t_account_details`, `t_accounts`, `account_balances`, `contacts`, `products`); **data** = changes or inserts rows that are not menu
rows; **menus** = menu / permission rows only; otherwise it creates new empty tables (no risk to existing data).

### A. 2026_10_01 .. 2026_10_08 (probably live already)

| Migration | Makes | Risk |
|---|---|---|
| `2026_10_01_100000_create_leads_table` .. `_130100_add_crm_activities_menu`, `2026_10_02_100000_add_crm_analytics_menu` | CRM: leads, sources, pipeline stages, opportunities, activities and their menus | new tables, menus |
| `2026_10_03_100000_create_subscription_plans_table` .. `_100600_create_api_logs_table` | SaaS plans, coupons, subscription invoices, webhooks, API log; `2026_10_03_100100` adds billing columns to `companies` | small table alter |
| `2026_10_03_110000` .. `_130100` | billing / integrations menus and companyadmin grants | menus |
| `2026_10_04_100000` .. `_120000` | customer subscription plans, subscriptions, invoices, usages and menus | new tables, menus |
| `2026_10_05_100000_add_tags_blacklist_onhold_to_contacts_table` | three columns on `contacts` | **lock** (contacts) |
| `2026_10_05_100100_add_contact_id_to_price_lists_table`, `_200000_add_approval_columns_to_cash_collections_table`, `_200100_create_credit_limit_requests_table`, `_200200_add_approval_center_menus` | price-list customer, cash-collection approval, credit-limit requests | small alters, menus |
| `2026_10_06_100000_create_audit_logs_table` .. `_200000_grant_companyadmin_phase1_menus` | audit log, menus, grants | new table, menus |
| `2026_10_07_100000_create_pos_shifts_tables`, `_200000_create_bank_reconciliation_tables` (+ menus) | POS shifts, bank reconciliation | new tables, menus |
| `2026_10_08_100000_create_purchase_landed_costs_table` (+ menu) | landed cost tables; `landed_cost` column on `purchase_lines` | **lock** (purchase_lines) |

### B. 2026_10_09 .. 2026_11_02 (built after the go-live check; treat as pending)

| Migration | Makes | Risk |
|---|---|---|
| `2026_10_09_100000_add_analytics_report_menus` | analytics report menus | menus |
| `2026_10_10_100000_add_pricing_cluster_tables`, `_100100_..._menus` | price rules, min/max sell price columns | small alters, menus |
| `2026_10_11_100000_create_fixed_assets_tables` .. `2026_10_15_100100_add_asset_lifecycle_menus` (six pairs) | fixed assets, acquisitions, depreciation, transfers, lifecycle; `transfer_coa_id`, `disposed_on` | new tables, small alters, menus |
| `2026_10_16_100000_create_cost_centers_table` (+ menus) | cost centres; `cost_center_id` on `t_account_details` | **lock** (t_account_details: column + index + FK) |
| `2026_10_17_100000_create_budgets_table`, `_100100`, `_200000` | budgets and menus | new tables, menus |
| `2026_10_18_100000_add_warehouse_columns_to_transactions_table` (+ `_100100_add_warehouse_stock_menu`) | `warehouse_id`, `towarehouse_id` with FKs on `transactions` | **lock** (transactions) |
| `2026_10_19_100000_add_stock_movement_history_menu` | menu | menus |
| `2026_10_20_100000_create_warehouse_locations_and_capacity` (+ menus) | warehouse locations, capacity | new table, small alter, menus |
| `2026_10_21_100000_extend_taxes_for_decimal_rates_and_groups` | `taxes.percentage` integer -> decimal(8,4), group-tax columns | **lock** (`->change()`, small table), see section 4 |
| `2026_10_21_100100_add_tax_columns_to_document_lines` | `tax_id`, `tax_amount` + indexes on `sell_lines`, `purchase_lines` | **lock** (sell_lines, purchase_lines) |
| `2026_10_21_100200_add_tax_inclusive_to_transactions_table` | `tax_inclusive` on `transactions` | **lock** (transactions) |
| `2026_10_22_100000_create_tax_exemptions_table` (+ menu) | exemptions; `tax_exemption_id` + index on lines | **lock** (lines) |
| `2026_10_23_100000_add_withholding_tax_columns` | withholding columns on `transactions`, `payments`; two empty account mappings per branch | **lock** (transactions), **data** (mapping rows) |
| `2026_10_24_100000_create_fbr_tables_and_tax_registration_fields` | FBR settings and submissions (stub), STRN, tax-inclusive default | small alters on contacts, company_settings |
| `2026_10_25_100000_add_stock_tracking`, `2026_10_26_100000_add_stock_tracking_menus` | serial/batch tables; `tracking_type` on `products` | **lock** (products), menus |
| `2026_10_27_100000_repair_menus_and_grant_companyadmin_gaps`, `_100100_grant_preset_roles_their_menus` | go-live menu repair, preset role grants | **data** (menus / permissions only) |
| `2026_10_27_100200_map_withholding_tax_accounts` | creates / maps Withholding Tax Receivable / Payable accounts per branch where empty | **data**: inserts chart accounts and mappings; never touches a filled mapping |
| `2026_10_28_100000_create_blog_posts_table`, `_100100` | blog (platform) | new table, menus |
| `2026_10_29_100000_replace_legacy_placeholder_logo` | replaces the old default system logo only when it still holds the legacy file | **data** (one settings row) |
| `2026_10_30_100000_add_header_and_footer_logo_to_settings_table`, `_110000_add_is_featured_to_subscription_plans_table` | logo columns, featured flag | small alters |
| `2026_10_31_100000_create_period_locks_table`, `_100100_add_period_lock_menus` | period locks; `max_backdate_days`, `allow_future_dates` on `company_settings` | small alter, menus |
| `2026_11_01_100000_widen_account_balances_opening_balance` | `account_balances.opening_balance` integer -> decimal(15,2) | **lock** (`->change()`, rewrites `account_balances`), see section 4 |
| `2026_11_02_100000_add_returned_lines_to_transactions` | `returned_lines` on `transactions` | **lock** (transactions) |

### C. 2026_11_03 .. 2026_11_11 (documented as not run live)

| Migration | Makes | Risk |
|---|---|---|
| `2026_11_03_100000_add_contact_import_menus` | customer / supplier import menus | menus |
| `2026_11_03_110000_add_credit_limit_mode_to_company_settings` | `credit_limit_mode` | small alter |
| `2026_11_03_120000_create_contact_attachments_table`, `_130000_..._menus` | contact attachments | new table, menus |
| `2026_11_03_140000_create_portal_requests_table`, `_150000_add_portal_requests_menu` | portal requests; `portal_payment_info` | new table, small alter, menus |
| `2026_11_04_100000_create_product_catalog_extension_tables`, `_110000_..._menus` | attributes, barcodes, kits, warranty claims, consignment, discount rules; columns on `products`, `purchase_lines` (`opening_cost`) | **lock** (products, purchase_lines), menus; also turns the legacy hidden `/openingstock` rows into the real page |
| `2026_11_05_100000_create_inventory_closeout_tables`, `_110000_..._menus` | reservations, negative stock rules, tasks, write-offs, inventory close | new tables, small alters, menus |
| `2026_11_06_100000_create_procurement_closeout_tables`, `_110000_..._menus` | PO, RMA, QC, inbound shipments; column on `purchase_lines` | **lock** (purchase_lines), menus |
| `2026_11_07_100000_create_sales_closeout_tables`, `_110000_..._menus` | quotations, sales orders, riders, KPI, routes, recurring invoices, installments, cheques, messaging; column on `transactions` | **lock** (transactions), menus |
| `2026_11_08_100000_create_accounts_closeout_tables`, `_110000_..._menus` | petty cash, cheque print, guarantees, loans, tax payments, payment terms, allocation, year close; `payment_term_id` on `contacts` | **lock** (contacts), menus |
| `2026_11_09_100000_create_advance_refunds_table`, `_100100` | advance refunds, two permissions | new table, menus |
| `2026_11_09_200000_create_inventory_valuation_tables`, `_200100` | valuation methods, standard costs, locked sale-line costs | new tables, menus |
| `2026_11_10_100000_create_journal_schedule_tables`, `_100100` | journal schedules; `reversal_of_id`, `reversed_by_id` on `t_accounts` | **lock** (t_accounts), menus |
| `2026_11_10_200000_create_bad_debt_tables`, `_200100` | bad debt | new tables, menus |
| `2026_11_10_300000_create_dunning_tables`, `_300100` | dunning | new tables, menus |
| `2026_11_10_400000_create_projects_tables`, `_400100` | projects; `project_id` + index on `sell_lines`, `purchase_lines`, `t_account_details` | **lock** (three large tables), menus |
| `2026_11_10_500000_create_multicurrency_tables`, `_500100` | currency columns on `t_account_details`, switch on `company_settings`, rates, settlements | **lock** (t_account_details), menus |
| `2026_11_10_600000_create_interbranch_tables`, `_600100` | inter-branch accounts, transfers | new tables, menus |
| `2026_11_11_100000_create_pos_offline_sales_table`, `_100100` | offline POS sales | new table, menus |
| `2026_11_11_300000_add_options_to_messaging_settings` | `options` on `messaging_settings` | small alter |

### D. 2026_11_12 .. 2026_11_21 (waves 1-8 and 1b; all new tables plus menus)

| Migration | Makes | Risk |
|---|---|---|
| `2026_11_12_100000_create_wave1_core_tables`, `_100100_add_wave1_menus` | notifications, lead allocation, reminders, IP rules, login events, sessions, security settings, password history, privacy requests, dashboard preferences; `activities.reminded_at`, `users.password_changed_at` | small alters (activities, users) |
| `2026_11_13_100000_create_approval_center_tables`, `_100100` | workflows, requests, actions, delegations; `discounts.approval_status` | small alter |
| `2026_11_14_100000_create_saas_wave3_tables`, `_100100` | plan features, usage, notices, exports, support, domains, partners, add-ons, integration accounts, courier shipments, gateway payments; `companies.partner_id`, `subscription_invoices.addons_amount` | small alters |
| `2026_11_15_100000_create_report_tools_tables`, `_100100` | saved filters, custom reports, schedules | new tables |
| `2026_11_16_100000_create_documents_communication_tables`, `_100100` | templates, reminders, attachments, custom fields | new tables |
| `2026_11_17_100000_create_system_setup_tables`, `_100100` | `branches.parent_id`, business settings, push subscriptions, import runs, retention, groups, help | small alter (branches) |
| `2026_11_18_100000_create_hrm_payroll_tables`, `_100100` | HR and payroll | new tables |
| `2026_11_19_100000_create_manufacturing_tables`, `_100100` | BOM, work orders | new tables |
| `2026_11_20_100000_create_service_field_tables`, `_100100` | service jobs | new tables |
| `2026_11_20_200000_create_rental_tables`, `_200100` | rental | new tables |
| `2026_11_20_300000_create_project_work_claims_storefront_tables`, `_300100` | project work, expense claims, storefront | new tables |
| `2026_11_21_100000_create_dashboard_tools_tables`, `_100100` | daily summary, role layouts, KPI widgets | new tables |

## 1. Practical order of risk

1. Take the backup (section 3) and restore it on a staging server first. Run `migrate` there and time it: only the **lock** rows
   matter; on MySQL 8 adding a nullable column is an instant / in-place change and adding an index builds online, but
   `->change()` (taxes, account_balances) copies the table.
2. Run the real migration in a quiet window with maintenance mode on (section 3).
3. A migration that fails stops the run; migrations are not wrapped in one transaction on MySQL (DDL commits), so a failure
   leaves the earlier files applied. Fix forward from `migrate:status`; do not run `migrate:rollback` (section 2).

## 2. down() dangers, and the rollback plan

**There is no rollback plan other than restoring the backup.** Do not run `migrate:rollback` or `migrate:reset` on live.

| Migration | down() does | Why it is dangerous |
|---|---|---|
| `2026_11_01_100000_widen_account_balances_opening_balance` | `opening_balance` back to integer | **truncates cents of every opening balance**; the ledger no longer ties |
| `2026_10_21_100000_extend_taxes_for_decimal_rates_and_groups` | `percentage` back to integer, drops group-tax columns | a 0.5 % or 17.5 % rate becomes 0 / 17; grouped taxes lose their parts |
| every `dropColumn` of a data column: `2026_10_05_100000` (tags, blacklist, hold), `2026_10_08_100000` (landed cost), `2026_10_16_100000` (cost centre on vouchers), `2026_10_18_100000` (warehouses on transactions), `2026_10_21_100100` / `2026_10_22_100000` / `2026_10_23_100000` (tax, exemption, withholding on lines and payments), `2026_11_02_100000` (returned lines), `2026_11_04_100000` (`opening_cost`, tags, reorder fields), `2026_11_10_400000` (project tags), `2026_11_10_500000` (foreign amounts), `2026_11_10_100000` (reversal links) | drops the column and everything stored in it | the data is gone; `opening_cost` and `returned_lines` change stock and cost figures |
| every `create_*_tables` migration | `dropIfExists` of its tables | all rows of the feature are deleted |
| `2026_10_29_100000_replace_legacy_placeholder_logo` | nothing useful | cannot restore a replaced logo |
| menu migrations | remove the menu rows by path | permissions granted to roles on those rows go with them |

If a deploy goes wrong after migrating: maintenance mode stays on, restore the dump taken in section 3 into the live schema (or a new
schema and repoint `.env`), deploy the previous code release, bring the site up. Everything done between the dump and the restore
is lost, which is why the window is quiet and the dump is taken inside it.

## 3. Pre-deploy checklist

1. **Announce** the window; stop cron (`crontab -e`, comment the `schedule:run` line) so no command runs during the migrate.
2. **Backup**, with a binary and an output file you will check:
   ```bash
   mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 \
     -h "$DB_HOST" -u "$DB_USERNAME" -p "$DB_DATABASE" | gzip > /backups/accounting-$(date +%F-%H%M).sql.gz
   ```
   The app's own Backup page uses `config/database_backup.php` (`MYSQLDUMP_PATH`, `DB_BACKUP_TIMEOUT`); the command above is
   independent of it and is the one to trust for a deploy.
3. **Verify the restore** (never skip): create `accounting_verify`, load the dump into it, compare row counts and one total:
   ```bash
   mysql -u root -p -e "CREATE DATABASE accounting_verify CHARACTER SET utf8mb4"
   gunzip -c /backups/accounting-<stamp>.sql.gz | mysql -u root -p accounting_verify
   mysql -u root -p -e "SELECT (SELECT COUNT(*) FROM accounting_verify.transactions) a, (SELECT COUNT(*) FROM <live>.transactions) b,
                              (SELECT COUNT(*) FROM accounting_verify.t_account_details) c, (SELECT COUNT(*) FROM <live>.t_account_details) d"
   ```
   The pairs must match. Then drop `accounting_verify`.
4. `php artisan down --retry=60` (maintenance mode on). Keep your own IP allowed with `--secret=...` if you smoke-test live.
5. Pull the release, `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build` (or deploy the built
   `public/build`).
6. `php artisan migrate --pretend --force` (read the SQL), then `php artisan migrate --force`.
7. `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache`.
8. `php artisan queue:restart` (also restarts workers if you run any; the default queue connection is `database`).
9. `php artisan storage:link` if the public disk link is missing; make sure `storage/` and `bootstrap/cache/` are writable.
10. `php artisan up`.
11. Cron: restore the line `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`, then check `php artisan schedule:list`.
12. Post-deploy smoke (signed in as the company admin of the live company unless noted):
    - `/dashboard` loads and the search bar answers; `/dashboard/insights`; `/report/trial-balance` for last month ties to before the deploy;
    - `/sell` and `/purchase` list, open one invoice, print it; `/product` list;
    - `/period-lock` shows the lock you had; `/approvalcenter`; `/documentcenter`;
    - as superadmin: `/saasadmin`, `/systemcenter`; as a signed-out visitor: `/`, `/shop/<code>` (404 until a shop is opened);
    - `storage/logs/laravel.log` has no new ERROR lines.
13. The new modules (HR, manufacturing, service, rental, expense claims, shop) are invisible until the company admin is
    granted the menus (the menu migrations grant `companyadmin`; other roles are granted on the Role Permission screen or with
    `php artisan roles:sync-presets <company> <branch>`).

## 4. Environment keys

Most new features keep their credentials in the database (encrypted columns), not in `.env`. What `.env` must have:

| Key | Needed for | Default | Required? |
|---|---|---|---|
| `APP_URL` | links in mail, share links, shop address, Dusk guard | `http://localhost` | **yes** (https URL of the site) |
| `APP_KEY` / `APP_PREVIOUS_KEYS` | every encrypted column (integration credentials, SMTP password, FBR credentials, push private key); losing the key makes them unreadable | none | **yes**; keep it in the backup of secrets |
| `DB_*` | database | sqlite | **yes** (MySQL) |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | reminders, payslips, daily summary, scheduled reports, password mail | `log` (mail is only written to the log) | **yes** for any email to reach a person |
| `QUEUE_CONNECTION` | queue | `database` | run a worker only if you add queued jobs; none of the closeout features needs one |
| `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`, `SESSION_DOMAIN` | sign-in | `database`, off | set `SESSION_SECURE_COOKIE=true` under https |
| `CACHE_STORE` | dashboard, permissions cache | `database` | fine as is |
| `FILESYSTEM_DISK` | uploads (private `local` disk is used for documents, receipts, signatures) | `local` | leave `local`; documents are never on the public disk |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` | remote backup copy (the superadmin names the disk on System Center) | empty | **no**; the S3 adapter package is not installed, see `known-limitations.md` |
| `MYSQLDUMP_PATH`, `MYSQL_CLIENT_PATH`, `DB_BACKUP_TIMEOUT` | Backup / Restore page | `mysqldump` on PATH, 900 s | only if the binaries are not on PATH |
| `PUBLIC_REGISTRATION_ENABLED` | self sign-up on the landing page | `true` | **set `false` for a closed pilot** |
| `LANDING_BRAND`, `LANDING_CURRENCY_LABEL`, `LANDING_CONTACT_*` | public site text | empty | optional |
| `CSCAPI_KEY`, `CSCAPI_BASE_URL` | country / state / city sync | none | only to run that sync |
| `PASSKEYS_USER_HANDLE_SECRET` | passkeys | `APP_KEY` | optional |
| `LOG_LEVEL`, `APP_DEBUG` | logging | `debug`, `false` | `APP_DEBUG=false`, `LOG_LEVEL=warning` or higher on live |

Keys that are **not** in `.env` on purpose (set in the application, stored encrypted):
- Web push VAPID keys: System Center > Push (superadmin), `Generate keys` once.
- Stripe, PostEx, Leopards, bank feed: Integrations page (per company).
- WhatsApp / SMS provider: Messaging Settings (per company). SMTP of the app itself: Software Settings (superadmin).
- Exchange-rate feed URL and token: Business Settings (per company). FBR credentials: Tax Registration (stub, sends nothing).

## 5. Scheduler

The server cron must call `schedule:run` every minute. `php artisan schedule:list` shows the next runs. Times are the server's
timezone.

| Command | When | Does |
|---|---|---|
| `assets:run-depreciation` | 1st of the month 02:00 | last month's depreciation (existing) |
| `invoices:generate-recurring` | daily 03:00 | standing invoices due |
| `journals:run-schedules` | daily 03:30 | recurring / reversing journals, accruals |
| `customersubscriptions:run` | daily 04:00 | renewals, reminders, dunning of customer subscriptions |
| `tenants:run-billing` | daily 05:00 | usage snapshot, cancel at period end, overdue reminders, suspension, due deletions |
| `reports:send-scheduled` | daily 06:00 | emailed reports |
| `currency:sync-rates` | daily 07:00 | rate feeds of the companies that set one |
| `documents:send-reminders` | daily 08:00 | due / overdue invoice reminders |
| `hrm:document-alerts` | daily 08:30 | expiring employee documents |
| `system:apply-retention` | daily 02:30 | deletes old log rows by each company's rule (never business documents) |
| `crm:send-reminders` | hourly | CRM follow-ups due |
| `approvals:notify-pending` | hourly | opens approval requests and notifies approvers |
| `dashboard:daily-summary` | hourly | daily business summary, only companies that switched it on, once per recipient per day |

Features that are off until a company switches them on: payroll / manufacturing / rental ledger posting, maker-checker, IP rules,
password policy, daily summary, storefront, instant delivery, discount approval, multi-currency vouchers, inventory valuation
other than weighted average.
