<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cost Center / Profit Center Analysis, and the department-wise and branch-wise view of the same books: revenue,
 * cost of goods sold and expenses of the approved vouchers in the range (default the last 30 days), grouped by cost
 * center, by the department the cost center belongs to, or by branch. Revenue is the credit side of the 5xx
 * accounts, cost of goods sold and expenses the debit side of the 6xx and 4xx accounts (by the first digit of the
 * code, like the financial statements). Lines with no cost center fall under "Unallocated" (or "No department").
 * One company only. The grouping arrives as the report's `status` filter.
 */
class CostCenterAnalysisReport
{
    public const GROUPS = ['cost_center', 'department', 'branch'];

    public const SORTABLE = [
        'id' => 'id',
        'name' => 'name',
        'revenue' => 'revenue',
        'cogs' => 'cogs',
        'expenses' => 'expenses',
        'net' => 'net',
    ];

    public const DEFAULT_SORT = 'name';

    public const DEFAULT_DESC = false;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        if ($companyId === null) {
            return collect();
        }

        [$from, $to] = ReportDates::range($filters);
        $group = in_array($filters['status'] ?? null, self::GROUPS, true) ? $filters['status'] : 'cost_center';

        [$idColumn, $join, $codeColumn, $typeColumn, $empty] = match ($group) {
            'department' => ['cc.department_id', 'left join departments g on g.id = cc.department_id', "''", "'department'", 'No department'],
            'branch' => ['a.branch_id', 'left join branches g on g.id = a.branch_id', "''", "'branch'", 'No branch'],
            default => ['d.cost_center_id', 'left join cost_centers g on g.id = d.cost_center_id', 'g.code', "coalesce(g.type, '')", 'Unallocated'],
        };

        return collect(DB::select(
            "select coalesce({$idColumn}, 0) as id, g.name as gname, {$codeColumn} as gcode, {$typeColumn} as kind,
                coalesce(sum(case when substr(d.account_code, 1, 1) = '5' then d.credit - d.debit else 0 end), 0) as revenue,
                coalesce(sum(case when substr(d.account_code, 1, 1) = '6' then d.debit - d.credit else 0 end), 0) as cogs,
                coalesce(sum(case when substr(d.account_code, 1, 1) = '4' then d.debit - d.credit else 0 end), 0) as expenses
             from t_account_details d
             join t_accounts a on a.id = d.t_account_id
             left join cost_centers cc on cc.id = d.cost_center_id
             {$join}
             where a.company_id = ? and a.status = 'approved' and a.voucher_date >= ? and a.voucher_date <= ?
               and substr(d.account_code, 1, 1) in ('4', '5', '6')".($branchId !== null ? ' and a.branch_id = ?' : '').'
             group by 1, 2, 3, 4',
            array_values(array_filter([$companyId, $from, $to, $branchId], fn ($value) => $value !== null)),
        ))->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => $row->gname === null ? $empty : trim(((string) $row->gcode !== '' ? $row->gcode.' - ' : '').$row->gname),
            'kind' => (string) $row->kind,
            'revenue' => round((float) $row->revenue, 2),
            'cogs' => round((float) $row->cogs, 2),
            'expenses' => round((float) $row->expenses, 2),
            'net' => round((float) $row->revenue - (float) $row->cogs - (float) $row->expenses, 2),
        ])->sortBy('name')->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'groups' => $rows->count(),
            'revenue' => round((float) $rows->sum('revenue'), 2),
            'cogs' => round((float) $rows->sum('cogs'), 2),
            'expenses' => round((float) $rows->sum('expenses'), 2),
            'net' => round((float) $rows->sum('net'), 2),
        ];
    }
}
