<?php

namespace App\Services\Reports;

use App\Models\Budget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Budget vs Actual Variance Analysis: each budget line (a branch, cost center and account scope) against what the
 * approved ledger vouchers actually booked in the same range, from the same t_account_details the Cost Center Analysis
 * reads. The range defaults to the year so far. The budget of a range is the sum, over its months, of that scope's
 * monthly amount (a yearly budget counts a twelfth per month).
 *
 * "Actual" follows the budget's account: a revenue (5xx) account counts credit less debit, an expense or cost of goods
 * sold account debit less credit; a budget with no account counts all expense and cost of goods sold (4xx and 6xx) lines.
 * It is limited to the budget's cost center and branch when it has them. Variance is favourable when positive: budget
 * less actual for an expense, actual less budget for revenue. Budgets are planning data and nothing here posts anything.
 * One company only.
 */
class BudgetVsActualReport
{
    public const SORTABLE = [
        'id' => 'id',
        'cost_center_name' => 'cost_center_name',
        'account_name' => 'account_name',
        'budget' => 'budget',
        'actual' => 'actual',
        'variance' => 'variance',
    ];

    public const DEFAULT_SORT = 'cost_center_name';

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

        [$from, $to] = $this->range($filters);
        $months = $this->months($from, $to);

        $budgets = Budget::query()
            ->with(['costCenter:id,code,name', 'account:id,code,name', 'branch:id,name'])
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('year', array_unique(array_column($months, 0)))
            ->get();

        return $budgets
            ->groupBy(fn (Budget $budget): string => ($budget->branch_id ?? 0).'|'.($budget->cost_center_id ?? 0).'|'.($budget->coa_id ?? 0))
            ->map(function (Collection $lines) use ($months, $companyId, $from, $to): ?array {
                /** @var Budget $first */
                $first = $lines->first();
                $planned = 0.0;

                foreach ($months as [$year, $month]) {
                    foreach ($lines as $budget) {
                        if ($budget->year === $year && ($budget->month === null || $budget->month === $month)) {
                            $planned += $budget->monthlyAmount();
                        }
                    }
                }

                if ($planned <= 0.0) {
                    return null;
                }

                $revenue = $first->account !== null && str_starts_with((string) $first->account->code, '5');
                $actual = $this->actual($companyId, $first, $revenue, $from, $to);
                $planned = round($planned, 2);
                $variance = round($revenue ? $actual - $planned : $planned - $actual, 2);

                return [
                    'cost_center_name' => $first->costCenter ? $first->costCenter->code.' - '.$first->costCenter->name : 'All cost centers',
                    'branch_name' => $first->branch?->name ?? 'Whole company',
                    'account_name' => $first->account ? $first->account->code.' - '.$first->account->name : 'All expenses',
                    'kind' => $revenue ? 'revenue' : 'expense',
                    'budget' => $planned,
                    'actual' => $actual,
                    'variance' => $variance,
                    'variance_percent' => round($variance / $planned * 100, 2),
                    'status' => $variance >= 0 ? ($revenue ? 'On or above target' : 'Within budget') : ($revenue ? 'Below target' : 'Over budget'),
                ];
            })
            ->filter()
            ->sortBy([['cost_center_name', 'asc'], ['account_name', 'asc']])
            ->values()
            ->map(fn (array $row, int $index): array => ['id' => $index + 1] + $row);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        $expenses = $rows->where('kind', 'expense');

        return [
            'lines' => $rows->count(),
            'budget' => round((float) $expenses->sum('budget'), 2),
            'actual' => round((float) $expenses->sum('actual'), 2),
            'variance' => round((float) $expenses->sum('variance'), 2),
            'over_budget' => $rows->where('variance', '<', 0)->count(),
        ];
    }

    private function actual(int $companyId, Budget $budget, bool $revenue, string $from, string $to): float
    {
        $query = DB::table('t_account_details as d')
            ->join('t_accounts as a', 'a.id', '=', 'd.t_account_id')
            ->where('a.company_id', $companyId)
            ->where('a.status', 'approved')
            ->whereDate('a.voucher_date', '>=', $from)
            ->whereDate('a.voucher_date', '<=', $to)
            ->when($budget->branch_id !== null, fn ($q) => $q->where('a.branch_id', $budget->branch_id))
            ->when($budget->cost_center_id !== null, fn ($q) => $q->where('d.cost_center_id', $budget->cost_center_id));

        if ($budget->account !== null) {
            $query->where('d.account_code', $budget->account->code);
        } else {
            $query->where(fn ($q) => $q->whereRaw("substr(d.account_code, 1, 1) = '4'")->orWhereRaw("substr(d.account_code, 1, 1) = '6'"));
        }

        $net = (float) ($query->selectRaw('coalesce(sum(d.debit), 0) - coalesce(sum(d.credit), 0) as net')->value('net') ?? 0);

        return round($revenue ? -$net : $net, 2);
    }

    /**
     * The range asked for, else the year so far.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: string}
     */
    private function range(array $filters): array
    {
        $to = trim((string) ($filters['end_date'] ?? ''));
        $to = $to !== '' ? $to : now()->toDateString();
        $from = trim((string) ($filters['start_date'] ?? ''));

        return [$from !== '' ? $from : CarbonImmutable::parse($to)->startOfYear()->toDateString(), $to];
    }

    /**
     * Every [year, month] the range touches, in order.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function months(string $from, string $to): array
    {
        $months = [];
        $cursor = CarbonImmutable::parse($from)->startOfMonth();
        $end = CarbonImmutable::parse($to)->startOfMonth();

        while ($cursor <= $end && count($months) < 600) {
            $months[] = [(int) $cursor->year, (int) $cursor->month];
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }
}
