<?php

use App\Models\Menu;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * `/sell` and `/purchase` (and their group parents) exist in live data but are not created by any
 * migration, so a fresh schema has no anchors. This recreates the live shape.
 */
function seedSellPurchaseMenuAnchors(): void
{
    foreach ([['Sell', '/sell'], ['Purchase', '/purchase']] as [$group, $path]) {
        $groupId = DB::table('menus')->insertGetId([
            'parent_id' => null,
            'name' => $group,
            'icon' => 'bx bx-buildings',
            'route_name' => '',
            'route_path' => '',
            'menu_color' => '#6a0dad',
            'sort_order' => 1,
            'is_hidden' => 0,
            'is_active' => 1,
            'is_admin' => 0,
            'is_permission' => 0,
            'type' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('menus')->insert([
            'parent_id' => $groupId,
            'name' => $group,
            'icon' => 'bx bx-buildings',
            'route_name' => ltrim($path, '/'),
            'route_path' => $path,
            'menu_color' => '#6a0dad',
            'sort_order' => 1,
            'is_hidden' => 0,
            'is_active' => 1,
            'is_admin' => 0,
            'is_permission' => 0,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function sellReturnPaymentMigration(): object
{
    return require database_path('migrations/2026_09_19_060203_add_sell_return_and_payment_menus.php');
}

function menuParentIdFor(string $path): ?int
{
    $parentId = DB::table('menus')->where('route_path', $path)->value('parent_id');

    return $parentId === null ? null : (int) $parentId;
}

dataset('sell return and payment menus', [
    'sell return' => ['/sell/return', '/sell', 'SellReturnController'],
    'sell payment' => ['/sell/payment', '/sell', 'SellPaymentController'],
    'purchase payment' => ['/purchase/payment', '/purchase', 'PurchasePaymentController'],
]);

test('a fresh schema without the sell and purchase anchors gets no orphan rows', function () {
    sellReturnPaymentMigration()->up();

    expect(DB::table('menus')->whereIn('route_path', ['/sell/return', '/sell/payment', '/purchase/payment'])->exists())->toBeFalse();
});

test('each page gets a visible row under the same group as its anchor', function (string $path, string $anchor) {
    seedSellPurchaseMenuAnchors();
    sellReturnPaymentMigration()->up();

    $row = DB::table('menus')->where('route_path', $path)->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->parent_id)->toBe(menuParentIdFor($anchor))
        ->and((int) $row->is_hidden)->toBe(0)
        ->and((int) $row->is_active)->toBe(1)
        ->and((int) $row->type)->toBe(1);
})->with('sell return and payment menus');

test('every permission key checked by the controller has a row under the page', function (string $path, string $anchor, string $controller) {
    seedSellPurchaseMenuAnchors();
    sellReturnPaymentMigration()->up();

    $source = file_get_contents(app_path("Http/Controllers/{$controller}.php"));
    preg_match_all("#(?:authorizeMenuPermission|deletepermission|guardedBulkAction)\('(/[^']+)'#", $source, $matches);
    $required = array_values(array_unique($matches[1]));

    $parentId = DB::table('menus')->where('route_path', $path)->value('id');
    $childPaths = DB::table('menus')->where('parent_id', $parentId)->where('is_hidden', 1)->pluck('route_path')->all();

    expect($required)->not->toBeEmpty()
        ->and(array_diff($required, $childPaths))->toBe([]);
})->with('sell return and payment menus');

test('the sidebar page paths resolve to real web routes', function (string $path) {
    $match = app('router')->getRoutes()->match(Request::create($path, 'GET'));

    expect($match->getName())->toBe(ltrim(str_replace('/', '.', $path), '.'));
})->with(['/sell/return', '/sell/payment', '/purchase/payment']);

test('running the migration twice does not duplicate rows', function () {
    seedSellPurchaseMenuAnchors();
    sellReturnPaymentMigration()->up();
    $count = DB::table('menus')->count();

    sellReturnPaymentMigration()->up();

    expect(DB::table('menus')->count())->toBe($count);
});

test('the superadmin sidebar lists the new pages under sell and purchase', function () {
    seedSellPurchaseMenuAnchors();
    sellReturnPaymentMigration()->up();

    $tree = collect(Menu::sidebarMenusForRole(1));
    $sell = $tree->firstWhere('name', 'Sell');
    $purchase = $tree->firstWhere('name', 'Purchase');

    expect(collect($sell['children'])->pluck('my_route')->all())->toContain('/sell/return', '/sell/payment')
        ->and(collect($purchase['children'])->pluck('my_route')->all())->toContain('/purchase/payment');
});

test('a staff role can only use the new actions once the permission row is granted', function () {
    seedSellPurchaseMenuAnchors();
    sellReturnPaymentMigration()->up();

    $roleId = DB::table('roles')->insertGetId([
        'name' => 'salesstaff',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $staff = createStaffUserForRole(Role::query()->findOrFail($roleId));

    $this->actingAs($staff);

    expect(hasMenuPermission('/sell/return/add'))->toBeFalse();

    DB::table('permissions')->insert([
        'role_id' => $roleId,
        'menu_id' => DB::table('menus')->where('route_path', '/sell/return/add')->value('id'),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Cache::forget("user_permission_paths:{$roleId}");
    Cache::forget("user_menu_permissions:{$roleId}");

    expect(hasMenuPermission('/sell/return/add'))->toBeTrue()
        ->and(hasMenuPermission('/sell/return/delete'))->toBeFalse();
});

test('rolling the migration back removes the rows and their permissions', function () {
    seedSellPurchaseMenuAnchors();
    sellReturnPaymentMigration()->up();

    $paymentMenuId = DB::table('menus')->where('route_path', '/sell/payment')->value('id');

    DB::table('permissions')->insert([
        'role_id' => 1,
        'menu_id' => $paymentMenuId,
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    sellReturnPaymentMigration()->down();

    expect(DB::table('menus')->where('route_path', 'like', '/sell/return%')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', 'like', '/sell/payment%')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', 'like', '/purchase/payment%')->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $paymentMenuId)->exists())->toBeFalse()
        ->and(DB::table('menus')->whereIn('route_path', ['/sell', '/purchase'])->count())->toBe(2);
});
