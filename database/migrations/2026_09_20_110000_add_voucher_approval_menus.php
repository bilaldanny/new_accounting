<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval for the Payment, Expense, Deposit and Fund Transfer vouchers, the same shape as
 * add_journal_entry_approval_menu: a visible "<Voucher> Approval" list row next to Purchase, Sell
 * and Journal Entry Approval in the Approval group, plus the hidden `/…/:id/approve` and
 * `/…/:id/reject` permission rows under the voucher's own menu row (the keys the approval
 * controller checks). Payment also gets the `/acpayment/:id/view` row the other three already have.
 *
 * Permissions are not granted here: roles get the rows from the Role Permission screen.
 */
return new class extends Migration
{
    /**
     * Voucher menu path => [label, list route_name, approve route_name, reject route_name].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const VOUCHERS = [
        '/acpayment' => ['Payment', 'acpaymentapprovals', 'acpaymentapproval', 'acpaymentreject'],
        '/expense' => ['Expense', 'expenseapprovals', 'expenseapproval', 'expensereject'],
        '/deposit' => ['Deposit', 'depositapprovals', 'depositapproval', 'depositreject'],
        '/fundtransfer' => ['Fund Transfer', 'fundtransferapprovals', 'fundtransferapproval', 'fundtransferreject'],
    ];

    public function up(): void
    {
        $approvalGroupId = DB::table('menus')->where('route_path', '/purchase/approval')->value('parent_id')
            ?? DB::table('menus')->where('route_path', '/sell/approval')->value('parent_id');

        foreach (self::VOUCHERS as $path => [$label, $listRoute, $approveRoute, $rejectRoute]) {
            $voucherMenuId = DB::table('menus')->where('route_path', $path)->value('id');

            if ($voucherMenuId === null) {
                continue;
            }

            if ($approvalGroupId !== null) {
                $this->insertOnce([
                    'parent_id' => $approvalGroupId,
                    'name' => $label.' Approval',
                    'route_name' => $listRoute,
                    'route_path' => $path.'/approval',
                    'sort_order' => (int) DB::table('menus')->where('parent_id', $approvalGroupId)->max('sort_order') + 1,
                    'is_hidden' => 0,
                    'is_permission' => 0,
                    'icon' => 'bx bx-buildings',
                    'menu_color' => '#6a0dad',
                ]);
            }

            foreach ([['Approve', $approveRoute, '/:id/approve'], ['Reject', $rejectRoute, '/:id/reject']] as [$action, $routeName, $suffix]) {
                $this->insertOnce($this->hiddenRow((int) $voucherMenuId, $label.' '.$action, $routeName, $path.$suffix));
            }
        }

        $paymentMenuId = DB::table('menus')->where('route_path', '/acpayment')->value('id');

        if ($paymentMenuId !== null) {
            $this->insertOnce($this->hiddenRow((int) $paymentMenuId, 'View Payment', 'viewacpayment', '/acpayment/:id/view'));
        }

        $this->flushMenuCaches();
    }

    public function down(): void
    {
        $paths = ['/acpayment/:id/view'];

        foreach (array_keys(self::VOUCHERS) as $path) {
            array_push($paths, $path.'/approval', $path.'/:id/approve', $path.'/:id/reject');
        }

        $ids = DB::table('menus')->whereIn('route_path', $paths)->pluck('id');

        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();

        $this->flushMenuCaches();
    }

    /**
     * @return array<string, mixed>
     */
    private function hiddenRow(int $parentId, string $name, string $routeName, string $routePath): array
    {
        return [
            'parent_id' => $parentId,
            'name' => $name,
            'route_name' => $routeName,
            'route_path' => $routePath,
            'sort_order' => 1,
            'is_hidden' => 1,
            'is_permission' => 1,
            'icon' => '',
            'menu_color' => '#6a0dad',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertOnce(array $row): void
    {
        if (DB::table('menus')->where('route_path', $row['route_path'])->exists()) {
            return;
        }

        $row += ['is_active' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now()];

        if (Schema::hasColumn('menus', 'is_admin')) {
            $row['is_admin'] = 0;
        }

        DB::table('menus')->insert($row);
    }

    private function flushMenuCaches(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            Cache::forget("user_menu_permissions_tree:{$roleId}");
            Cache::forget("user_permission_paths:{$roleId}");
        }
    }
};
