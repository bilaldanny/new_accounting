<?php

namespace App\Services\Reports;

use App\Models\ChartOfAccountMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tax liability and its reconciliation to the ledger, for the range.
 *
 *  - From the documents (TaxReport): output tax on sales less returns, input tax on purchases less returns, net tax payable;
 *  - what was paid to the tax office (tax payments of the period) and the balance still to pay;
 *  - From the ledger: the postings of the output-tax and input-tax accounts mapped for the company's branches (credit less debit
 *    on the output account without tax payments, debit less credit on the input account), and the difference to the documents.
 *
 * A difference is not an error to hide: it points at a document edited around the ledger, an account mapped later, or a manual
 * voucher on the tax account, and the row says which of the figures it is.
 */
class TaxLiabilityReport
{
    public const SORTABLE = [
        'line' => 'id',
        'amount' => 'amount',
    ];

    public const DEFAULT_SORT = 'line';

    public const DEFAULT_DESC = false;

    public function __construct(private readonly TaxReport $documents, private readonly AccountFigures $figures) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, float|int|string>}
     */
    public function build(?int $companyId, ?int $branchId, array $filters): array
    {
        if ($companyId === null) {
            return ['rows' => collect(), 'summary' => []];
        }

        [$from, $to] = ReportDates::range($filters, 30);
        $docs = $this->documents->summary($this->documents->rows($companyId, $branchId, ['start_date' => $from, 'end_date' => $to]));
        $paid = round((float) DB::table('tax_payments')->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)->sum('amount'), 2);

        $output = $this->mappedCodes($companyId, 'outputtax');
        $input = $this->mappedCodes($companyId, 'inputtax');
        $period = $this->figures->period($companyId, $branchId, $from, $to);
        $ledgerOutput = round((float) collect($output)->sum(fn (string $code): float => (float) (($period[$code]['credit'] ?? 0) - ($period[$code]['debit'] ?? 0))) + $paid, 2);
        $ledgerInput = round((float) collect($input)->sum(fn (string $code): float => (float) (($period[$code]['debit'] ?? 0) - ($period[$code]['credit'] ?? 0))), 2);

        $net = round($docs['output_tax'] - $docs['input_tax'], 2);
        $lines = [
            ['line' => 'Output tax on sales (documents, less returns)', 'section' => 'documents', 'amount' => $docs['output_tax']],
            ['line' => 'Input tax on purchases (documents, less returns)', 'section' => 'documents', 'amount' => $docs['input_tax']],
            ['line' => 'Net tax payable for the period (documents)', 'section' => 'documents', 'amount' => $net],
            ['line' => 'Tax paid to the tax office in the period', 'section' => 'paid', 'amount' => $paid],
            ['line' => 'Balance still to pay for the period', 'section' => 'paid', 'amount' => round($net - $paid, 2)],
            ['line' => 'Output tax account: credits less debits excluding payments (ledger)', 'section' => 'ledger', 'amount' => $ledgerOutput],
            ['line' => 'Input tax account: debits less credits (ledger)', 'section' => 'ledger', 'amount' => $ledgerInput],
            ['line' => 'Output tax: documents less ledger', 'section' => 'difference', 'amount' => round($docs['output_tax'] - $ledgerOutput, 2)],
            ['line' => 'Input tax: documents less ledger', 'section' => 'difference', 'amount' => round($docs['input_tax'] - $ledgerInput, 2)],
        ];

        $rows = collect($lines)->map(fn (array $line, int $index): array => ['id' => $index + 1] + $line);

        return [
            'rows' => $rows,
            'summary' => [
                'output_tax' => $docs['output_tax'], 'input_tax' => $docs['input_tax'], 'net_tax' => $net, 'paid' => $paid, 'balance' => round($net - $paid, 2),
                'output_difference' => round($docs['output_tax'] - $ledgerOutput, 2), 'input_difference' => round($docs['input_tax'] - $ledgerInput, 2),
                'output_account_mapped' => count($output) > 0 ? 1 : 0, 'input_account_mapped' => count($input) > 0 ? 1 : 0,
                'from' => $from, 'to' => $to,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        return $this->build($companyId, $branchId, $filters)['rows'];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return array<string, float|int|string>
     */
    public function summary(Collection $rows, ?int $companyId = null, ?int $branchId = null, array $filters = []): array
    {
        return $this->build($companyId, $branchId, $filters)['summary'];
    }

    /**
     * The codes of the accounts the company mapped under a key (one per branch at most).
     *
     * @return list<string>
     */
    private function mappedCodes(int $companyId, string $key): array
    {
        $ids = ChartOfAccountMapping::query()->where('company_id', $companyId)->where('key', $key)->whereNotNull('value')->where('value', '!=', '')->pluck('value')->all();

        return $ids === [] ? [] : DB::table('chart_of_accounts')->whereIn('id', $ids)->pluck('code')->map(fn ($code): string => (string) $code)->unique()->values()->all();
    }
}
