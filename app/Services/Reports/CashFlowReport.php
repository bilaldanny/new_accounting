<?php

namespace App\Services\Reports;

use App\Models\ChartOfAccount;
use App\Support\AccountClass;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash Flow Statement, direct method: the cash and bank accounts' movement in the range (the financial year to
 * date when none is given), by what the other side of each voucher was.
 *
 * For every approved voucher that touches a cash or bank account, each other line is a counter account: a
 * credit there is cash coming in, a debit cash going out. A transfer between two cash or bank accounts has no
 * counter account and does not show. Counter accounts are sorted into sections by their class (AccountClass):
 *  - Financing: equity (1xx), and liabilities (3xx) named like a loan, borrowing, lease or mortgage;
 *  - Investing: assets (2xx) named like a fixed asset, equipment, vehicle, machinery, building, furniture, land or property;
 *  - Operating: everything else (sales, purchases, expenses, receivables, payables, stock).
 * The chart has no flag for this, so the section is a reading of the class and name, and a counter account
 * named unusually may sit in Operating. The indirect method is not provided.
 */
class CashFlowReport
{
    public const SORTABLE = [
        'section' => 'section_order',
        'account_code' => 'account_code',
        'account_name' => 'account_name',
        'inflow' => 'inflow',
        'outflow' => 'outflow',
        'net' => 'net',
    ];

    public const DEFAULT_SORT = 'section';

    public const DEFAULT_DESC = false;

    private const SECTIONS = ['Operating' => 1, 'Investing' => 2, 'Financing' => 3];

    public function __construct(private readonly AccountFigures $figures) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, opening: float, closing: float}
     */
    public function build(?int $companyId, ?int $branchId, array $filters): array
    {
        if ($companyId === null) {
            return ['rows' => collect(), 'opening' => 0.0, 'closing' => 0.0];
        }

        $year = $this->figures->financialYear($companyId);
        [$from, $to] = $this->figures->range($filters['start_date'] ?? null, $filters['end_date'] ?? null, $year);

        $cashCodes = ChartOfAccount::bankAndCashAccountOptions($companyId, $branchId)->pluck('code')->map(fn ($code): string => (string) $code)->unique()->values()->all();

        $counters = $cashCodes === [] ? collect() : DB::table('t_account_details as d')
            ->join('t_accounts as a', 'a.id', '=', 'd.t_account_id')
            ->where('a.company_id', $companyId)
            ->where('a.status', 'approved')
            ->when($branchId !== null, fn (Builder $q) => $q->where('a.branch_id', $branchId))
            ->whereDate('a.voucher_date', '>=', $from)
            ->whereDate('a.voucher_date', '<=', $to)
            ->whereIn('d.t_account_id', fn (Builder $q) => $q->select('x.t_account_id')->from('t_account_details as x')->whereIn('x.account_code', $cashCodes))
            ->whereNotIn('d.account_code', $cashCodes)
            ->groupBy('d.account_code')
            ->selectRaw('d.account_code as code, coalesce(sum(d.credit), 0) as inflow, coalesce(sum(d.debit), 0) as outflow')
            ->get();

        $names = $this->figures->names($companyId, $counters->pluck('code'));

        $rows = $counters->map(function (object $row) use ($names): array {
            $name = (string) ($names[(string) $row->code] ?? '');
            $section = $this->section((string) $row->code, $name);

            return [
                'id' => 0,
                'section' => $section,
                'section_order' => self::SECTIONS[$section],
                'account_code' => (string) $row->code,
                'account_name' => $name,
                'inflow' => round((float) $row->inflow, 2),
                'outflow' => round((float) $row->outflow, 2),
                'net' => round((float) $row->inflow - (float) $row->outflow, 2),
            ];
        })->sortBy(['section_order', 'account_code'])->values()->map(function (array $row, int $index): array {
            $row['id'] = $index + 1;

            return $row;
        });

        $stored = $this->figures->stored($companyId, $branchId, $year);
        $yearStart = $year?->start_date?->toDateString();
        $beforeFrom = collect($cashCodes)->sum(fn (string $code): float => (float) ($stored[$code] ?? 0))
            + collect($cashCodes)->sum(fn (string $code): float => (float) ($this->figures->net($companyId, $branchId, $yearStart, $from, true)[$code] ?? 0));
        $opening = round($yearStart !== null && $from < $yearStart ? 0.0 : $beforeFrom, 2);

        return ['rows' => $rows, 'opening' => $opening, 'closing' => round($opening + (float) $rows->sum('net'), 2)];
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
     * @return array<string, float|int>
     */
    public function summary(Collection $rows, ?int $companyId = null, ?int $branchId = null, array $filters = []): array
    {
        $net = fn (string $section): float => round((float) $rows->where('section', $section)->sum('net'), 2);
        $built = $companyId === null ? ['opening' => 0.0, 'closing' => 0.0] : $this->build($companyId, $branchId, $filters);

        return [
            'operating' => $net('Operating'),
            'investing' => $net('Investing'),
            'financing' => $net('Financing'),
            'net_change' => round((float) $rows->sum('net'), 2),
            'opening_cash' => $built['opening'],
            'closing_cash' => $built['closing'],
        ];
    }

    private function section(string $code, string $name): string
    {
        $digit = AccountClass::of($code)['digit'] ?? 0;

        return match (true) {
            $digit === 1 => 'Financing',
            $digit === 3 && preg_match('/loan|borrow|lease|mortgage/i', $name) === 1 => 'Financing',
            $digit === 2 && preg_match('/fixed asset|equipment|vehicle|machin|building|furniture|land|property/i', $name) === 1 => 'Investing',
            default => 'Operating',
        };
    }
}
