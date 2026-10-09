<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

/**
 * Gives a branch its Withholding Tax Receivable / Payable accounts and maps them, so a sale or purchase with withholding
 * tax can be saved without a manual onboarding step. An account of that name already in the branch's chart is reused,
 * otherwise it is created (under Current Assets / Current Liabilities when the chart has that group, else directly under
 * the Assets / Liabilities root). A mapping that is already filled is never touched, so it is safe to run again.
 */
class WithholdingAccountSetup
{
    /**
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}> mapping key => name, group name, root name, nature
     */
    private const ACCOUNTS = [
        WithholdingSettlement::SELL_ACCOUNT_KEY => ['Withholding Tax Receivable', 'Current Assets', 'Assets', 'dr'],
        WithholdingSettlement::PURCHASE_ACCOUNT_KEY => ['Withholding Tax Payable', 'Current Liabilities', 'Liabilities', 'cr'],
    ];

    public function ensureForBranch(int $companyId, int $branchId): void
    {
        foreach (self::ACCOUNTS as $key => [$name, $groupName, $rootName, $nature]) {
            $mapping = DB::table('chart_of_account_mappings')->where('company_id', $companyId)->where('branch_id', $branchId)->where('key', $key)->first();

            if ($mapping === null || ($mapping->value !== null && $mapping->value !== '')) {
                continue;
            }

            $account = ChartOfAccount::query()->where('company_id', $companyId)->where('branch_id', $branchId)->where('name', $name)->first()
                ?? $this->createAccount($companyId, $branchId, $name, $groupName, $rootName, $nature);

            if ($account !== null) {
                DB::table('chart_of_account_mappings')->where('id', $mapping->id)->update(['value' => (string) $account->id, 'updated_at' => now()]);
            }
        }
    }

    private function createAccount(int $companyId, int $branchId, string $name, string $groupName, string $rootName, string $nature): ?ChartOfAccount
    {
        $scope = fn ($query) => $query->where('company_id', $companyId)->where('branch_id', $branchId);
        $parent = ChartOfAccount::query()->tap($scope)->where('name', $groupName)->whereNotNull('parent_id')->first()
            ?? ChartOfAccount::query()->tap($scope)->where('name', $rootName)->whereNull('parent_id')->first();

        if ($parent === null) {
            return null;
        }

        return ChartOfAccount::query()->create([
            'parent_id' => $parent->id,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'name' => $name,
            'code' => ChartOfAccount::generateAccountCode((int) $parent->id, $companyId, $branchId),
            'acc_type' => 't',
            'acc_nature' => $nature,
            'active' => true,
            'pl' => $parent->pl,
            'bs' => $parent->bs,
        ]);
    }
}
