<?php

namespace App\Services\System;

use App\Services\Reports\PurchaseSaleSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Franchise / multi-owner groups: several companies that belong to one owner. The companies stay completely separate tenants - no
 * query here ever reads two companies at once, no row of one company is visible to another company's users and nothing is
 * posted across them. A group only gives its named owner (a user of one of the member companies) a read-only overview that is the
 * same month figures each member company would see itself, side by side. The superadmin creates groups and chooses members; a
 * company can be in one group at most (unique), and the owner must be a user of a member company.
 *
 * The totals row is only added when every member keeps its books in the same currency; otherwise adding them would be wrong.
 */
class FranchiseGroups
{
    /**
     * @param  list<int>  $companyIds
     *
     * @throws ValidationException
     */
    public function save(?int $groupId, string $name, int $ownerUserId, array $companyIds): int
    {
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        $owner = DB::table('users')->where('id', $ownerUserId)->where('is_active', true)->whereNull('deleted_at')->first();

        if ($companyIds === [] || DB::table('companies')->whereIn('id', $companyIds)->count() !== count($companyIds)) {
            throw ValidationException::withMessages(['company_ids' => ['Choose at least one existing company.']]);
        }

        if ($owner === null || ! in_array((int) $owner->company_id, $companyIds, true)) {
            throw ValidationException::withMessages(['owner_user_id' => ['The owner must be an active user of one of the member companies.']]);
        }

        $taken = DB::table('company_group_members')->whereIn('company_id', $companyIds)->when($groupId !== null, fn ($q) => $q->where('company_group_id', '!=', $groupId))->pluck('company_id');

        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages(['company_ids' => ['Company '.$taken->first().' already belongs to another group.']]);
        }

        return (int) DB::transaction(function () use ($groupId, $name, $ownerUserId, $companyIds): int {
            if ($groupId === null) {
                $groupId = (int) DB::table('company_groups')->insertGetId(['name' => $name, 'owner_user_id' => $ownerUserId, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('company_groups')->where('id', $groupId)->update(['name' => $name, 'owner_user_id' => $ownerUserId, 'updated_at' => now()]);
                DB::table('company_group_members')->where('company_group_id', $groupId)->delete();
            }

            foreach ($companyIds as $companyId) {
                DB::table('company_group_members')->insert(['company_group_id' => $groupId, 'company_id' => $companyId, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $groupId;
        });
    }

    /**
     * The overview of the groups a user owns (empty for anyone else).
     *
     * @return list<array<string, mixed>>
     */
    public function overviewFor(int $userId, ?Carbon $month = null): array
    {
        $month = ($month ?? Carbon::today())->copy()->startOfMonth();
        $groups = DB::table('company_groups')->where('owner_user_id', $userId)->orderBy('id')->get();
        $result = [];

        foreach ($groups as $group) {
            $rows = [];
            $currencies = [];

            foreach (DB::table('company_group_members as m')->join('companies as c', 'c.id', '=', 'm.company_id')->where('m.company_group_id', $group->id)->orderBy('c.name')->get(['c.id', 'c.name', 'c.code', 'c.tenant_status']) as $company) {
                $totals = app(PurchaseSaleSummary::class)->build((int) $company->id, null, ['start_date' => $month->toDateString(), 'end_date' => $month->copy()->endOfMonth()->toDateString()])['totals'];
                $currency = DB::table('company_settings as s')->leftJoin('currencies as cur', 'cur.id', '=', 's.currency_id')->where('s.company_id', $company->id)->value('cur.code');
                $currencies[] = (string) $currency;
                $rows[] = [
                    'company_id' => (int) $company->id, 'name' => $company->name, 'code' => $company->code, 'status' => $company->tenant_status, 'currency' => $currency,
                    'branches' => DB::table('branches')->where('company_id', $company->id)->whereNull('deleted_at')->count(),
                    'net_sales' => round((float) $totals['net_sales'], 2), 'net_purchases' => round((float) $totals['net_purchases'], 2),
                ];
            }

            $sameCurrency = count(array_unique($currencies)) === 1 && $currencies[0] !== '';
            $result[] = [
                'id' => (int) $group->id, 'name' => $group->name, 'month' => $month->format('Y-m'), 'companies' => $rows,
                'totals' => $sameCurrency ? ['net_sales' => round(array_sum(array_column($rows, 'net_sales')), 2), 'net_purchases' => round(array_sum(array_column($rows, 'net_purchases')), 2), 'currency' => $currencies[0]] : null,
            ];
        }

        return $result;
    }
}
