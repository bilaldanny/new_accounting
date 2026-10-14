<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sidebar discoverability, second pass.
 *
 *  - The "HR and Payroll" group is renamed HRM and lists every HR page: Employees, Attendance, Leave Requests, Payroll, Loans and
 *    Advances, Shifts, Employee Documents, the four HR reports (moved out of the long Reports group) and HR Settings. Leave, Loans,
 *    Shifts and Documents are new addresses that open the HR hub on the right tab; they are granted to the companyadmin role like
 *    any new page. Nobody else gains access: roles already holding the hub keep what they hold.
 *  - Manufacturing, Service, Rental, Expense Claims, Online Shop and Projects were single entries buried in User Management and
 *    Accounts; they move into a group of their own, "Operations". Only parent and position change for them.
 */
return new class extends Migration
{
    private const HR_OLD = 'HR and Payroll';

    private const HR_NEW = 'HRM';

    private const OPS = 'Operations';

    /** @var array<string, array{0: string, 1: int}> */
    private const HR_ORDER = [
        '/hrm' => ['Employees', 1],
        '/hrm/attendance' => ['Attendance', 2],
        '/hrm/leave' => ['Leave Requests', 3],
        '/hrm/payroll' => ['Payroll', 4],
        '/hrm/loans' => ['Loans and Advances', 5],
        '/hrm/shifts' => ['Shifts', 6],
        '/hrm/documents' => ['Employee Documents', 7],
        '/report/payroll-register' => ['Payroll Register', 8],
        '/report/attendance-summary' => ['Attendance Summary', 9],
        '/report/statutory-liability' => ['Statutory Liability', 10],
        '/report/loan-balances' => ['Loan Balances', 11],
        '/hrm/manage' => ['HR Settings', 12],
    ];

    /** @var array<string, array{0: string, 1: int}> */
    private const OPS_ORDER = [
        '/manufacturing' => ['Manufacturing', 1],
        '/service' => ['Service Jobs', 2],
        '/rental' => ['Rental', 3],
        '/project' => ['Projects', 4],
        '/expenseclaim' => ['Expense Claims', 5],
        '/storefront' => ['Online Shop', 6],
    ];

    public function up(): void
    {
        $group = DB::table('menus')->where('type', 2)->whereIn('name', [self::HR_OLD, self::HR_NEW])->first();

        if ($group !== null) {
            MenuMigration::install([
                ['sibling:/hrm/attendance', 'Leave Requests', '/hrm/leave', 'hrm.leave', 'Users', false],
                ['sibling:/hrm/attendance', 'Loans and Advances', '/hrm/loans', 'hrm.loans', 'Users', false],
                ['sibling:/hrm/attendance', 'Shifts', '/hrm/shifts', 'hrm.shifts', 'Users', false],
                ['sibling:/hrm/attendance', 'Employee Documents', '/hrm/documents', 'hrm.documents', 'Users', false],
            ]);

            // the new addresses open tabs of the hub that the holder of the matching old page already uses: they get the same access
            foreach (['/hrm/leave' => '/hrm/attendance', '/hrm/shifts' => '/hrm/manage', '/hrm/documents' => '/hrm', '/hrm/loans' => '/hrm/payroll'] as $new => $source) {
                $this->copyAccess($source, $new);
            }

            DB::table('menus')->where('id', $group->id)->update(['name' => self::HR_NEW, 'updated_at' => now()]);
            $this->arrange((int) $group->id, self::HR_ORDER);
        }

        $ops = $this->group(self::OPS, 'Briefcase');

        if ($ops !== null) {
            $this->arrange($ops, self::OPS_ORDER);
        }

        $this->flush();
    }

    public function down(): void
    {
        $group = DB::table('menus')->where('type', 2)->where('name', self::HR_NEW)->first();
        $ops = DB::table('menus')->where('type', 2)->where('name', self::OPS)->first();

        if ($group !== null) {
            $users = DB::table('menus')->whereNull('parent_id')->where('name', 'User Management')->value('id');
            $reports = DB::table('menus')->whereNull('parent_id')->where('name', 'Reports')->value('id');
            DB::table('menus')->where('id', $group->id)->update(['name' => self::HR_OLD, 'updated_at' => now()]);

            foreach (['/report/payroll-register', '/report/attendance-summary', '/report/statutory-liability', '/report/loan-balances'] as $path) {
                if ($reports !== null) {
                    DB::table('menus')->where('route_path', $path)->update(['parent_id' => $reports, 'updated_at' => now()]);
                }
            }

            MenuMigration::remove(['/hrm/leave', '/hrm/loans', '/hrm/shifts', '/hrm/documents']);
            DB::table('menus')->where('route_path', '/hrm/manage')->update(['name' => 'HR Setup', 'updated_at' => now()]);
            DB::table('menus')->where('route_path', '/hrm/attendance')->update(['name' => 'Attendance and Leave', 'updated_at' => now()]);

            unset($users);
        }

        if ($ops !== null) {
            $users = DB::table('menus')->whereNull('parent_id')->where('name', 'User Management')->value('id');
            $accounts = DB::table('menus')->whereNull('parent_id')->where('name', 'Accounts')->value('id');

            foreach (array_keys(self::OPS_ORDER) as $path) {
                DB::table('menus')->where('route_path', $path)->where('parent_id', $ops->id)->update(['parent_id' => $path === '/project' ? $accounts : $users, 'updated_at' => now()]);
            }

            DB::table('menus')->where('id', $ops->id)->delete();
        }

        $this->flush();
    }

    private function copyAccess(string $sourcePath, string $newPath): void
    {
        $source = DB::table('menus')->where('route_path', $sourcePath)->value('id');
        $new = DB::table('menus')->where('route_path', $newPath)->value('id');

        if ($source === null || $new === null) {
            return;
        }

        foreach (DB::table('permissions')->where('menu_id', $source)->get() as $row) {
            $exists = DB::table('permissions')->where('menu_id', $new)->where('role_id', $row->role_id)->where('company_id', $row->company_id)->where('branch_id', $row->branch_id)->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'company_id' => $row->company_id, 'branch_id' => $row->branch_id, 'department_id' => $row->department_id, 'role_id' => $row->role_id,
                    'menu_id' => $new, 'status' => $row->status, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function group(string $name, string $icon): ?int
    {
        $existing = DB::table('menus')->where('type', 2)->where('name', $name)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $anyChild = DB::table('menus')->whereIn('route_path', array_keys(self::OPS_ORDER))->exists();

        if (! $anyChild) {
            return null;
        }

        $nullable = (bool) (collect(Schema::getColumns('menus'))->firstWhere('name', 'route_path')['nullable'] ?? false);
        $after = DB::table('menus')->whereNull('parent_id')->where('name', self::HR_NEW)->value('sort_order')
            ?? DB::table('menus')->whereNull('parent_id')->where('name', 'User Management')->value('sort_order');

        return (int) DB::table('menus')->insertGetId([
            'parent_id' => null, 'name' => $name, 'icon' => $icon, 'route_name' => '', 'route_path' => $nullable ? null : '', 'menu_color' => '#199683',
            'sort_order' => (int) ($after ?? 0) + 1, 'is_hidden' => 0, 'is_active' => 1, 'is_permission' => 0, 'type' => 2, 'is_admin' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $order
     */
    private function arrange(int $groupId, array $order): void
    {
        foreach ($order as $path => [$name, $position]) {
            DB::table('menus')->where('route_path', $path)->update(['parent_id' => $groupId, 'name' => $name, 'is_hidden' => 0, 'sort_order' => $position, 'updated_at' => now()]);
        }
    }

    private function flush(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
                Cache::forget("{$key}:{$roleId}");
            }
        }
    }
};
