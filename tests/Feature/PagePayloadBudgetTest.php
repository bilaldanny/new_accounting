<?php

use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use App\Services\System\PagePayloadSizes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Size budget for what every page sends to the browser (see docs/page-payload-sizes.md). Before the budget, the sidebar tree sent every
 * column of every menu row, hidden add / edit / approve permission rows included, with every page: 150 KB on every page of a
 * 308 menu install, 250 to 375 KB on the browser test database, and the cut off responses of the Dusk server came from that.
 */
function budgetAdmin(int $extraHiddenMenus = 0): User
{
    $scope = seedSellScope();
    $role = Role::query()->create(['name' => 'Budget Admin', 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'is_active' => true]);

    // the action rows of a long-lived install: hidden, one permission each
    $parent = (int) DB::table('menus')->whereNull('parent_id')->value('id');

    for ($i = 0; $i < $extraHiddenMenus; $i++) {
        DB::table('menus')->insert([
            'parent_id' => $parent, 'name' => "Action {$i}", 'icon' => 'Grid', 'route_name' => "budget.action.{$i}", 'route_path' => "/budget-action-{$i}/add", 'menu_color' => '#000000',
            'sort_order' => 1, 'is_hidden' => 1, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach (DB::table('menus')->pluck('id') as $menuId) {
        DB::table('permissions')->insert(['role_id' => $role->id, 'menu_id' => $menuId, 'status' => 1, 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'created_at' => now(), 'updated_at' => now()]);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
}

it('keeps every page response and the props shared with all pages inside the size budget', function () {
    $result = app(PagePayloadSizes::class)->measure(budgetAdmin(900));

    expect($result['pages'])->not->toBeEmpty();

    $largest = $result['pages'][0];
    $shared = array_sum($result['shared']);

    // the goal is 150 KB per normal page; the budget sits well under it so growth is noticed early
    expect($shared)->toBeLessThan(60 * 1024, 'the props shared with every page grew to '.number_format($shared / 1024, 1).' KB');
    expect($largest['bytes'])->toBeLessThan(80 * 1024, "{$largest['uri']} is ".number_format($largest['bytes'] / 1024, 1).' KB (largest own prop: '.$largest['largest_prop'].')');

    foreach ($result['pages'] as $page) {
        expect($page['own_bytes'])->toBeLessThan(40 * 1024, "{$page['uri']} sends {$page['largest_prop']} of ".number_format($page['largest_bytes'] / 1024, 1).' KB with the page; load it from an endpoint with a limit instead');
    }
});

it('leaves hidden permission rows and unused columns out of the sidebar tree', function () {
    $tree = Menu::slimSidebarTree([
        ['id' => 1, 'parent_id' => '', 'name' => 'Payment', 'icon' => 'Wallet', 'route_name' => 'acpayment', 'my_route' => '/acpayment', 'status' => 1, 'type' => 1, 'is_hidden' => false, 'created_at' => 'x', 'menu_color' => '#fff', 'children' => [
            ['id' => 2, 'parent_id' => 1, 'name' => 'Add Payment', 'is_hidden' => true, 'my_route' => '/acpayment/add', 'children' => []],
            ['id' => 3, 'parent_id' => 1, 'name' => 'Reports', 'icon' => 'Grid', 'route_name' => 'r', 'my_route' => '/r', 'status' => 1, 'type' => 1, 'is_hidden' => false, 'updated_by' => 5, 'children' => []],
        ]],
    ]);

    expect($tree)->toHaveCount(1)
        ->and(array_keys($tree[0]))->not->toContain('created_at', 'menu_color')
        ->and(collect($tree[0]['children'])->pluck('name')->all())->toBe(['Reports'])
        ->and(array_keys($tree[0]['children'][0]))->not->toContain('updated_by');
});
