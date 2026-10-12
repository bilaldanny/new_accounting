<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the company owes the statutory bodies and the tax office from approved or posted payrolls in a range of months: one row per
 * contribution code and side (employee share withheld from pay, employer share on top) and one row for income tax withheld. The
 * amounts are read from the stored payslip detail, i.e. the rates the accountant entered on the day of each payroll. Range:
 * `start_date` to `end_date` by payroll month (the last 12 months by default).
 */
class StatutoryLiabilityReport
{
    public const SORTABLE = ['code' => 'code', 'name' => 'name', 'side' => 'side', 'amount' => 'amount', 'employees' => 'employees'];

    public const DEFAULT_SORT = 'code';

    public const DEFAULT_DESC = false;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 365);
        $groups = [];

        $lines = DB::table('payroll_lines as l')->join('payroll_runs as r', 'r.id', '=', 'l.payroll_run_id')->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->whereIn('r.status', ['approved', 'posted'])->where('r.period', '>=', substr($from, 0, 7))->where('r.period', '<=', substr($to, 0, 7))
            ->when($companyId !== null, fn ($q) => $q->where('l.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('e.branch_id', $branchId))
            ->get(['l.detail', 'l.tax', 'l.employee_id']);

        $add = function (string $code, string $name, string $side, float $amount, int $employee) use (&$groups): void {
            $key = $code.'|'.$side;
            $groups[$key] ??= ['code' => $code, 'name' => $name, 'side' => $side, 'amount' => 0.0, 'people' => []];
            $groups[$key]['amount'] += $amount;
            $groups[$key]['people'][$employee] = true;
        };

        foreach ($lines as $line) {
            $detail = (array) json_decode((string) $line->detail, true);

            foreach ((array) ($detail['deductions'] ?? []) as $item) {
                if (($item['kind'] ?? '') === 'statutory') {
                    $add((string) ($item['code'] ?? 'STAT'), (string) ($item['name'] ?? ''), 'employee', (float) $item['amount'], (int) $line->employee_id);
                }
            }

            foreach ((array) ($detail['employer'] ?? []) as $item) {
                $add((string) ($item['code'] ?? 'STAT'), (string) ($item['name'] ?? ''), 'employer', (float) $item['amount'], (int) $line->employee_id);
            }

            if ((float) $line->tax > 0) {
                $add('INCOME_TAX', 'Income tax withheld', 'employee', (float) $line->tax, (int) $line->employee_id);
            }
        }

        return collect($groups)->map(fn (array $g, string $key): array => [
            'id' => $key, 'code' => $g['code'], 'name' => $g['name'], 'side' => $g['side'], 'amount' => round($g['amount'], 2), 'employees' => count($g['people']),
        ])->sortBy('code')->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'rows' => $rows->count(), 'employee_share' => round((float) $rows->where('side', 'employee')->where('code', '!=', 'INCOME_TAX')->sum('amount'), 2),
            'employer_share' => round((float) $rows->where('side', 'employer')->sum('amount'), 2), 'income_tax' => round((float) $rows->where('code', 'INCOME_TAX')->sum('amount'), 2),
            'total' => round((float) $rows->sum('amount'), 2),
        ];
    }
}
