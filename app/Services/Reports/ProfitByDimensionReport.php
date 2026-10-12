<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Profit grouped by customer, branch, salesperson (the user who saved the sale) or warehouse. It is built on the lines of Item Profit
 * & Loss, so the cost of a line is exactly what that report shows for it: the company's costing method on the day of the sale (or
 * the cost a recalculation locked), and a line without a known cost is counted in `without_cost` and stays out of cost and profit
 * rather than showing as a profit of 100%. The revenue is the line amount less the returned share.
 */
class ProfitByDimensionReport
{
    public const DIMENSIONS = ['customer', 'branch', 'salesperson', 'warehouse'];

    public const SORTABLE = [
        'name' => 'name',
        'lines' => 'lines',
        'sales' => 'sales',
        'cost' => 'cost',
        'profit' => 'profit',
        'margin' => 'margin',
        'without_cost' => 'without_cost',
    ];

    public const DEFAULT_SORT = 'profit';

    public function __construct(private readonly ItemProfitReport $items, private readonly string $dimension = 'customer') {}

    public function forDimension(string $dimension): self
    {
        return new self($this->items, $dimension);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters);
        $lines = $this->items->rowsWithTransaction($companyId, $branchId, ['start_date' => $from, 'end_date' => $to] + array_intersect_key($filters, ['contact_id' => 1, 'search' => 1]));

        if ($lines->isEmpty()) {
            return collect();
        }

        $column = ['customer' => 'contact_id', 'branch' => 'branch_id', 'salesperson' => 'created_by', 'warehouse' => 'warehouse_id'][$this->dimension];
        $owners = DB::table('transactions')->whereIn('id', $lines->pluck('txn_id')->unique()->all())->pluck($column, 'id');
        $names = $this->names($owners->filter()->unique()->values()->all());

        return $lines->groupBy(fn (array $line): string => (string) ($owners[$line['txn_id']] ?? ''))
            ->map(function (Collection $group, string $key) use ($names): array {
                $costed = $group->whereNotNull('cost');
                $sales = round((float) $group->sum('net_amount'), 2);
                $costedSales = round((float) $costed->sum('net_amount'), 2);
                $cost = round((float) $costed->sum('cost'), 2);
                $profit = round($costedSales - $cost, 2);

                return [
                    'subject_id' => $key === '' ? null : (int) $key,
                    'name' => $key === '' ? 'Unassigned' : (string) ($names[$key] ?? '#'.$key),
                    'lines' => $group->count(), 'sales' => $sales, 'cost' => $cost, 'profit' => $profit,
                    'margin' => $costedSales > 0 ? round($profit / $costedSales * 100, 2) : null,
                    'without_cost' => $group->whereNull('cost')->count(),
                ];
            })->sortByDesc('profit')->values()->map(fn (array $row, int $index): array => ['id' => $index + 1] + $row);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        $sales = round((float) $rows->sum('sales'), 2);
        $profit = round((float) $rows->sum('profit'), 2);

        return [
            'count' => $rows->count(), 'sales' => $sales, 'cost' => round((float) $rows->sum('cost'), 2), 'profit' => $profit,
            'margin' => $sales > 0 ? round($profit / $sales * 100, 2) : 0.0, 'without_cost' => (int) $rows->sum('without_cost'),
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return Collection<string, string>
     */
    private function names(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return match ($this->dimension) {
            'customer' => DB::table('contacts')->whereIn('id', $ids)->get(['id', 'business_name', 'first_name', 'last_name'])
                ->mapWithKeys(fn (object $c): array => [(string) $c->id => trim((string) ($c->business_name ?: $c->first_name.' '.$c->last_name))]),
            'branch' => DB::table('branches')->whereIn('id', $ids)->pluck('name', 'id')->mapWithKeys(fn ($n, $id): array => [(string) $id => (string) $n]),
            'warehouse' => DB::table('warehouses')->whereIn('id', $ids)->pluck('name', 'id')->mapWithKeys(fn ($n, $id): array => [(string) $id => (string) $n]),
            default => DB::table('users')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn (object $u): array => [(string) $u->id => trim($u->first_name.' '.$u->last_name)]),
        };
    }
}
