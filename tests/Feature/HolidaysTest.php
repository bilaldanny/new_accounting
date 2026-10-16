<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The company holiday list: tenant scoped add / edit / delete, the one-click import of the public holidays of Pakistan, and the dots on
 * the header calendar.
 */
function holStaff(array $scope, array $paths): User
{
    return fcoStaff($scope, $paths, false);
}

test('holidays are added, edited and deleted by whoever manages HR settings, and read with the holidays page', function () {
    $scope = fcoScope();

    Sanctum::actingAs(holStaff($scope, ['/hrm/holidays']));
    $this->getJson('/api/hrm/holidays')->assertOk();
    $this->postJson('/api/hrm/holidays', ['holiday_date' => '2026-12-31', 'name' => 'Year end'])->assertForbidden();

    Sanctum::actingAs(holStaff($scope, ['/hrm/holidays', '/hrm/manage']));
    $id = $this->postJson('/api/hrm/holidays', ['holiday_date' => '2026-12-31', 'name' => '<b>Year end</b>'])->assertCreated()->json('data.id');
    $this->postJson('/api/hrm/holidays', ['holiday_date' => '2026-12-31', 'name' => 'Year end'])->assertUnprocessable();
    $this->postJson('/api/hrm/holidays', ['holiday_date' => 'nope', 'name' => 'x'])->assertUnprocessable();
    $this->putJson("/api/hrm/holidays/{$id}", ['holiday_date' => '2026-12-30', 'name' => 'Office closed'])->assertOk();
    expect(DB::table('company_holidays')->find($id)->name)->toBe('Office closed');
    $this->deleteJson("/api/hrm/holidays/{$id}")->assertOk();
    $this->deleteJson("/api/hrm/holidays/{$id}")->assertNotFound();
});

test('one click imports the Pakistan public holidays once, and another company never sees them', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany('hol');

    Sanctum::actingAs(holStaff($scope, ['/hrm/holidays', '/hrm/manage']));
    $first = $this->postJson('/api/hrm/holidays/import-pakistan', ['year' => 2026])->assertOk()->json('data');
    expect($first['added'])->toBeGreaterThanOrEqual(10)->and($first['islamic_dates_listed'])->toBeTrue();
    $this->postJson('/api/hrm/holidays/import-pakistan', ['year' => 2026])->assertOk()->assertJsonPath('data.added', 0);
    $names = collect($this->getJson('/api/hrm/holidays?year=2026')->json('data'))->pluck('name')->all();
    expect($names)->toContain('Pakistan Day', 'Independence Day')->and(collect($names)->contains(fn (string $n): bool => str_contains($n, 'Eid ul Fitr')))->toBeTrue();
    $this->postJson('/api/hrm/holidays/import-pakistan', ['year' => 2031])->assertOk()->assertJsonPath('data.islamic_dates_listed', false);

    Sanctum::actingAs(holStaff($other, ['/hrm/holidays', '/hrm/manage']));
    $this->getJson('/api/hrm/holidays')->assertOk()->assertJsonCount(0, 'data');
    $id = DB::table('company_holidays')->where('company_id', $scope['company_id'])->value('id');
    $this->putJson("/api/hrm/holidays/{$id}", ['holiday_date' => '2026-01-01', 'name' => 'Mine now'])->assertNotFound();
    $this->deleteJson("/api/hrm/holidays/{$id}")->assertNotFound();
});

test('the calendar carries the holidays of the month for every user of the company', function () {
    $scope = fcoScope();
    DB::table('company_holidays')->insert(['company_id' => $scope['company_id'], 'holiday_date' => now()->startOfMonth()->addDays(4)->toDateString(), 'name' => 'Founders day', 'kind' => 'company', 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs(holStaff($scope, ['/dashboard']));
    $events = collect($this->getJson('/api/header/calendar')->assertOk()->json('data.events'));

    expect($events->where('kind', 'holiday')->pluck('title')->all())->toBe(['Founders day']);
});
