<?php

namespace App\Services\Reports;

use App\Services\SalesKpiStatement;
use Illuminate\Support\Collection;

/**
 * The commission report and the target report of a month, both over SalesKpiStatement so they always agree with the KPI page.
 *
 *  - commission: net sales, the rate applied (base or tier) and the commission, for commission agents;
 *  - target: the month's target against net sales for agents, salespeople or cashiers, with the shortfall.
 *
 * The month is `period` (Y-m, default this month); the subject is `subject` (agent, user, cashier). Nothing is posted.
 */
class CommissionTargetReport
{
    public const SORTABLE = [
        'name' => 'name',
        'sales' => 'sales',
        'gross' => 'gross',
        'returned' => 'returned',
        'net' => 'net',
        'target' => 'target',
        'achievement_percent' => 'achievement_percent',
        'shortfall' => 'shortfall',
        'rate_percent' => 'rate_percent',
        'commission' => 'commission',
    ];

    public const DEFAULT_SORT = 'net';

    public function __construct(private readonly SalesKpiStatement $statement, private readonly string $mode = 'commission') {}

    public function forMode(string $mode): self
    {
        return new self($this->statement, $mode);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        if ($companyId === null) {
            return collect();
        }

        $built = $this->statement->build($companyId, $this->period($filters), $this->subject($filters), $branchId);
        $rows = $built['rows']->map(function (array $row): array {
            $shortfall = $row['target'] !== null ? round(max($row['target'] - $row['net'], 0), 2) : null;

            return $row + ['shortfall' => $shortfall, 'id' => $row['subject_id']];
        });

        if ($this->mode === 'target') {
            return $rows->filter(fn (array $row): bool => $row['target'] !== null)->values();
        }

        return $rows->filter(fn (array $row): bool => $row['rate_percent'] !== null)->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return array<string, float|int|string>
     */
    public function summary(Collection $rows, ?int $companyId = null, ?int $branchId = null, array $filters = []): array
    {
        $target = round((float) $rows->sum('target'), 2);
        $net = round((float) $rows->sum('net'), 2);

        return [
            'count' => $rows->count(),
            'period' => $this->period($filters),
            'net' => $net,
            'target' => $target,
            'achievement_percent' => $target > 0 ? round($net / $target * 100, 1) : 0.0,
            'targets_met' => $rows->filter(fn (array $row): bool => $row['target'] !== null && $row['net'] >= $row['target'])->count(),
            'commission' => round((float) $rows->sum('commission'), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function period(array $filters): string
    {
        $period = trim((string) ($filters['period'] ?? ''));

        return preg_match('/^\d{4}-\d{2}$/', $period) === 1 ? $period : now()->format('Y-m');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function subject(array $filters): string
    {
        $subject = (string) ($filters['subject'] ?? '');

        return in_array($subject, SalesKpiStatement::SUBJECTS, true) ? $subject : ($this->mode === 'commission' ? 'agent' : 'user');
    }
}
