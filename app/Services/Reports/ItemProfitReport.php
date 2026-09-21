<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use stdClass;

/**
 * Item profit and loss: every sold line with what it cost and what it earned.
 *
 * The cost of a line is the weighted average purchase cost per base unit of its variation on the day of
 * the sale (AverageCost) times the base units that stayed sold, because the app keeps no per-sale cost
 * (the old report read FIFO links that this app never writes). The revenue is the line amount less the
 * share that was returned. A variation with no purchase up to that day has no cost: its cost and profit
 * are null, it is counted in `without_cost` and it stays out of the cost and profit totals, so an
 * unknown cost is never shown as a profit of 100%.
 */
class ItemProfitReport
{
    public const SORTABLE = [
        'transaction_date' => 'transaction_date',
        'invoice_no' => 'invoice_no',
        'contact_name' => 'contact_name',
        'product_name' => 'product_name',
        'sku' => 'sku',
        'net_base_quantity' => 'net_base_quantity',
        'net_amount' => 'net_amount',
        'average_cost' => 'average_cost',
        'cost' => 'cost',
        'profit' => 'profit',
        'margin' => 'margin',
    ];

    public function __construct(private readonly ProductLineReport $lines) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        return $this->lines->query('sell', $companyId, $branchId, $filters)
            ->selectSub(AverageCost::correlated('l.variation_id', 't.company_id', 't.transaction_date'), 'average_cost')
            ->get()
            ->map(fn (stdClass $row): array => $this->present($row))
            ->sortBy([['transaction_date', 'desc'], ['id', 'desc']])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, sales: float, cost: float, profit: float, margin: float, without_cost: int}
     */
    public function summary(Collection $rows): array
    {
        $costed = $rows->whereNotNull('cost');
        $sales = round((float) $costed->sum('net_amount'), 2);
        $cost = round((float) $costed->sum('cost'), 2);

        return [
            'count' => $rows->count(),
            'sales' => round((float) $rows->sum('net_amount'), 2),
            'cost' => $cost,
            'profit' => round($sales - $cost, 2),
            'margin' => $sales > 0 ? round(($sales - $cost) / $sales * 100, 2) : 0.0,
            'without_cost' => $rows->whereNull('cost')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(stdClass $row): array
    {
        $packing = $row->packing_qty !== null && (float) $row->packing_qty >= 1 ? (float) $row->packing_qty : 1.0;
        $quantity = (float) $row->quantity;
        $returned = (float) $row->quantity_returned;
        $netBase = round(($quantity - $returned) * $packing, 4);
        $returnedShare = $quantity > 0 ? min($returned / $quantity, 1) : 0.0;
        $netAmount = round((float) $row->amount * (1 - $returnedShare), 2);
        $averageCost = $row->average_cost === null ? null : round((float) $row->average_cost, 4);
        $cost = $averageCost === null ? null : round($averageCost * $netBase, 2);
        $profit = $cost === null ? null : round($netAmount - $cost, 2);

        return [
            'id' => (int) $row->id,
            'transaction_date' => substr((string) $row->transaction_date, 0, 10),
            'invoice_no' => $row->invoice_no,
            'contact_name' => TransactionListReport::contactName($row),
            'product_name' => (string) ($row->product_name ?? '-'),
            'sku' => (string) ($row->sku ?? ''),
            'variation_name' => (string) ($row->variation_name ?? ''),
            'branch_name' => (string) ($row->branch_name ?? ''),
            'unit_name' => (string) ($row->base_unit_name ?? ''),
            'net_base_quantity' => $netBase,
            'net_amount' => $netAmount,
            'average_cost' => $averageCost,
            'cost' => $cost,
            'profit' => $profit,
            'margin' => $profit !== null && $netAmount > 0 ? round($profit / $netAmount * 100, 2) : null,
        ];
    }
}
