<?php

namespace App\Services;

use App\Support\AccountClass;
use Illuminate\Support\Collection;

/**
 * The trial balance: every posting account with what it opened with, what was posted in the range and
 * where it stands, each as a debit or a credit, so the debit and credit columns can be checked against
 * each other.
 *
 * Read from approved vouchers only (the ledger's own figures, AccountFigures), one row per account code
 * across the branches (or one branch when asked). The opening balance is the stored opening balance of
 * the active financial year plus the postings from its start up to the day before the range, as in the
 * party ledger; the range is inclusive at both ends and defaults to the financial year up to today. The
 * class of an account is its first digit (AccountClass), not the stored flags. An account with no
 * opening balance and no posting is not listed. The report always states its own check: total debits
 * less total credits (0.00 when the books balance), for the opening, the period and the closing.
 */
class TrialBalanceReport
{
    public const SORTABLE = [
        'code' => 'code',
        'name' => 'name',
        'class' => 'class',
        'opening_debit' => 'opening_debit',
        'opening_credit' => 'opening_credit',
        'debit' => 'debit',
        'credit' => 'credit',
        'closing_debit' => 'closing_debit',
        'closing_credit' => 'closing_credit',
    ];

    public function __construct(private readonly AccountFigures $figures) {}

    /**
     * @param  array{account_group?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int $companyId, ?int $branchId, array $filters = []): Collection
    {
        $group = (int) ($filters['account_group'] ?? 0);
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        $financialYear = $this->figures->financialYear($companyId);
        $yearStart = $financialYear?->start_date?->toDateString();
        [$from, $to] = $this->figures->range($filters['start_date'] ?? null, $filters['end_date'] ?? null, $financialYear);

        $stored = $this->figures->stored($companyId, $branchId, $financialYear);
        $prior = $yearStart === null || $from > $yearStart
            ? $this->figures->net($companyId, $branchId, $yearStart, $from, exclusiveEnd: true)
            : collect();
        $period = $this->figures->period($companyId, $branchId, $from, $to);

        $codes = $stored->keys()->merge($prior->keys())->merge($period->keys())->unique()->values();
        $names = $this->figures->names($companyId, $codes);

        return $codes
            ->map(function (string $code) use ($stored, $prior, $period, $names): array {
                $opening = round((float) ($stored[$code] ?? 0) + (float) ($prior[$code] ?? 0), 2);
                $debit = round((float) ($period[$code]['debit'] ?? 0), 2);
                $credit = round((float) ($period[$code]['credit'] ?? 0), 2);
                $closing = round($opening + $debit - $credit, 2);

                return [
                    'id' => 0,
                    'code' => $code,
                    'name' => (string) ($names[$code] ?? ''),
                    'class' => AccountClass::label($code),
                    'class_digit' => AccountClass::of($code)['digit'] ?? 0,
                    'opening_debit' => $opening > 0 ? $opening : 0.0,
                    'opening_credit' => $opening < 0 ? -$opening : 0.0,
                    'debit' => $debit,
                    'credit' => $credit,
                    'closing_debit' => $closing > 0 ? $closing : 0.0,
                    'closing_credit' => $closing < 0 ? -$closing : 0.0,
                ];
            })
            ->reject(fn (array $row): bool => $row['opening_debit'] == 0 && $row['opening_credit'] == 0 && $row['debit'] == 0 && $row['credit'] == 0)
            ->when($group >= 1 && $group <= 6, fn (Collection $rows) => $rows->where('class_digit', $group))
            ->when($search !== '', fn (Collection $rows) => $rows->filter(fn (array $row): bool => str_contains(strtolower($row['code'].' '.$row['name']), $search)))
            ->sortBy('code')
            ->values()
            ->map(fn (array $row, int $index): array => ['id' => $index + 1] + $row);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int|bool>
     */
    public function summary(Collection $rows): array
    {
        $sum = fn (string $key): float => round((float) $rows->sum($key), 2);

        $openingDifference = round($sum('opening_debit') - $sum('opening_credit'), 2);
        $periodDifference = round($sum('debit') - $sum('credit'), 2);
        $closingDifference = round($sum('closing_debit') - $sum('closing_credit'), 2);

        return [
            'count' => $rows->count(),
            'opening_debit' => $sum('opening_debit'),
            'opening_credit' => $sum('opening_credit'),
            'debit' => $sum('debit'),
            'credit' => $sum('credit'),
            'closing_debit' => $sum('closing_debit'),
            'closing_credit' => $sum('closing_credit'),
            'opening_difference' => $openingDifference,
            'period_difference' => $periodDifference,
            'closing_difference' => $closingDifference,
            'is_balanced' => $openingDifference == 0.0 && $periodDifference == 0.0 && $closingDifference == 0.0,
        ];
    }
}
