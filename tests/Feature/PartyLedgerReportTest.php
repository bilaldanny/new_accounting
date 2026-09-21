<?php

use App\Models\Contact;
use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function reportsMenuMigration(): object
{
    return require database_path('migrations/2026_09_19_064721_add_reports_menu_with_party_ledger.php');
}

function reportsGroupId(): ?int
{
    $id = DB::table('menus')->whereNull('parent_id')->where('name', 'Reports')->where('type', 2)->value('id');

    return $id === null ? null : (int) $id;
}

test('guests cannot open the party ledger report', function () {
    $this->get(route('report.ledger'))->assertRedirect();
});

test('the superadmin opens the party ledger report page', function () {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route('report.ledger'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('report/ledger'));
});

test('the sidebar path resolves to the named web route', function () {
    $match = app('router')->getRoutes()->match(Request::create('/report/ledger', 'GET'));

    expect($match->getName())->toBe('report.ledger')
        ->and(route('report.ledger', absolute: false))->toBe('/report/ledger');
});

test('the page needs the report ledger permission for staff roles', function () {
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'accountsclerk', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $staff = createStaffUserForRole(Role::query()->findOrFail($roleId));

    $this->actingAs($staff)->get(route('report.ledger'))->assertForbidden();

    DB::table('permissions')->insert([
        'role_id' => $roleId,
        'menu_id' => DB::table('menus')->where('route_path', '/report/ledger')->value('id'),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Cache::forget("user_permission_paths:{$roleId}");
    Cache::forget("user_menu_permissions:{$roleId}");

    $this->actingAs($staff)
        ->get(route('report.ledger'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('report/ledger'));
});

test('the migration creates a visible reports group with the ledger as its first report', function () {
    $groupId = reportsGroupId();
    $ledger = DB::table('menus')->where('route_path', '/report/ledger')->first();
    $group = DB::table('menus')->where('id', $groupId)->first();

    expect($groupId)->not->toBeNull()
        ->and((int) $group->type)->toBe(2)
        ->and((int) $group->is_hidden)->toBe(0)
        ->and((int) $group->is_active)->toBe(1)
        ->and((int) $group->parent_id)->toBe(0)
        ->and($ledger->route_name)->toBe('report.ledger')
        ->and((int) $ledger->parent_id)->toBe($groupId)
        ->and((int) $ledger->type)->toBe(1)
        ->and((int) $ledger->is_hidden)->toBe(0);
});

test('the superadmin sidebar renders reports as a dropdown with the ledger link', function () {
    $reports = collect(Menu::sidebarMenusForRole(1))->firstWhere('name', 'Reports');

    expect($reports)->not->toBeNull()
        ->and((int) $reports['type'])->toBe(2)
        ->and(collect($reports['children'])->pluck('my_route')->first())->toBe('/report/ledger');
});

test('running the migration twice does not duplicate the group or the report', function () {
    $count = DB::table('menus')->count();

    reportsMenuMigration()->up();

    expect(DB::table('menus')->count())->toBe($count)
        ->and(DB::table('menus')->where('name', 'Reports')->count())->toBe(1);
});

test('rolling back removes the report and the group and it can be applied again', function () {
    $ledgerId = DB::table('menus')->where('route_path', '/report/ledger')->value('id');
    DB::table('permissions')->insert(['role_id' => 1, 'menu_id' => $ledgerId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    // The transaction and party reports live in the same group; the group only goes once they are gone too.
    (require database_path('migrations/2026_09_20_214054_add_transaction_report_menus.php'))->down();
    (require database_path('migrations/2026_09_21_120000_add_party_report_menus.php'))->down();
    (require database_path('migrations/2026_09_21_130000_add_product_report_menus.php'))->down();
    (require database_path('migrations/2026_09_21_140000_add_stock_report_menus.php'))->down();
    (require database_path('migrations/2026_09_21_150000_add_ledger_report_menus.php'))->down();
    reportsMenuMigration()->down();

    expect(reportsGroupId())->toBeNull()
        ->and(DB::table('menus')->where('route_path', '/report/ledger')->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $ledgerId)->exists())->toBeFalse();

    reportsMenuMigration()->up();

    expect(reportsGroupId())->not->toBeNull()
        ->and(DB::table('menus')->where('route_path', '/report/ledger')->exists())->toBeTrue();
});

test('rolling back keeps the reports group when it also holds other reports', function () {
    $groupId = reportsGroupId();

    DB::table('menus')->insert([
        'parent_id' => $groupId, 'name' => 'Another Report', 'icon' => 'Receipt', 'route_name' => 'report.another',
        'route_path' => '/report/another', 'menu_color' => '#199683', 'sort_order' => 9, 'is_hidden' => 0, 'is_active' => 1,
        'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    reportsMenuMigration()->down();

    expect(reportsGroupId())->toBe($groupId)
        ->and(DB::table('menus')->where('route_path', '/report/ledger')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/report/another')->exists())->toBeTrue();
});

test('the ledger api answers with the fields the report page reads for both customers and suppliers', function (string $party) {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();

    $contactId = $party === 'customer'
        ? $scope['contact_id']
        : Contact::query()->where('user_type', 'supplier')->value('id');

    $response = $this->getJson('/api/fetchledger?contact_id='.$contactId)->assertSuccessful();

    $response->assertJsonStructure(['taccount', 'openingbalance', 'closingbalance']);

    if ($party === 'customer') {
        $row = $response->json('taccount.0');

        expect($row)->toHaveKeys(['id', 'voucher_date', 'voucher_no', 'debit', 'credit', 'acc_nature'])
            ->and((float) $response->json('closingbalance'))->toBe(280.0);
    }
})->with(['customer', 'supplier']);
