<?php

use App\Http\Controllers\PartyReportController;
use App\Http\Controllers\TransactionReportController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Access to the six party reports (API, page and menu rows). The figures are covered by
 * PartyOutstandingReportTest, PartySummaryReportTest, CustomerGroupReportTest and PartyAgingReportTest.
 */
dataset('party reports', [
    'customer outstanding' => ['customer-outstanding', '/report/customer-outstanding'],
    'supplier outstanding' => ['supplier-outstanding', '/report/supplier-outstanding'],
    'customer and supplier' => ['customer-supplier', '/report/customer-supplier'],
    'customer group' => ['customer-group', '/report/customer-group'],
    'customer aging' => ['customer-aging', '/report/customer-aging'],
    'supplier aging' => ['supplier-aging', '/report/supplier-aging'],
]);

test('the report answers with a page of rows and a summary', function (string $report) {
    trpScope();
    trpActAsSuperadmin();

    $this->getJson("/api/reports/{$report}")
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => ['data', 'current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            'summary' => ['count'],
            'trash_count',
        ]);
})->with('party reports');

test('a guest is turned away', function (string $report) {
    $this->getJson("/api/reports/{$report}")->assertUnauthorized();
})->with('party reports');

test('a user needs the menu row of the report', function (string $report, string $path) {
    $scope = trpScope();

    Sanctum::actingAs(jeaUserWith($scope, []));
    $this->getJson("/api/reports/{$report}")->assertForbidden();

    Sanctum::actingAs(jeaUserWith($scope, [$path]));
    $this->getJson("/api/reports/{$report}")->assertSuccessful();
})->with('party reports');

test('one report permission does not open another report', function () {
    $scope = trpScope();

    Sanctum::actingAs(jeaUserWith($scope, ['/report/customer-aging']));

    $this->getJson('/api/reports/customer-aging')->assertSuccessful();
    $this->getJson('/api/reports/supplier-aging')->assertForbidden();
    $this->getJson('/api/reports/customer-outstanding')->assertForbidden();
    $this->getJson('/api/reports/sell')->assertForbidden();
});

test('a user without a company gets nothing', function () {
    trpScope();

    $role = Role::query()->create(['name' => 'nocompany', 'is_active' => true]);
    grantMenuPermission($role->id, '/report/customer-outstanding', 'customeroutstanding'.uniqid());
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => null, 'branch_id' => null]));

    $this->getJson('/api/reports/customer-outstanding')->assertForbidden();
});

test('an unknown report is not found', function () {
    trpScope();
    trpActAsSuperadmin();

    $this->getJson('/api/reports/no-such-report')->assertNotFound();
});

test('a page needs the same menu row and a signed in user', function (string $report, string $path) {
    $scope = trpScope();

    $this->get(route("report.{$report}"))->assertRedirect();

    $this->actingAs(User::query()->findOrFail(1))
        ->get(route("report.{$report}"))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('report/transaction')->where('report', $report));

    $this->actingAs(jeaUserWith($scope, []))->get(route("report.{$report}"))->assertForbidden();

    $this->actingAs(jeaUserWith($scope, [$path]))
        ->get(route("report.{$report}"))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('report/transaction')->where('report', $report));
})->with('party reports');

test('the sidebar page path resolves to the report route', function (string $report, string $path) {
    $match = app('router')->getRoutes()->match(Request::create($path, 'GET'));

    expect($match->getName())->toBe("report.{$report}");
})->with('party reports');

test('the permission map and the routes list the same six reports', function () {
    expect(array_keys(PartyReportController::PERMISSIONS))->toBe([
        'customer-outstanding', 'supplier-outstanding', 'customer-supplier', 'customer-group', 'customer-aging', 'supplier-aging',
    ]);

    foreach (PartyReportController::PERMISSIONS as $report => $path) {
        expect($path)->toBe("/report/{$report}");
    }
});

test('the party reports and the transaction reports do not share a report name', function () {
    expect(array_intersect_key(PartyReportController::PERMISSIONS, TransactionReportController::PERMISSIONS))->toBe([]);
});

// ------------------------------------------------------------------ menu migration

function prpMenuMigration(): object
{
    return require database_path('migrations/2026_09_21_120000_add_party_report_menus.php');
}

/**
 * @return list<string>
 */
function prpReportPaths(): array
{
    return array_values(PartyReportController::PERMISSIONS);
}

test('the six reports sit in the Reports group, each with a hidden export row', function () {
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Reports')->where('type', 2)->value('id');

    expect($groupId)->not->toBeNull();

    foreach (prpReportPaths() as $path) {
        $page = DB::table('menus')->where('route_path', $path)->first();

        expect($page)->not->toBeNull()
            ->and((int) $page->parent_id)->toBe((int) $groupId)
            ->and((int) $page->is_hidden)->toBe(0)
            ->and((int) $page->is_active)->toBe(1)
            ->and((int) $page->type)->toBe(1);

        $export = DB::table('menus')->where('route_path', $path.'/export')->first();

        expect($export)->not->toBeNull()
            ->and((int) $export->parent_id)->toBe((int) $page->id)
            ->and((int) $export->is_hidden)->toBe(1);
    }
});

test('running the menu migration again adds nothing and grants nothing', function () {
    $before = DB::table('menus')->count();
    $permissions = DB::table('permissions')->count();

    prpMenuMigration()->up();

    expect(DB::table('menus')->count())->toBe($before)
        ->and(DB::table('permissions')->count())->toBe($permissions);
});

test('the menu migration can be rolled back and applied again without touching other reports', function () {
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Reports')->where('type', 2)->value('id');
    $ledger = DB::table('menus')->where('route_path', '/report/ledger')->value('id');
    $sell = DB::table('menus')->where('route_path', '/report/sell')->value('id');
    $pageId = (int) DB::table('menus')->where('route_path', '/report/customer-aging')->value('id');
    $role = Role::query()->create(['name' => 'accountant', 'is_active' => true]);
    Permission::query()->create(['role_id' => $role->id, 'menu_id' => $pageId, 'status' => 1]);

    prpMenuMigration()->down();

    foreach (prpReportPaths() as $path) {
        expect(DB::table('menus')->where('route_path', $path)->exists())->toBeFalse()
            ->and(DB::table('menus')->where('route_path', $path.'/export')->exists())->toBeFalse();
    }

    expect(DB::table('permissions')->where('menu_id', $pageId)->exists())->toBeFalse()
        ->and(DB::table('menus')->where('id', $groupId)->exists())->toBeTrue()
        ->and(DB::table('menus')->where('id', $ledger)->exists())->toBeTrue()
        ->and(DB::table('menus')->where('id', $sell)->exists())->toBeTrue();

    prpMenuMigration()->up();

    expect(DB::table('menus')->whereIn('route_path', prpReportPaths())->count())->toBe(6);
});

test('the menu migration adds nothing without the Reports group', function () {
    prpMenuMigration()->down();
    DB::table('menus')->where('name', 'Reports')->where('type', 2)->delete();

    prpMenuMigration()->up();

    expect(DB::table('menus')->whereIn('route_path', prpReportPaths())->exists())->toBeFalse();
});
