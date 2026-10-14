<?php

namespace App\Services\Reports;

use App\Services\Manufacturing\ManufacturingSettings;
use Illuminate\Support\Facades\DB;

/**
 * Whether a company already carries its stock in the ledger. The standard set-up does not (purchases post to the Purchases account and the
 * balance sheet adds the value of the stock as a line, see BalanceSheetReport); a company that journals its stock itself has an asset
 * account for it with postings, and adding the valuation as well would count the stock twice.
 *
 * Stock is treated as journalled when any of these accounts has a posting or a stored opening balance:
 *  - the account mapped to the `inventory` posting key (Account Mapping > Inventory), and
 *  - the raw material, work in progress and finished goods accounts of the manufacturing posting.
 */
class JournalledStock
{
    public function __construct(private readonly ManufacturingSettings $manufacturing) {}

    public function exists(int $companyId, ?int $branchId = null): bool
    {
        $accountIds = DB::table('chart_of_account_mappings')->where('company_id', $companyId)->where('key', 'inventory')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('value')->where('value', '!=', '')->pluck('value')->map(fn ($v): int => (int) $v)->all();

        $slots = $this->manufacturing->all($companyId)['accounts'] ?? [];

        foreach (['raw_material_inventory', 'wip', 'finished_goods_inventory'] as $slot) {
            if (! empty($slots[$slot])) {
                $accountIds[] = (int) $slots[$slot];
            }
        }

        $accountIds = array_values(array_unique(array_filter($accountIds)));

        if ($accountIds === []) {
            return false;
        }

        $posted = DB::table('t_account_details as d')->join('t_accounts as t', 't.id', '=', 'd.t_account_id')
            ->where('t.company_id', $companyId)->whereIn('d.coa_id', $accountIds)->where('t.status', 'approved')
            ->where(fn ($q) => $q->where('d.debit', '!=', 0)->orWhere('d.credit', '!=', 0))->exists();

        if ($posted) {
            return true;
        }

        return DB::table('account_balances')->where('company_id', $companyId)->whereIn('coa_id', $accountIds)->where('opening_balance', '!=', 0)->exists();
    }
}
