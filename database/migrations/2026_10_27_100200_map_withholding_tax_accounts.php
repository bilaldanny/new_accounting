<?php

use App\Models\ChartOfAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A sale or purchase with withholding tax cannot be saved while the branch has no Withholding Tax Receivable / Payable
 * account mapped. Every branch with such an empty mapping is given one: an account of that name that already exists in its
 * chart is used, otherwise it is created (the receivable under Current Assets, the payable under Current Liabilities) and
 * mapped. A mapping that is already filled is never touched.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}> mapping key => name, group name, root name, nature
     */
    private const ACCOUNTS = [
        'withholdingreceivable' => ['Withholding Tax Receivable', 'Current Assets', 'Assets', 'dr'],
        'withholdingpayable' => ['Withholding Tax Payable', 'Current Liabilities', 'Liabilities', 'cr'],
    ];

    public function up(): void
    {
        foreach (self::ACCOUNTS as $key => [$name, $groupName, $rootName, $nature]) {
            $mappings = DB::table('chart_of_account_mappings')->where('key', $key)
                ->where(fn ($query) => $query->whereNull('value')->orWhere('value', ''))->get();

            foreach ($mappings as $mapping) {
                $account = ChartOfAccount::query()->where('company_id', $mapping->company_id)->where('branch_id', $mapping->branch_id)
                    ->where('name', $name)->first()
                    ?? $this->createAccount((int) $mapping->company_id, (int) $mapping->branch_id, $name, $groupName, $rootName, $nature);

                if ($account !== null) {
                    DB::table('chart_of_account_mappings')->where('id', $mapping->id)->update(['value' => (string) $account->id, 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::ACCOUNTS as $key => [$name]) {
            $accountIds = ChartOfAccount::query()->where('name', $name)->pluck('id');

            DB::table('chart_of_account_mappings')->where('key', $key)->whereIn('value', $accountIds->map(fn (mixed $id): string => (string) $id))
                ->update(['value' => null, 'updated_at' => now()]);

            foreach ($accountIds as $accountId) {
                if (! DB::table('t_account_details')->where('coa_id', $accountId)->exists()) {
                    ChartOfAccount::query()->whereKey($accountId)->forceDelete();
                }
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
};
