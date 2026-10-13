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

I did not connect to any existing database. To prove the 63 pending migrations (from `2026_10_31_100000`) on an old-schema copy:

1. Restore a copy of the live backup into a **new, separate** database, for example `new_accounting_staging`.
2. Give me that database name (and confirm it is a copy, not live), or run this yourself with `DB_DATABASE` pointing at the copy:

```bash
php artisan migrate:status
php artisan migrate --force
php artisan migrate:status | grep -i pending
```

3. Check that no migration errors, then open the smoke URLs listed in `docs/deploy-runbook.md`.
