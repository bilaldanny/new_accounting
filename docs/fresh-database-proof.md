# Fresh database proof (integration checkpoint, part 2)

## What was proven

| Check | Result |
|---|---|
| `php artisan migrate:fresh --seed --force` on an empty sqlite file, all 334 migration files | passes, 0 pending afterwards |
| Migrations ran in filename (timestamp) order | asserted by `tests/Feature/FreshDatabaseTest.php` |
| Migration names well formed, no accidental duplicate names | asserted (one reviewed duplicate: `add_approval_center_menus`, two anonymous classes with different timestamps) |
| Default seeder works on a fresh database | fixed: `DatabaseSeeder` wrote a `name` column that does not exist, and `UserFactory` omitted the legacy NOT NULL `users.pass` column |
| Tenant isolation of every model that has `company_id` | see below |

Every Pest test also starts from a fresh migration run (RefreshDatabase), so the suite itself exercises this on every run.

## Company scope on models: what is true

There is **no Eloquent global scope**. Isolation is applied per model through `visibleToCurrentUser()` and per controller through
`scopeCompanyId()` / `reportScope()`. The ratchet test lists every model whose table has `company_id`:

- with a `visibleToCurrentUser` scope: the main business models
- without one: 27 child / settings rows (`tests/Feature/fixtures/tenant_models_without_scope.php`), each reached through a scoped
  parent or filtered by the controller (reviewed in `docs/security-sweep.md`)

A new tenant model that has neither fails the test until someone reviews it.

## Old-schema (staging) proof: needs you

I did not connect to any existing database, and the smoke test (`docs/smoke-test-report.md`) ran on a fresh sqlite database only. To prove the pending migrations (114 files from `2026_10_09_100000` if the go-live of `2026_10_08` is live; the exact list is in `docs/deploy-runbook.md`, section 0) on an old-schema copy, **you** run this (nothing here was run):

1. Restore a copy of the live backup into a **new, separate** database, for example `new_accounting_staging`.
2. Give me that database name (and confirm it is a copy, not live), or run this yourself with `DB_DATABASE` pointing at the copy:

```bash
# 0. on the machine that holds the COPY only: .env must point at the copy (DB_DATABASE=new_accounting_staging), never at live
php artisan config:clear
php artisan migrate:status --pending              # the list and the count that will run
php artisan migrate --pretend --force > migrate-pretend.sql   # read it; look for ALTER on transactions, t_account_details, sell_lines, purchase_lines
time php artisan migrate --force                  # note the time of the lock rows (runbook section 0 legend)
php artisan migrate:status --pending              # must print nothing
php artisan install:baseline-menus --dry-run      # adds nothing the dump already has
php artisan roles:sync-presets <company_id> <branch_id>   # grants the new pages to the preset roles of one company
php artisan customersubscriptions:refresh-analytics
php -d memory_limit=-1 vendor/bin/pest tests/Feature/FreshDatabaseTest.php   # (uses its own sqlite, safe)
```

3. Check that no migration errors, then open the smoke URLs listed in `docs/deploy-runbook.md`.

## Setting up a new install

```bash
php artisan migrate --force                 # all tables and the menus added since the first release
php artisan install:baseline-menus          # the menu rows the original dump would have given (add --dry-run to count first)
php artisan plans:seed-defaults             # optional: Starter / Growth / Business packages for the sign-up page
```

What `install:baseline-menus` does: for every menu path the code checks or serves that has no menu row yet
(`database/data/baseline_menus.php`, 600 paths on a migrations-only database) it adds a row under the nearest existing parent, and it
creates the shared `companyadmin` role if it is missing and gives it every company-level menu (never the platform menus: tenants,
subscription plans, SaaS admin). It only adds; a menu that exists is never touched, so on a database that has the old dump it adds
only what the dump never had. The names it gives are plain (taken from the path); the sidebar layout of an old install is better.

After that, a company is created by public registration or on the Company page, and both now run the same `CompanyBootstrap` step:

| Made for every new company | Source |
|---|---|
| company, first branch, settings row, withholding tax accounts | `Company::createCompany`, `Branch::createCompanyBranch` (unchanged) |
| admin user on the shared `companyadmin` role | `SelfSignup::companyAdminRole` (the Company page used to create an empty role of its own) |
| the starter staff roles (Accountant, Cashier, Sales Representative, Manager, Warehouse Keeper) | `RolePresets::sync` (the Company page did not run it) |
| an open financial year (July to June) | `CompanyBootstrap` |
| chart of accounts (33 groups and ledger accounts) and every posting key mapped (customer, supplier, bank, cash, purchase, sale, tax, profit and loss ...) | `CompanyBootstrap` |
| first unit, brand, category, item type, so a product can be saved | `CompanyBootstrap` |

`CompanyBootstrap` only fills what is absent: a company with any account beyond the top level (and the two withholding accounts) keeps
its chart and mappings, a company with a financial year keeps it, and it is never run on an existing company by itself
(`tests/Feature/CompanyBootstrapTest.php` guards both).

### What stays manual

- The accountant reviews the starting chart and renames or adds accounts; opening balances are entered by the accountant.
- Tax rates, withholding rates and payroll statutory rates and tax slabs (the application ships none).
- Banks, warehouses beyond the head office, price lists, users and their roles, document numbering and print layouts.
- A new company starts with journal approval on: the journal is approved by someone with the approve menu before it is in the books.

### Known report difference

The balance sheet takes its "profit to date" from the ledger, and purchases post to the Purchases account (no inventory account is
posted), so it differs from the profit and loss net profit by the change in stock value. Both are internally balanced (the sheet's
difference line is 0.00). Aligning them needs an inventory line and an opening-stock equity entry; that is an owner decision (see
`reports-decisions`).

## Pilot demo company

```bash
php artisan demo:pilot            # build DEMO-PILOT (does nothing if it already exists)
php artisan demo:pilot --fresh    # remove it, then build it again
php artisan demo:pilot --remove   # remove it
```

It only ever creates, finds or removes the company coded `DEMO-PILOT` and refuses to run when the environment is production unless
`--force` is given. The admin password is printed once at the end of a build.

## Showcase companies (a busy platform)

```bash
php artisan demo:showcase                                   # 4 companies, 4 months, 30 sales and 10 purchases a month each
php artisan demo:showcase --companies=6 --months=6 --sales=60 --purchases=15
php artisan demo:showcase --fresh                            # remove the showcase companies, build again
php artisan demo:showcase --remove
```

Up to six separate tenants (`DEMO-SHOW-1` to `-6`: a grocery, a mobile and electronics shop, a garments house, a pharmacy, ...), each with
its own customers, suppliers and catalogue, months of purchases, sales, returns and payments (posted through the real API, dated up to
today so the current month is partly filled), and a payroll run for every full past month. The default builds about 450 sales in under a
minute. It never touches a real company, refuses production without `--force`, and a second run changes nothing. Each company's admin
password is printed once.
