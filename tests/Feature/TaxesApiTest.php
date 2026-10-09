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
    $this->postJson('/api/taxes/preview', ['lines' => []])->assertUnauthorized();
    $this->getJson('/api/tax-exemptions')->assertUnauthorized();

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

test('a tax rate can be a decimal and is returned as one', function () {
    $company = createTaxCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/taxes', ['company_id' => $company->id, 'name' => 'Further tax', 'percentage' => 17.5, 'type' => 0])->assertSuccessful();
    $this->postJson('/api/taxes', ['company_id' => $company->id, 'name' => 'Too much', 'percentage' => 120, 'type' => 0])->assertUnprocessable()->assertJsonValidationErrors(['percentage']);

    $tax = Tax::query()->where('name', 'Further tax')->firstOrFail();
    $this->getJson('/api/taxes/'.$tax->id)->assertJsonPath('percentage', 17.5);
    expect((float) $tax->percentage)->toBe(17.5);
});

test('a compound group charges each tax on the amount plus the taxes before it and keeps the order', function () {
    $company = createTaxCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $sales = Tax::query()->create(['company_id' => $company->id, 'name' => 'Sales tax', 'percentage' => 5, 'type' => 0])->id;
    $levy = Tax::query()->create(['company_id' => $company->id, 'name' => 'Levy', 'percentage' => 10, 'type' => 0])->id;

    $this->postJson('/api/taxes', ['company_id' => $company->id, 'name' => 'Stacked', 'type' => 1, 'sub_tax' => [$sales, $levy], 'compound' => true])->assertSuccessful();
    $this->postJson('/api/taxes', ['company_id' => $company->id, 'name' => 'Added', 'type' => 1, 'sub_tax' => [$levy, $sales], 'compound' => false])->assertSuccessful();

    $stacked = Tax::query()->where('name', 'Stacked')->firstOrFail();
    $added = Tax::query()->where('name', 'Added')->firstOrFail();

    expect($stacked->percentage)->toBe(15.5)->and($stacked->compound)->toBeTrue()->and($stacked->subTaxIds())->toBe([$sales, $levy])
        ->and($added->percentage)->toBe(15.0)->and($added->compound)->toBeFalse()->and($added->subTaxIds())->toBe([$levy, $sales]);

    // The group keeps the rate it amounts to when one of its taxes changes.
    $this->putJson('/api/taxes/'.$sales, ['company_id' => $company->id, 'name' => 'Sales tax', 'percentage' => 8, 'type' => 0])->assertSuccessful();
    expect($stacked->fresh()->percentage)->toBe(18.8)->and($added->fresh()->percentage)->toBe(18.0);
});

test('a group only takes single sales taxes of its own company', function () {
    $company = createTaxCompany();
    $other = Company::query()->create(['code' => 'CO-TAX-02', 'name' => 'Other Corp', 'is_active' => true, 'max_users' => 10, 'max_branches' => 2]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $mine = Tax::query()->create(['company_id' => $company->id, 'name' => 'Mine', 'percentage' => 5, 'type' => 0])->id;
    $foreign = Tax::query()->create(['company_id' => $other->id, 'name' => 'Foreign', 'percentage' => 5, 'type' => 0])->id;
    $withholding = Tax::query()->create(['company_id' => $company->id, 'name' => 'WHT', 'percentage' => 4, 'type' => 0, 'kind' => 'withholding'])->id;

    foreach ([$foreign, $withholding, 999999] as $bad) {
        $this->postJson('/api/taxes', ['company_id' => $company->id, 'name' => 'Bad group', 'type' => 1, 'sub_tax' => [$mine, $bad]])
            ->assertUnprocessable()->assertJsonValidationErrors(['sub_tax']);
    }
});

test('withholding taxes stay out of the sales tax picker and groups can be listed with their parts', function () {
    $company = createTaxCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $a = Tax::query()->create(['company_id' => $company->id, 'name' => 'A', 'percentage' => 5, 'type' => 0])->id;
    $b = Tax::query()->create(['company_id' => $company->id, 'name' => 'B', 'percentage' => 10, 'type' => 0])->id;
    Tax::query()->create(['company_id' => $company->id, 'name' => 'WHT 4', 'percentage' => 4, 'type' => 0, 'kind' => 'withholding', 'applies_on' => 'gross']);
    Tax::query()->create(['company_id' => $company->id, 'name' => 'AB', 'percentage' => 15.5, 'type' => 1, 'compound' => true, 'sub_tax' => [$a, $b]]);

    $this->getJson('/api/fetchtaxes?company_id='.$company->id)->assertJsonCount(2);

    $withGroups = $this->getJson('/api/fetchtaxes?with_groups=1&company_id='.$company->id)->assertJsonCount(3)->json();
    $group = collect($withGroups)->firstWhere('name', 'AB');
    expect($group['compound'])->toBeTrue()->and(array_column($group['components'], 'name'))->toBe(['A', 'B']);

    $withholding = $this->getJson('/api/fetchtaxes?kind=withholding&company_id='.$company->id)->assertJsonCount(1)->json();
    expect($withholding[0]['name'])->toBe('WHT 4')->and($withholding[0]['applies_on'])->toBe('gross');
});

test('a tax that is in a group or on a document cannot be deleted', function () {
    $company = createTaxCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $inGroup = Tax::query()->create(['company_id' => $company->id, 'name' => 'Part', 'percentage' => 5, 'type' => 0])->id;
    Tax::query()->create(['company_id' => $company->id, 'name' => 'Group', 'percentage' => 5, 'type' => 1, 'sub_tax' => [$inGroup]]);
    $free = Tax::query()->create(['company_id' => $company->id, 'name' => 'Free', 'percentage' => 5, 'type' => 0])->id;

    $this->deleteJson('/api/taxes/'.$inGroup)->assertUnprocessable()->assertJsonValidationErrors(['tax']);
    $this->postJson('/api/taxes/bulk_delete', [$inGroup, $free])->assertUnprocessable();
    $this->assertDatabaseHas('taxes', ['id' => $free]);

    $this->deleteJson('/api/taxes/'.$free)->assertSuccessful();
});
