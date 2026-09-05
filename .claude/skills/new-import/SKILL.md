---
name: new-import
description: Scaffold a new bulk-import feature (controller import() method, validation rules, model upsertFromImport, and a {Resource}ImportTest.php) following this app's established bulk-import-pattern. Invoke with the resource name, e.g. "/new-import Warehouses".
disable-model-invocation: true
---

# Scaffold a New Bulk Import

Read [bulk-import-pattern](../bulk-import-pattern/SKILL.md) first — it documents the exact request shape, upsert convention, response envelope, and test structure this scaffold must follow. Do not deviate from it.

## Steps

1. **Identify the target resource** from the argument passed to this command (e.g. `Warehouses` → model `Warehouse`, controller `WarehouseController`, route prefix `/api/warehouses/import`, test file `tests/Feature/WarehousesImportTest.php`). If no argument was given, ask which model/resource this import is for.

2. **Inspect the existing model and controller** for that resource before writing anything:
   - Read the model's fillable fields, factory, and any existing `scopeVisibleToCurrentUser`/`findVisibleToCurrentUser` helper — reuse it in the new `upsertFromImport`.
   - Read a sibling import (`ProductController::import` / `Product::upsertFromImport` for name-resolved relations, or `CompanyController::import` / `Company::upsertFromImport` for a simpler id-only case) and pick whichever is the closer structural match.

3. **Add the controller method** `import(Request $request): JsonResponse` on the resource's controller, matching the request/validation/transaction shape from the pattern skill exactly (menu-permission check, `rows` validation, per-row transaction, `ValidationException` vs `Throwable` split, `ImportResponse::success()`).

4. **Add `{Model}::upsertFromImport(array $row): string`** on the model, plus any `normalizeImportId()` / `buildImportPayload()` helpers it needs, resolving related records by id or by name as the sibling example does.

5. **Wire the route** in `routes/api.php` next to the resource's other routes, following the existing naming convention (e.g. `Route::post('{resource}/import', [...])`).

6. **Write `tests/Feature/{Resource}ImportTest.php`** covering, in order: a seed-scope helper for related records, create case, update case, unknown-id rejection, and an unauthenticated-guest rejection — matching `ProductsImportTest.php` / `CompaniesImportTest.php` structure.

7. **Run the new test file**: `php artisan test --compact --filter={Resource}Import`. Fix failures before finishing — do not report the scaffold as done on a failing test.

8. **Run `vendor/bin/pint --dirty --format agent`** on the touched PHP files (the PostToolUse hook in `.claude/settings.json` does this automatically on each edit, but re-check before finishing).

If the resource needs a frontend import UI too, ask the user whether that's in scope before building it — this command only scaffolds the backend import endpoint and its test.
