<?php

use App\Models\FinancialYear;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedFinancialYearScope(): array
{
    static $counter = 0;
    $counter++;

    $companyId = DB::table('companies')->insertGetId([
        'code' => 'FY'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'name' => 'Financial Year Test Company '.$counter,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['company_id' => $companyId];
}

function createCompanyAdminForFinancialYear(array $scope): User
{
    $role = Role::query()->create([
        'name' => 'companyadmin',
        'company_id' => $scope['company_id'],
        'is_active' => true,
    ]);

    return createStaffUserForRole($role, ['company_id' => $scope['company_id']]);
}

test('financial years api lists years scoped to the current company', function () {
    $scope = seedFinancialYearScope();
    $other = seedFinancialYearScope();

    FinancialYear::query()->create([
        'company_id' => $scope['company_id'],
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => true,
    ]);

    FinancialYear::query()->create([
        'company_id' => $other['company_id'],
        'name' => 'FY Other',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => true,
    ]);

    Sanctum::actingAs(createCompanyAdminForFinancialYear($scope));

    $response = $this->getJson('/api/financialyears');

    $response->assertSuccessful();
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('FY 2026');
});

test('financial years api creates a year and defaults the name from its dates', function () {
    $scope = seedFinancialYearScope();
    Sanctum::actingAs(createCompanyAdminForFinancialYear($scope));

    $this->postJson('/api/financialyears', [
        'company_id' => $scope['company_id'],
        'start_date' => '2026-07-01',
        'end_date' => '2027-06-30',
    ])->assertSuccessful();

    $year = FinancialYear::query()->where('company_id', $scope['company_id'])->firstOrFail();

    expect($year->name)->toBe('2026-07-01 - 2027-06-30')
        ->and($year->start_date->format('Y-m-d'))->toBe('2026-07-01')
        ->and($year->end_date->format('Y-m-d'))->toBe('2027-06-30')
        ->and((bool) $year->status)->toBeTrue();
});

test('financial years api store is forbidden without company setting permission', function () {
    $scope = seedFinancialYearScope();

    $role = Role::query()->create(['name' => 'clerk', 'is_active' => true]);
    $user = createStaffUserForRole($role, ['company_id' => $scope['company_id']]);
    Sanctum::actingAs($user);

    $this->postJson('/api/financialyears', [
        'company_id' => $scope['company_id'],
        'start_date' => '2026-07-01',
        'end_date' => '2027-06-30',
    ])->assertForbidden();

    expect(FinancialYear::query()->where('company_id', $scope['company_id'])->exists())->toBeFalse();
});

test('financial years api show returns 404 for a year outside the current company', function () {
    $scope = seedFinancialYearScope();
    $other = seedFinancialYearScope();

    $year = FinancialYear::query()->create([
        'company_id' => $other['company_id'],
        'name' => 'FY Other',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => true,
    ]);

    Sanctum::actingAs(createCompanyAdminForFinancialYear($scope));

    $this->getJson('/api/financialyears/'.$year->id)->assertNotFound();
});

test('financial years api updates the date range', function () {
    $scope = seedFinancialYearScope();
    $year = FinancialYear::query()->create([
        'company_id' => $scope['company_id'],
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => true,
    ]);

    Sanctum::actingAs(createCompanyAdminForFinancialYear($scope));

    $this->putJson('/api/financialyears/'.$year->id, [
        'company_id' => $scope['company_id'],
        'start_date' => '2026-02-01',
        'end_date' => '2027-01-31',
    ])->assertSuccessful();

    $year->refresh();

    expect($year->start_date->format('Y-m-d'))->toBe('2026-02-01')
        ->and($year->end_date->format('Y-m-d'))->toBe('2027-01-31');
});

test('financial years api activating one year deactivates the others for the same company', function () {
    $scope = seedFinancialYearScope();

    $active = FinancialYear::query()->create([
        'company_id' => $scope['company_id'],
        'name' => 'FY 2025',
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'status' => true,
    ]);

    $inactive = FinancialYear::query()->create([
        'company_id' => $scope['company_id'],
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => false,
    ]);

    Sanctum::actingAs(createCompanyAdminForFinancialYear($scope));

    $this->putJson('/api/financialyears/'.$inactive->id, [
        'updatetype' => 'status',
        'status' => true,
    ])->assertSuccessful();

    expect((bool) $active->fresh()->status)->toBeFalse()
        ->and((bool) $inactive->fresh()->status)->toBeTrue();
});

test('financial years api destroy returns 406 without company setting permission', function () {
    $scope = seedFinancialYearScope();
    $year = FinancialYear::query()->create([
        'company_id' => $scope['company_id'],
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => true,
    ]);

    $role = Role::query()->create(['name' => 'clerk', 'is_active' => true]);
    $user = createStaffUserForRole($role, ['company_id' => $scope['company_id']]);
    Sanctum::actingAs($user);

    $response = $this->deleteJson('/api/financialyears/'.$year->id);

    $response->assertSuccessful();
    expect($response->json())->toBe('406');
    expect(FinancialYear::query()->find($year->id))->not->toBeNull();
});

test('financial years api destroy deletes the year with company setting permission', function () {
    $scope = seedFinancialYearScope();
    $year = FinancialYear::query()->create([
        'company_id' => $scope['company_id'],
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => true,
    ]);

    Sanctum::actingAs(createCompanyAdminForFinancialYear($scope));

    $this->deleteJson('/api/financialyears/'.$year->id)
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Deleted');

    expect(FinancialYear::query()->find($year->id))->toBeNull();
});
