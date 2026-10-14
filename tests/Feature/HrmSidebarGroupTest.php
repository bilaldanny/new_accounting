<?php

use App\Models\Menu;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * HR and Payroll was one row in the User Management group with hidden attendance / payroll rows. The migration gives it a group of its
 * own with visible entries; roles keep exactly the entries they hold.
 */
function hrmMenus(): array
{
    $now = now();
    $row = fn (array $attrs) => DB::table('menus')->insertGetId($attrs + ['icon' => 'Grid', 'route_name' => uniqid('r'), 'menu_color' => '#000', 'sort_order' => 1, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => $now, 'updated_at' => $now]);

    $users = $row(['parent_id' => null, 'name' => 'User Management', 'route_path' => '', 'type' => 2, 'is_hidden' => 0, 'sort_order' => 5]);
    $hub = $row(['parent_id' => $users, 'name' => 'HR and Payroll', 'route_path' => '/hrm', 'is_hidden' => 0]);

    return [
        'users' => $users, 'hub' => $hub,
        'manage' => $row(['parent_id' => $hub, 'name' => 'Manage Employees and Settings', 'route_path' => '/hrm/manage', 'is_hidden' => 1]),
        'attendance' => $row(['parent_id' => $hub, 'name' => 'Attendance and Leave', 'route_path' => '/hrm/attendance', 'is_hidden' => 1]),
        'payroll' => $row(['parent_id' => $hub, 'name' => 'Prepare Payroll', 'route_path' => '/hrm/payroll', 'is_hidden' => 1]),
        'approve' => $row(['parent_id' => $hub, 'name' => 'Approve and Post Payroll', 'route_path' => '/hrm/payroll/approve', 'is_hidden' => 1]),
    ];
}

function hrmRoleSees(array $menuIds): array
{
    $role = Role::query()->create(['name' => 'hr'.uniqid(), 'company_id' => null, 'is_active' => true]);

    foreach ($menuIds as $id) {
        DB::table('permissions')->insert(['role_id' => $role->id, 'menu_id' => $id, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    return collect(Menu::slimSidebarTree(Menu::sidebarMenusForRole((int) $role->id)))
        ->map(fn (array $group): array => ['name' => $group['name'], 'children' => collect($group['children'] ?? [])->pluck('name')->all()])->all();
}

it('shows HR and Payroll as a group of its own with visible entries after the migration', function () {
    $m = hrmMenus();
    (require database_path('migrations/2026_11_24_100000_group_hr_payroll_menus.php'))->up();

    $group = Menu::query()->where('type', 2)->where('name', 'HR and Payroll')->firstOrFail();

    expect($group->parent_id)->toBeEmpty()->and((int) $group->type)->toBe(2)
        ->and((int) Menu::query()->find($m['hub'])->parent_id)->toBe((int) $group->id)
        ->and((bool) Menu::query()->find($m['attendance'])->is_hidden)->toBeFalse()
        ->and((bool) Menu::query()->find($m['approve'])->is_hidden)->toBeTrue();

    // an HR admin sees the four entries under the new group; a viewer who only holds the hub sees just Employees
    $admin = hrmRoleSees([$m['hub'], $m['manage'], $m['attendance'], $m['payroll'], $m['approve']]);
    $viewer = hrmRoleSees([$m['hub']]);

    expect(collect($admin)->firstWhere('name', 'HR and Payroll')['children'])->toContain('Employees', 'Attendance and Leave', 'Payroll', 'HR Setup')
        ->and(collect($viewer)->firstWhere('name', 'HR and Payroll')['children'])->toBe(['Employees']);
});

it('can be run again and rolled back', function () {
    $m = hrmMenus();
    $migration = require database_path('migrations/2026_11_24_100000_group_hr_payroll_menus.php');
    $migration->up();
    $migration->up();

    expect(Menu::query()->where('type', 2)->where('name', 'HR and Payroll')->count())->toBe(1);

    $migration->down();

    expect(Menu::query()->where('type', 2)->where('name', 'HR and Payroll')->count())->toBe(0)
        ->and((int) Menu::query()->find($m['hub'])->parent_id)->toBe($m['users'])
        ->and((bool) Menu::query()->find($m['payroll'])->is_hidden)->toBeTrue();
});
