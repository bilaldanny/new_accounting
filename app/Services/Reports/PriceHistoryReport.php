<?php

namespace App\Services\Reports;

use App\Models\AuditLog;
use App\Models\ProductDetail;
use Illuminate\Support\Collection;

/**
 * Product Cost & Price Change History: every change of a variation's purchase price, sell price, profit
 * percent and minimum / maximum price in the range (default the last 90 days), from the Activity Log, with the
 * old and new value and the percentage change. A variation created in the range shows its starting prices. The
 * search box matches the product name.
 */
class PriceHistoryReport
{
    public const FIELDS = [
        'default_purchase_price' => 'Purchase price',
        'dpp_unit_price' => 'Purchase unit price',
        'default_sell_price' => 'Sell price',
        'profit_percent' => 'Profit %',
        'min_sell_price' => 'Minimum price',
        'max_sell_price' => 'Maximum price',
    ];

    public const SORTABLE = [
        'created_at' => 'created_at',
        'product_name' => 'product_name',
        'field_label' => 'field_label',
        'old_value' => 'old_value',
        'new_value' => 'new_value',
        'change_percent' => 'change_percent',
        'user_name' => 'user_name',
    ];

    public const DEFAULT_SORT = 'created_at';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 90);
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        $logs = AuditLog::query()
            ->where('auditable_type', ProductDetail::class)
            ->whereIn('event', ['created', 'updated'])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->with('user:id,first_name,last_name')
            ->orderByDesc('id')
            ->limit(5000)
            ->get();

        $names = ProductDetail::query()->whereIn('id', $logs->pluck('auditable_id')->unique())->with('product:id,name')->get()
            ->mapWithKeys(fn (ProductDetail $detail): array => [$detail->id => trim((string) $detail->product?->name.($detail->variation_name && $detail->variation_name !== 'dummy' ? ' - '.$detail->variation_name : ''))]);

        $id = 0;

        return $logs->flatMap(function (AuditLog $log) use ($names, &$id): array {
            $rows = [];

            foreach (self::FIELDS as $field => $label) {
                $old = $log->old_values[$field] ?? null;
                $new = $log->new_values[$field] ?? null;

                if ($new === null && $old === null) {
                    continue;
                }

                $oldNumber = $old === null ? null : round((float) $old, 2);
                $newNumber = $new === null ? null : round((float) $new, 2);

                $rows[] = [
                    'id' => ++$id,
                    'created_at' => $log->created_at?->format('Y-m-d H:i'),
                    'product_name' => (string) ($names[$log->auditable_id] ?? 'Variation #'.$log->auditable_id),
                    'field_label' => $label,
                    'old_value' => $oldNumber,
                    'new_value' => $newNumber,
                    'change_percent' => $oldNumber !== null && $oldNumber > 0 && $newNumber !== null ? round(($newNumber - $oldNumber) / $oldNumber * 100, 2) : null,
                    'event' => $log->event,
                    'user_name' => $log->user?->full_name ?: 'System',
                ];
            }

            return $rows;
        })
            ->when($search !== '', fn (Collection $rows) => $rows->filter(fn (array $row): bool => str_contains(strtolower($row['product_name']), $search)))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'changes' => $rows->where('event', 'updated')->count(),
            'products' => $rows->pluck('product_name')->unique()->count(),
            'increases' => $rows->filter(fn (array $row): bool => ($row['change_percent'] ?? 0) > 0)->count(),
            'decreases' => $rows->filter(fn (array $row): bool => ($row['change_percent'] ?? 0) < 0)->count(),
        ];
    }
}
