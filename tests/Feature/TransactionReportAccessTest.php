<?php

use App\Http\Controllers\Reports\TransactionReportController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Access to the eight transaction list reports (API, page and menu rows). The figures are covered by
 * TransactionListReportTest, PaymentListReportTest and StockAdjustmentAndExpenseReportTest.
 */
dataset('transaction reports', [
    'purchase' => ['purchase', '/report/purchase'],
    'purchase return' => ['purchase-return', '/report/purchase-return'],
    'sell' => ['sell', '/report/sell'],
    'sell return' => ['sell-return', '/report/sell-return'],
    'purchase payment' => ['purchase-payment', '/report/purchase-payment'],
    'sell payment' => ['sell-payment', '/report/sell-payment'],
    'stock adjustment' => ['stock-adjustment', '/report/stock-adjustment'],
    'expense' => ['expense', '/report/expense'],
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
})->with('transaction reports');

test('a guest is turned away', function (string $report) {
    $this->getJson("/api/reports/{$report}")->assertUnauthorized();
})->with('transaction reports');

test('a user needs the menu row of the report', function (string $report, string $path) {
    $scope = trpScope();

    Sanctum::actingAs(jeaUserWith($scope, []));
    $this->getJson("/api/reports/{$report}")->assertForbidden();

    Sanctum::actingAs(jeaUserWith($scope, [$path]));
    $this->getJson("/api/reports/{$report}")->assertSuccessful();
})->with('transaction reports');

test('a page needs the same menu row and a signed in user', function (string $report, string $path) {
    $scope = trpScope();

    $this->get(route("report.{$report}"))->assertRedirect();

    $this->actingAs(User::query()->findOrFail(1))
        ->get(route("report.{$report}"))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('report/index')->where('report', $report));

    $this->actingAs(jeaUserWith($scope, []))->get(route("report.{$report}"))->assertForbidden();

    $this->actingAs(jeaUserWith($scope, [$path]))
        ->get(route("report.{$report}"))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('report/index')->where('report', $report));
})->with('transaction reports');

test('the sidebar page path resolves to the report route', function (string $report, string $path) {
    $match = app('router')->getRoutes()->match(Request::create($path, 'GET'));

    expect($match->getName())->toBe("report.{$report}");
})->with('transaction reports');

test('the permission map and the routes list the same eight reports', function () {
    expect(array_keys(TransactionReportController::PERMISSIONS))->toBe([
        'purchase', 'purchase-return', 'sell', 'sell-return', 'purchase-payment', 'sell-payment', 'stock-adjustment', 'expense',
    ]);

    foreach (TransactionReportController::PERMISSIONS as $report => $path) {
        expect($path)->toBe("/report/{$report}");
    }
});

// ------------------------------------------------------------------ menu migration

function trpMenuMigration(): object
{
    return require database_path('migrations/2026_09_20_214054_add_transaction_report_menus.php');
}

/**
 * @return list<string>
 */
function trpReportPaths(): array
{
    return array_values(TransactionReportController::PERMISSIONS);
}

test('the eight reports sit in the Reports group, each with a hidden export row', function () {
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Reports')->where('type', 2)->value('id');

    expect($groupId)->not->toBeNull();

    foreach (trpReportPaths() as $path) {
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

    trpMenuMigration()->up();

    expect(DB::table('menus')->count())->toBe($before)
        ->and(DB::table('permissions')->count())->toBe($permissions);
});

test('the menu migration can be rolled back and applied again', function () {
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Reports')->where('type', 2)->value('id');
    $ledger = DB::table('menus')->where('route_path', '/report/ledger')->value('id');
    $pageId = (int) DB::table('menus')->where('route_path', '/report/sell')->value('id');
    $role = Role::query()->create(['name' => 'accountant', 'is_active' => true]);
    Permission::query()->create(['role_id' => $role->id, 'menu_id' => $pageId, 'status' => 1]);

    trpMenuMigration()->down();

    foreach (trpReportPaths() as $path) {
        expect(DB::table('menus')->where('route_path', $path)->exists())->toBeFalse()
            ->and(DB::table('menus')->where('route_path', $path.'/export')->exists())->toBeFalse();
    }

    expect(DB::table('permissions')->where('menu_id', $pageId)->exists())->toBeFalse()
        ->and(DB::table('menus')->where('id', $groupId)->exists())->toBeTrue()
        ->and(DB::table('menus')->where('id', $ledger)->exists())->toBeTrue();

    trpMenuMigration()->up();

    expect(DB::table('menus')->whereIn('route_path', trpReportPaths())->count())->toBe(8);
});

test('the menu migration adds nothing without the Reports group', function () {
    trpMenuMigration()->down();
    DB::table('menus')->where('name', 'Reports')->where('type', 2)->delete();

    trpMenuMigration()->up();

    expect(DB::table('menus')->whereIn('route_path', trpReportPaths())->exists())->toBeFalse();
});
