<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Sales per customer group for documents dated inside the range (inclusive).
 *
 * The group is not on the sale: `transactions` has no `customer_group_id`. It is the customer's group
 * (`contacts.customer_group_id`), so every sale and sell return is joined to its customer and grouped
 * by the customer's group as it is today. Customers without a group land in one "No group" row. Sales
 * count like everywhere else (every sell except a draft or quotation) and sell returns are taken off:
 * net sales = sales - sell returns. Groups with no sale or return in the range are not listed.
 */
class CustomerGroupReport
{
    public const NO_GROUP = 'No group';

    public const SORTABLE = [
        'group_name' => 'group_name',
        'customers' => 'customers',
        'invoices' => 'invoices',
        'sales' => 'sales',
        'sell_returns' => 'sell_returns',
        'net_sales' => 'net_sales',
    ];

    /**
     * @param  array{customer_group_id?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        return $this->base($companyId, $branchId, $filters)
            ->groupBy('c.customer_group_id', 'g.name')
            ->select(['c.customer_group_id as group_id', 'g.name as group_name'])
            ->selectRaw('count(distinct c.id) as customers')
            ->selectRaw('count(distinct case when t.type = ? then t.id end) as invoices', [Transaction::TYPE_SELL])
            ->selectRaw('coalesce(sum(case when t.type = ? then t.final_amount else 0 end), 0) as sales', [Transaction::TYPE_SELL])
            ->selectRaw('coalesce(sum(case when t.type = ? then t.final_amount else 0 end), 0) as sell_returns', [Transaction::TYPE_SELL_RETURN])
            ->get()
            ->map(function (stdClass $row): array {
                $sales = round((float) $row->sales, 2);
                $returns = round((float) $row->sell_returns, 2);

                return [
                    'id' => (int) ($row->group_id ?? 0),
                    'group_name' => $row->group_id === null ? self::NO_GROUP : (string) ($row->group_name ?? '-'),
                    'customers' => (int) $row->customers,
                    'invoices' => (int) $row->invoices,
                    'sales' => $sales,
                    'sell_returns' => $returns,
                    'net_sales' => round($sales - $returns, 2),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        $total = fn (string $key): float => round((float) $rows->sum($key), 2);

        return [
            'count' => $rows->count(),
            'customers' => (int) $rows->sum('customers'),
            'invoices' => (int) $rows->sum('invoices'),
            'sales' => $total('sales'),
            'sell_returns' => $total('sell_returns'),
            'net_sales' => $total('net_sales'),
        ];
    }

    /**
     * @param  array{customer_group_id?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     */
    private function base(?int $companyId, ?int $branchId, array $filters): Builder
    {
        $groupId = $filters['customer_group_id'] ?? null;
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('transactions as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('customer_groups as g', 'g.id', '=', 'c.customer_group_id')
            ->whereNull('t.deleted_at')
            ->whereNull('c.deleted_at')
            ->where(function (Builder $documents): void {
                $documents->where(fn (Builder $sell) => $sell
                    ->where('t.type', Transaction::TYPE_SELL)
                    ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES))
                    ->orWhere('t.type', Transaction::TYPE_SELL_RETURN);
            })
            ->when($companyId !== null, fn (Builder $query) => $query->where('t.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->when(! empty($groupId), fn (Builder $query) => $query->where('c.customer_group_id', $groupId))
            ->when($startDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '>=', $startDate))
            ->when($endDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '<=', $endDate))
            ->when($search !== '', fn (Builder $query) => $query->where('g.name', 'like', "%{$search}%"));
    }
}
