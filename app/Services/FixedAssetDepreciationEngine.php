<?php

namespace App\Services;

use App\Models\AssetCategory;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The depreciation engine (Accounts & Finance Phase 3). Depreciation is worked out per asset, per calendar month,
 * from the month the asset went into service (or the month after its stated `depreciated_through`) up to the period
 * asked for, and each asset-month is recorded once (unique on asset + period), so running it again never doubles up.
 *
 *  - Straight-line: (cost - salvage) / useful life in months, every month.
 *  - Declining balance: book value at the start of the month x (factor / useful life in years) / 12.
 *  - WDV: book value at the start of the month x yearly rate % / 12.
 * Every month is capped so the book value never falls below the salvage value. A month's depreciation is posted as
 * one journal voucher per branch and category and month (debit the category's depreciation expense, credit its
 * accumulated depreciation), dated the last day of the month, through FixedAssetJournal, so it follows the company's
 * journal approval setting. Nothing else in the ledger is touched.
 */
class FixedAssetDepreciationEngine
{
    public function __construct(private readonly FixedAssetJournal $journal) {}

    /**
     * What a run up to `$period` (Y-m) would book, without booking it.
     *
     * @param  array{branch_id?: int|null, category_id?: int|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(int $companyId, string $period, array $filters = []): Collection
    {
        $rows = collect();

        foreach ($this->eligibleAssets($companyId, $filters) as $asset) {
            foreach ($this->schedule($asset, $period) as $row) {
                $rows->push($row);
            }
        }

        return $rows->sortBy([['period', 'asc'], ['code', 'asc']])->values();
    }

    /**
     * Books everything due up to `$period` (Y-m).
     *
     * @param  array{branch_id?: int|null, category_id?: int|null}  $filters
     * @return array{count: int, total: float, months: list<string>, vouchers: int}
     */
    public function run(int $companyId, string $period, array $filters = []): array
    {
        $rows = $this->preview($companyId, $period, $filters);
        $vouchers = 0;

        $groups = $rows->groupBy(fn (array $row): string => $row['period'].'|'.$row['branch_id'].'|'.$row['category_id'].'|'.($row['cost_center_id'] ?? 0));

        foreach ($groups as $group) {
            DB::transaction(function () use ($group, $companyId, &$vouchers): void {
                $first = $group->first();
                $category = AssetCategory::query()->findOrFail($first['category_id']);
                $amount = round((float) $group->sum('amount'), 2);
                $periodEnd = (string) $first['period_end'];

                $voucher = $this->journal->post(
                    $companyId,
                    (int) $first['branch_id'],
                    $periodEnd,
                    [
                        ['account_id' => (int) $category->expense_coa_id, 'debit' => $amount, 'credit' => 0.0, 'cost_center_id' => $first['cost_center_id'] ?? null],
                        ['account_id' => (int) $category->accumulated_coa_id, 'debit' => 0.0, 'credit' => $amount],
                    ],
                    'Depreciation '.$first['period'].': '.$category->name,
                    'DEP-'.$first['period'],
                );
                $vouchers++;

                foreach ($group as $row) {
                    FixedAssetDepreciation::query()->create([
                        'company_id' => $companyId,
                        'branch_id' => $row['branch_id'],
                        'fixed_asset_id' => $row['asset_id'],
                        'period' => $row['period'],
                        'period_end' => $periodEnd,
                        'amount' => $row['amount'],
                        'opening_book_value' => $row['opening_book_value'],
                        'closing_book_value' => $row['closing_book_value'],
                        't_account_id' => $voucher->id,
                        'created_by' => Auth::id(),
                    ]);

                    $asset = FixedAsset::query()->lockForUpdate()->findOrFail($row['asset_id']);
                    $asset->update([
                        'accumulated_depreciation' => round((float) $asset->accumulated_depreciation + (float) $row['amount'], 2),
                        'depreciated_through' => $periodEnd,
                    ]);
                }
            });
        }

        return [
            'count' => $rows->count(),
            'total' => round((float) $rows->sum('amount'), 2),
            'months' => $rows->pluck('period')->unique()->values()->all(),
            'vouchers' => $vouchers,
        ];
    }

    /**
     * The months of depreciation an asset still owes up to `$period`, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function schedule(FixedAsset $asset, string $period): array
    {
        $last = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->endOfMonth();
        $month = $this->firstMonth($asset);
        $book = round((float) $asset->cost - (float) $asset->accumulated_depreciation, 2);
        $salvage = round((float) $asset->salvage_value, 2);
        $rows = [];

        while ($month <= $last && $book > $salvage) {
            $amount = min($this->monthlyAmount($asset, $book), round($book - $salvage, 2));

            if ($amount <= 0.0) {
                break;
            }

            $closing = round($book - $amount, 2);
            $rows[] = [
                'asset_id' => $asset->id,
                'code' => $asset->code,
                'name' => $asset->name,
                'branch_id' => (int) $asset->branch_id,
                'category_id' => (int) $asset->asset_category_id,
                'cost_center_id' => $asset->cost_center_id,
                'period' => $month->format('Y-m'),
                'period_end' => $month->endOfMonth()->toDateString(),
                'amount' => round($amount, 2),
                'opening_book_value' => $book,
                'closing_book_value' => $closing,
            ];

            $book = $closing;
            $month = $month->addMonthNoOverflow()->startOfMonth();
        }

        return $rows;
    }

    /**
     * The first month still to depreciate: the month after `depreciated_through`, else the in-service month.
     */
    private function firstMonth(FixedAsset $asset): CarbonImmutable
    {
        if ($asset->depreciated_through !== null) {
            return CarbonImmutable::parse($asset->depreciated_through->toDateString())->addMonthNoOverflow()->startOfMonth();
        }

        return CarbonImmutable::parse(($asset->in_service_on ?? $asset->acquired_on)->toDateString())->startOfMonth();
    }

    private function monthlyAmount(FixedAsset $asset, float $book): float
    {
        return match ($asset->method) {
            'straight_line' => round(((float) $asset->cost - (float) $asset->salvage_value) / max(1, (int) $asset->useful_life_months), 2),
            'declining_balance' => round($book * min(1.0, (float) $asset->rate / (max(1, (int) $asset->useful_life_months) / 12)) / 12, 2),
            'wdv' => round($book * ((float) $asset->rate / 100) / 12, 2),
            default => 0.0,
        };
    }

    /**
     * @param  array{branch_id?: int|null, category_id?: int|null}  $filters
     * @return Collection<int, FixedAsset>
     */
    private function eligibleAssets(int $companyId, array $filters): Collection
    {
        return FixedAsset::query()
            ->where('company_id', $companyId)
            ->where('status', FixedAsset::STATUS_ACTIVE)
            ->whereNotNull('in_service_on')
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(! empty($filters['category_id']), fn ($q) => $q->where('asset_category_id', $filters['category_id']))
            ->orderBy('id')
            ->get();
    }
}
