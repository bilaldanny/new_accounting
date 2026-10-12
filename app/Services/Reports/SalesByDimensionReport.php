<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales split by who made them (the user who saved the sale), from which warehouse they left, or how they were paid.
 *
 * Salesperson and warehouse: the posted sales of the range (drafts and quotations excluded, as everywhere), less the sales returns
 * made against those sales in the range; net sales, tax and discount per group, number of sales and the average sale. A sale without a
 * warehouse (a service, or one taken before warehouses) is grouped as "Unassigned". Payment method: what was received on sales in the
 * range by method (cash, card, bank, cheque ...), less refunds paid out on sale returns. Money is by the payment date, not the
 * sale date, so it is a cash view and is not expected to add up to the net sales of the same range.
 */
class SalesByDimensionReport
{
    public const DIMENSIONS = ['salesperson', 'warehouse', 'payment-method'];

    public const SORTABLE = [
        'name' => 'name',
        'sales_count' => 'sales_count',
        'gross' => 'gross',
        'returned' => 'returned',
        'net' => 'net',
        'tax' => 'tax',
        'discount' => 'discount',
        'average_sale' => 'average_sale',
        'share' => 'share',
    ];

    public const DEFAULT_SORT = 'net';

    public function __construct(private readonly string $dimension = 'salesperson') {}

    public static function for(string $dimension): self
    {
        return new self($dimension);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters);

        $rows = $this->dimension === 'payment-method'
            ? $this->byPaymentMethod($companyId, $branchId, $from, $to)
            : $this->byTransactionColumn($companyId, $branchId, $from, $to, $this->dimension === 'warehouse' ? 'warehouse_id' : 'created_by');

        $total = (float) $rows->sum('net');

        return $rows->map(fn (array $row): array => $row + ['share' => $total > 0 ? round($row['net'] / $total * 100, 1) : 0.0])
            ->sortByDesc('net')->values()->map(fn (array $row, int $index): array => ['id' => $index + 1] + $row);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int|string>
     */
    public function summary(Collection $rows): array
    {
        $count = (int) $rows->sum('sales_count');

        return [
            'count' => $rows->count(),
            'sales_count' => $count,
            'gross' => round((float) $rows->sum('gross'), 2),
            'returned' => round((float) $rows->sum('returned'), 2),
            'net' => round((float) $rows->sum('net'), 2),
            'average_sale' => $count > 0 ? round((float) $rows->sum('net') / $count, 2) : 0.0,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function byTransactionColumn(?int $companyId, ?int $branchId, string $from, string $to, string $column): Collection
    {
        $scope = fn ($query, string $prefix) => $query
            ->when($companyId !== null, fn ($q) => $q->where("{$prefix}.company_id", $companyId))
            ->when($branchId !== null, fn ($q) => $q->where("{$prefix}.branch_id", $branchId))
            ->whereNull("{$prefix}.deleted_at")
            ->whereDate("{$prefix}.transaction_date", '>=', $from)
            ->whereDate("{$prefix}.transaction_date", '<=', $to);

        $sales = $scope(DB::table('transactions as t')->where('t.type', Transaction::TYPE_SELL)->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES), 't')
            ->groupBy("t.{$column}")
            ->selectRaw("t.{$column} as subject_id, count(*) as sales_count, sum(t.final_amount) as gross, sum(coalesce(t.tax_amount, 0)) as tax, sum(coalesce(t.discount_amount, 0)) as discount")
            ->get()->keyBy(fn (object $row): string => (string) ($row->subject_id ?? ''));

        $returns = $scope(DB::table('transactions as r')->join('transactions as t', 't.id', '=', 'r.parent_id')->where('r.type', Transaction::TYPE_SELL_RETURN), 'r')
            ->groupBy("t.{$column}")
            ->selectRaw("t.{$column} as subject_id, sum(r.final_amount) as returned")
            ->pluck('returned', 'subject_id');
        $returned = $returns->mapWithKeys(fn ($amount, $id): array => [(string) ($id ?? '') => (float) $amount]);

        $names = $this->names($column, $sales->keys()->merge($returned->keys())->filter()->unique()->all());

        return $sales->keys()->merge($returned->keys())->unique()->values()->map(function (string $id) use ($sales, $returned, $names): array {
            $gross = round((float) ($sales[$id]->gross ?? 0), 2);
            $back = round((float) ($returned[$id] ?? 0), 2);
            $count = (int) ($sales[$id]->sales_count ?? 0);
            $net = round($gross - $back, 2);

            return [
                'subject_id' => $id === '' ? null : (int) $id,
                'name' => $id === '' ? 'Unassigned' : (string) ($names[$id] ?? '#'.$id),
                'sales_count' => $count, 'gross' => $gross, 'returned' => $back, 'net' => $net,
                'tax' => round((float) ($sales[$id]->tax ?? 0), 2), 'discount' => round((float) ($sales[$id]->discount ?? 0), 2),
                'average_sale' => $count > 0 ? round($net / $count, 2) : 0.0,
            ];
        });
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<string, string>
     */
    private function names(string $column, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        if ($column === 'warehouse_id') {
            return DB::table('warehouses')->whereIn('id', $ids)->pluck('name', 'id')->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name]);
        }

        return DB::table('users')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (object $user): array => [(string) $user->id => trim($user->first_name.' '.$user->last_name)]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function byPaymentMethod(?int $companyId, ?int $branchId, string $from, string $to): Collection
    {
        $rows = DB::table('payments as p')
            ->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->whereIn('t.type', [Transaction::TYPE_SELL, Transaction::TYPE_SELL_RETURN])
            ->whereNull('t.deleted_at')
            ->when($companyId !== null, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->whereDate('p.paid_on', '>=', $from)
            ->whereDate('p.paid_on', '<=', $to)
            ->groupBy('p.method')
            ->selectRaw('p.method, sum(case when t.type = ? then p.amount else 0 end) as received, sum(case when t.type = ? then p.amount else 0 end) as refunded, count(*) as payments', [Transaction::TYPE_SELL, Transaction::TYPE_SELL_RETURN])
            ->get();

        return $rows->map(function (object $row): array {
            $gross = round((float) $row->received, 2);
            $back = round((float) $row->refunded, 2);

            return [
                'subject_id' => null, 'name' => ucfirst((string) $row->method), 'sales_count' => (int) $row->payments,
                'gross' => $gross, 'returned' => $back, 'net' => round($gross - $back, 2), 'tax' => 0.0, 'discount' => 0.0,
                'average_sale' => (int) $row->payments > 0 ? round(($gross - $back) / (int) $row->payments, 2) : 0.0,
            ];
        });
    }
}
