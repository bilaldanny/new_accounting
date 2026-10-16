<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The CRUD matrix of the master data screens: add, duplicate name refused, view, edit, copy, delete, the trash, restore, bulk delete and
 * the name check, for each resource the same way. Wave 5 of the UI + polish round: nothing new is built here, it proves the screens'
 * endpoints still behave alike so a refactor of one cannot quietly break the others.
 *
 * @return array<string, array{0: string, 1: array<string, mixed>}> uri => [extra fields]
 */
function crudMasters(): array
{
    return [
        'brands' => ['brands', []],
        'categories' => ['categories', []],
        'warranties' => ['warranties', ['duration' => 12, 'type' => 'month']],
        'item-types' => ['item-types', []],
        'departments' => ['departments', []],
        'units' => ['units', ['short_name' => 'u']],
        'customer-groups' => ['customer-groups', ['price_calculation_type' => 'percentage', 'calculation_percentage' => 5]],
    ];
}

function crudScope(): int
{
    return (int) DB::table('companies')->insertGetId(['code' => 'CRUD'.uniqid(), 'name' => 'Crud Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

dataset('crud-masters', array_map(fn (array $row): array => $row, crudMasters()));

test('a master screen adds, rejects a duplicate, shows, edits, deletes, restores and bulk deletes', function (string $uri, array $extra) {
    $company = crudScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $base = ['company_id' => $company, 'active' => true] + $extra;

    // add + validation
    $this->postJson("/api/{$uri}", $base + ['name' => ''])->assertUnprocessable();
    $this->postJson("/api/{$uri}", $base + ['name' => 'Alpha One'])->assertSuccessful();
    $this->postJson("/api/{$uri}", $base + ['name' => 'Alpha One'])->assertUnprocessable()->assertJsonValidationErrors(['name']);

    $table = DB::table(str_replace('-', '_', $uri))->where('company_id', $company)->where('name', 'Alpha One')->first();
    expect($table)->not->toBeNull();
    $id = (int) $table->id;

    // name check, view
    $this->postJson("/api/{$uri}/check-name", ['name' => 'Alpha One', 'company_id' => $company])->assertSuccessful();
    $this->getJson("/api/{$uri}/{$id}?company_id={$company}")->assertSuccessful();

    // edit
    $this->putJson("/api/{$uri}/{$id}", $base + ['name' => 'Alpha Two'])->assertSuccessful();
    expect(DB::table(str_replace('-', '_', $uri))->where('id', $id)->value('name'))->toBe('Alpha Two');

    // delete -> trash -> restore
    $this->deleteJson("/api/{$uri}/{$id}", ['company_id' => $company])->assertSuccessful();
    expect(DB::table(str_replace('-', '_', $uri))->where('id', $id)->whereNull('deleted_at')->exists())->toBeFalse();
    $this->getJson("/api/{$uri}/trash?company_id={$company}")->assertSuccessful();
    $this->postJson("/api/{$uri}/restore_records", [$id])->assertSuccessful();
    expect(DB::table(str_replace('-', '_', $uri))->where('id', $id)->whereNull('deleted_at')->exists())->toBeTrue();

    // bulk delete
    $this->postJson("/api/{$uri}", $base + ['name' => 'Beta'])->assertSuccessful();
    $second = (int) DB::table(str_replace('-', '_', $uri))->where('company_id', $company)->where('name', 'Beta')->value('id');
    $this->postJson("/api/{$uri}/bulk_delete", [$id, $second])->assertSuccessful();
    expect(DB::table(str_replace('-', '_', $uri))->whereIn('id', [$id, $second])->whereNull('deleted_at')->count())->toBe(0);
})->with('crud-masters');

test('a master screen of one company cannot be read or changed from another', function (string $uri, array $extra) {
    $company = crudScope();
    $other = crudScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);
    $this->postJson("/api/{$uri}", ['company_id' => $company, 'active' => true, 'name' => 'Private'] + $extra)->assertSuccessful();
    $id = (int) DB::table(str_replace('-', '_', $uri))->where('company_id', $company)->where('name', 'Private')->value('id');

    // the same name is free in another company
    $this->postJson("/api/{$uri}", ['company_id' => $other, 'active' => true, 'name' => 'Private'] + $extra)->assertSuccessful();
})->with('crud-masters');
