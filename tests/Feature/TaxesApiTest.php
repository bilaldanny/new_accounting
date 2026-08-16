<?php

use App\Models\Company;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createTaxCompany(): Company
{
    return Company::query()->create([
        'code' => 'CO-TAX-01',
        'name' => 'Tax Test Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);
}

test('guests cannot access taxes api', function () {
    $this->getJson('/api/taxes')
        ->assertUnauthorized();
});

test('superadmin can list create update and delete taxes for a company', function () {
    $company = createTaxCompany();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/taxes?company_id='.$company->id)
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('trash_count', 0);

    $this->postJson('/api/taxes', [
        'company_id' => $company->id,
        'name' => 'GST',
        'percentage' => 18,
        'type' => 0,
        'status' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $tax = Tax::query()->where('company_id', $company->id)->firstOrFail();

    $this->getJson('/api/taxes/'.$tax->id)
        ->assertSuccessful()
        ->assertJsonPath('name', 'GST')
        ->assertJsonPath('percentage', 18);

    $this->putJson('/api/taxes/'.$tax->id, [
        'company_id' => $company->id,
        'name' => 'GST Updated',
        'percentage' => 17,
        'type' => 0,
        'status' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $this->assertDatabaseHas('taxes', [
        'id' => $tax->id,
        'name' => 'GST Updated',
        'percentage' => 17,
    ]);

    $this->postJson('/api/taxes/statusupdate', [
        'ids' => [$tax->id],
        'status' => false,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $this->assertDatabaseHas('taxes', [
        'id' => $tax->id,
        'status' => 0,
    ]);

    $this->deleteJson('/api/taxes/'.$tax->id)
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Deleted');

    $this->assertDatabaseMissing('taxes', [
        'id' => $tax->id,
    ]);
});

test('superadmin can create tax group from existing tax rates', function () {
    $company = createTaxCompany();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $firstTaxId = Tax::query()->create([
        'company_id' => $company->id,
        'name' => 'Tax A',
        'percentage' => 10,
        'type' => 0,
        'status' => true,
    ])->id;

    $secondTaxId = Tax::query()->create([
        'company_id' => $company->id,
        'name' => 'Tax B',
        'percentage' => 5,
        'type' => 0,
        'status' => true,
    ])->id;

    $this->postJson('/api/taxes', [
        'company_id' => $company->id,
        'name' => 'Combined Tax',
        'sub_tax' => [$firstTaxId, $secondTaxId],
        'type' => 1,
        'status' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $this->assertDatabaseHas('taxes', [
        'company_id' => $company->id,
        'name' => 'Combined Tax',
        'percentage' => 15,
        'type' => 1,
    ]);
});

test('fetch taxes returns active single tax rates for a company', function () {
    $company = createTaxCompany();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    Tax::query()->create([
        'company_id' => $company->id,
        'name' => 'Active Tax',
        'percentage' => 12,
        'type' => 0,
        'status' => true,
    ]);

    Tax::query()->create([
        'company_id' => $company->id,
        'name' => 'Inactive Tax',
        'percentage' => 8,
        'type' => 0,
        'status' => false,
    ]);

    Tax::query()->create([
        'company_id' => $company->id,
        'name' => 'Group Tax',
        'percentage' => 20,
        'type' => 1,
        'status' => true,
    ]);

    $this->getJson('/api/fetchtaxes?company_id='.$company->id)
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'Active Tax');
});

test('tax store validates required fields', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/taxes', [
        'type' => 0,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'percentage']);
});
