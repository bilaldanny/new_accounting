---
name: bulk-import-pattern
description: Apply this skill when adding or modifying a bulk-import feature (a controller `import()` method backed by rows of data, e.g. Products, Categories, Brands, Units, Companies, Users, Roles, Departments, Menus, Branches, Warranties, ItemTypes, Variations). Documents the shared request shape, upsert convention, response format, and test structure used across every import in this app.
---

# Bulk Import Pattern

Every import feature in this app (`ProductController::import`, `CompanyController::import`, and ~11 others) follows the same shape. Copy this pattern rather than inventing a new one — consistency here is what lets `import-pattern-checker` catch drift.

## Request shape

```php
public function import(Request $request): JsonResponse
{
    $this->authorizeMenuPermission('/{resource}/import'); // and/or authorizeSuperadmin() for tenant-scoped resources

    $request->validate([
        'rows' => 'required|array|min:1',
        'rows.*.name' => 'bail|required|string|min:2|max:200', // required fields only — most fields are 'nullable'
        // ... one line per column the import accepts, resolved by id OR by human-readable name (see below)
    ]);

    DB::beginTransaction();
    try {
        $created = 0;
        $updated = 0;

        foreach ($request->rows as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'rows' => ['Row '.($index + 1).' is invalid.'],
                ]);
            }

            if ({Model}::upsertFromImport($row) === 'created') {
                $created++;
            } else {
                $updated++;
            }
        }

        DB::commit();
    } catch (ValidationException $e) {
        DB::rollBack();
        throw $e;
    } catch (Throwable $e) {
        DB::rollBack();
        return response()->json(['errormessage' => $e]);
    }

    return ImportResponse::success(count($request->rows), $created, $updated, '{resource} records');
}
```

Key points:
- **One DB transaction for the whole batch.** A single bad row rolls back every row in that request — imports are all-or-nothing, not partial-success. Don't change this without discussing it; several tests rely on it.
- **`ValidationException` vs `Throwable` are handled differently**: a validation failure re-throws (so Laravel renders the normal 422 with field errors); any other exception is swallowed into a 200 response with an `errormessage` key. Follow this exact split — it's the existing convention, not something to "fix."
- Row-level errors are reported via `ValidationException::withMessages(['rows' => ["..."]])`, always naming the row (`'Row '.($index + 1)` or, for id-based lookups, the id itself, e.g. `"Product with id {$id} was not found."`) so the frontend can surface which row failed.

## Upsert convention: `{Model}::upsertFromImport(array $row): string`

Put the actual create/update logic on the model as a static method returning the literal string `'created'` or `'updated'`, never a boolean or the model instance:

```php
public static function upsertFromImport(array $row): string
{
    $id = self::normalizeImportId($row['id'] ?? null);
    $payload = self::buildImportPayload($row, $id);

    if ($id !== null) {
        $existing = self::findVisibleToCurrentUser($id); // scope to current user/company, same as normal reads

        if ($existing === null) {
            throw ValidationException::withMessages([
                'rows' => ["{Model} with id {$id} was not found."],
            ]);
        }

        self::updateRecord($payload, $id);
        return 'updated';
    }

    self::createRecord($payload);
    return 'created';
}
```

- `normalizeImportId()`: id is present → update; id empty/`''`/`0`/`'0'`/non-numeric → treat as create (`null`). Never treat `0` or `'0'` as a real id.
- Rows may reference related records either **by id** (`unit_id`) or **by human-readable name** (`unit`) — resolve the name to an id inside `buildImportPayload()` (e.g. look up `Unit::where('name', $row['unit'])`) so spreadsheet-style imports work without requiring the user to know internal ids. Accept both; don't require one over the other unless the existing controller already does.
- Tenant scoping: if the importing user isn't superadmin, fall back to their own `company_id` when the row doesn't specify one (`Auth::user()?->company_id`).

## Response: `App\Support\ImportResponse`

Always return via `ImportResponse::success($total, $created, $updated, $resourceLabel)` — it produces the standard message (`"Successfully imported %d new and updated %d %s."`) and a `summary` block (`total`/`created`/`updated`/`failed`). Don't hand-roll a different JSON shape for a new import; the frontend import UI expects this envelope. Use `ImportResponse::failure($total, $errors)` for a full-batch rejection instead of a partial one.

## Test structure: `tests/Feature/{Resource}ImportTest.php`

Canonical examples: [tests/Feature/ProductsImportTest.php](../../../tests/Feature/ProductsImportTest.php) (nested/related-model resolution: unit/brand/category/item_type by name) and [tests/Feature/CompaniesImportTest.php](../../../tests/Feature/CompaniesImportTest.php) (id-based reject case + auth gate).

A new `{Resource}ImportTest.php` should cover, in this order:

1. **A `seed{Resource}ImportScope()` helper** at the top of the file that creates whatever related records the import needs to resolve by name (company, unit, brand, category, etc.) via `DB::table()->insertGetId()` or model factories — whichever the sibling tests for that resource already use.
2. **Create case**: `id => ''` in the row → asserts `assertSuccessful()`, the exact `ImportResponse::success` message via `assertJsonPath('message', ...)`, and the resulting record's fields in the DB (`assertDatabaseHas` or a fresh model fetch).
3. **Update case**: `id => $existing->id` → asserts the message says "updated" not "new", and that fields actually changed.
4. **Unknown-id rejection**: an id that doesn't exist → `assertUnprocessable()->assertJsonValidationErrors(['rows'])` (see `CompaniesImportTest`'s "rejects unknown ids").
5. **Auth gate**: an unauthenticated request → `assertUnauthorized()` (see `CompaniesImportTest`'s "guests cannot import").

Use `RefreshDatabase`, `Sanctum::actingAs($superadmin)` (typically `User::query()->findOrFail(1)` per existing tests), and `postJson('/api/{resource}/import', ['rows' => [...]])`. Reuse existing model factories where one exists (`Unit::factory()`, `Brand::factory()`, etc.) instead of raw `DB::table()->insert()` unless the sibling import test for that resource already prefers raw inserts (e.g. for junction/lookup tables without a factory).
