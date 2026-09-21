<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Tax on purchases and sales, one row per document, with the net tax to pay.
 *
 * Output tax is what was charged on sales less what was given back on sell returns; input tax is what was
 * paid on purchases less what came back on purchase returns; net tax payable = output - input. The tax is
 * the document's own `tax_amount`: the app keeps no tax per line (the old report also added a per-line
 * `item_tax` that does not exist here). Documents count like everywhere else (every sell except a draft
 * or quotation, every purchase except a draft, returns always) and the range is on the document date,
 * inclusive at both ends.
 */
class TaxReport
{
    public const SIDES = ['all', 'input', 'output'];

    /**
     * @var array<string, array{side: string, sign: int, label: string, excluded: list<string>}>
     */
    private const DOCUMENTS = [
        Transaction::TYPE_PURCHASE => ['side' => 'input', 'sign' => 1, 'label' => 'Purchase', 'excluded' => ['draft']],
        Transaction::TYPE_PURCHASE_RETURN => ['side' => 'input', 'sign' => -1, 'label' => 'Purchase return', 'excluded' => []],
        Transaction::TYPE_SELL => ['side' => 'output', 'sign' => 1, 'label' => 'Sale', 'excluded' => Transaction::UNPOSTED_SELL_STATUSES],
        Transaction::TYPE_SELL_RETURN => ['side' => 'output', 'sign' => -1, 'label' => 'Sell return', 'excluded' => []],
    ];

    public const SORTABLE = [
        'transaction_date' => 'transaction_date',
        'document' => 'document',
        'side' => 'side',
        'invoice_no' => 'invoice_no',
        'contact_name' => 'contact_name',
        'total_before_tax' => 'total_before_tax',
        'tax_amount' => 'tax_amount',
        'final_amount' => 'final_amount',
    ];

    /**
     * @param  array{contact_id?: mixed, tax_side?: ?string, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        $side = trim((string) ($filters['tax_side'] ?? ''));
        $contactId = $filters['contact_id'] ?? null;
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('transactions as t')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->whereNull('t.deleted_at')
            ->where(function (Builder $documents): void {
                foreach (self::DOCUMENTS as $type => $config) {
                    $documents->orWhere(fn (Builder $one) => $one
                        ->where('t.type', $type)
                        ->when($config['excluded'] !== [], fn (Builder $q) => $q->whereNotIn('t.status', $config['excluded'])));
                }
            })
            ->when($companyId !== null, fn (Builder $query) => $query->where('t.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->when(! empty($contactId), fn (Builder $query) => $query->where('t.contact_id', $contactId))
            ->when($startDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '>=', $startDate))
            ->when($endDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '<=', $endDate))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $sub) use ($search): void {
                $sub->where('t.invoice_no', 'like', "%{$search}%")
                    ->orWhere('c.business_name', 'like', "%{$search}%")
                    ->orWhere('c.first_name', 'like', "%{$search}%")
                    ->orWhere('c.last_name', 'like', "%{$search}%");
            }))
            ->select([
                't.id',
                't.type',
                't.invoice_no',
                't.transaction_date',
                't.total_before_tax',
                't.tax_amount',
                't.final_amount',
                'b.name as branch_name',
                'c.business_name',
                'c.first_name',
                'c.last_name',
            ])
            ->get()
            ->map(function (stdClass $row): array {
                $config = self::DOCUMENTS[$row->type];

                return [
                    'id' => (int) $row->id,
                    'transaction_date' => substr((string) $row->transaction_date, 0, 10),
                    'document' => $config['label'],
                    'side' => $config['side'],
                    'sign' => $config['sign'],
                    'invoice_no' => $row->invoice_no,
                    'contact_name' => TransactionListReport::contactName($row),
                    'branch_name' => (string) ($row->branch_name ?? ''),
                    'total_before_tax' => round((float) $row->total_before_tax, 2),
                    'tax_amount' => round((float) $row->tax_amount, 2),
                    'final_amount' => round((float) $row->final_amount, 2),
                ];
            })
            ->when(in_array($side, ['input', 'output'], true), fn (Collection $rows) => $rows->where('side', $side))
            ->sortBy([['transaction_date', 'desc'], ['id', 'desc']])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, output_tax: float, input_tax: float, net_tax: float}
     */
    public function summary(Collection $rows): array
    {
        $tax = fn (string $side): float => round((float) $rows->where('side', $side)->sum(fn (array $row): float => $row['sign'] * $row['tax_amount']), 2);

        $output = $tax('output');
        $input = $tax('input');

        return [
            'count' => $rows->count(),
            'output_tax' => $output,
            'input_tax' => $input,
            'net_tax' => round($output - $input, 2),
        ];
    }
}
