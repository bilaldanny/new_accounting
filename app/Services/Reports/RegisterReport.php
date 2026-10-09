<?php

namespace App\Services\Reports;

use App\Models\PosShift;
use App\Services\PosShiftReport;
use Illuminate\Support\Collection;

/**
 * Register Report (the Z report across shifts, and the Cashier report): every POS shift opened in the range
 * (default the last 30 days) with its cashier, sales, cash expected, cash counted and variance. A closed shift
 * shows its frozen Z report; an open one is worked out live (the X report).
 */
class RegisterReport
{
    public const SORTABLE = [
        'opened_at' => 'opened_at',
        'cashier' => 'cashier',
        'branch_name' => 'branch_name',
        'sales_count' => 'sales_count',
        'sales_total' => 'sales_total',
        'cash_payments' => 'cash_payments',
        'expected_cash' => 'expected_cash',
        'counted_cash' => 'counted_cash',
        'variance' => 'variance',
        'status' => 'status',
    ];

    public const DEFAULT_SORT = 'opened_at';

    public function __construct(private readonly PosShiftReport $report) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters);
        $search = trim((string) ($filters['search'] ?? ''));

        return PosShift::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when(in_array($filters['status'] ?? null, ['open', 'closed'], true), fn ($q) => $q->where('status', $filters['status']))
            ->whereDate('opened_at', '>=', $from)
            ->whereDate('opened_at', '<=', $to)
            ->with(['user:id,first_name,last_name', 'branch:id,name'])
            ->orderBy('opened_at')
            ->get()
            ->map(function (PosShift $shift): array {
                $report = $shift->isOpen() ? $this->report->build($shift, now()) : ($shift->summary ?? []);

                return [
                    'id' => $shift->id,
                    'opened_at' => $shift->opened_at?->format('Y-m-d H:i'),
                    'closed_at' => $shift->closed_at?->format('Y-m-d H:i'),
                    'cashier' => $shift->user?->full_name,
                    'branch_name' => $shift->branch?->name,
                    'status' => $shift->status,
                    'opening_float' => round((float) $shift->opening_float, 2),
                    'sales_count' => (int) ($report['sales_count'] ?? 0),
                    'sales_total' => round((float) ($report['sales_total'] ?? 0), 2),
                    'cash_payments' => round((float) ($report['cash_payments'] ?? 0), 2),
                    'expected_cash' => round((float) ($report['expected_cash'] ?? 0), 2),
                    'counted_cash' => $shift->counted_cash,
                    'variance' => $shift->variance,
                ];
            })
            ->when($search !== '', fn (Collection $rows) => $rows->filter(fn (array $row): bool => str_contains(strtolower((string) $row['cashier'].' '.(string) $row['branch_name']), strtolower($search))))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        $closed = $rows->where('status', 'closed');

        return [
            'shifts' => $rows->count(),
            'open' => $rows->where('status', 'open')->count(),
            'sales_total' => round((float) $rows->sum('sales_total'), 2),
            'cash_payments' => round((float) $rows->sum('cash_payments'), 2),
            'variance' => round((float) $closed->sum('variance'), 2),
            'short_shifts' => $closed->filter(fn (array $row): bool => (float) $row['variance'] < 0)->count(),
        ];
    }
}
