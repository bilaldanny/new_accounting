<?php

namespace App\Services\Reports;

use App\Models\AuditLog;
use Illuminate\Support\Collection;

/**
 * Activity Log & User Audit Trail report: how many records each user created, updated and deleted, by kind of
 * record, in the range (default the last 30 days). It counts the entries of the Activity Log (AuditLog); the
 * entries themselves, with their before and after, are the Data Change History report.
 */
class ActivitySummaryReport
{
    public const SORTABLE = [
        'user_name' => 'user_name',
        'model' => 'model',
        'created' => 'created',
        'updated' => 'updated',
        'deleted' => 'deleted',
        'total' => 'total',
        'last_activity' => 'last_activity',
    ];

    public const DEFAULT_SORT = 'total';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters);
        $search = trim((string) ($filters['search'] ?? ''));

        return AuditLog::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->with('user:id,first_name,last_name')
            ->get(['id', 'user_id', 'event', 'auditable_type', 'created_at'])
            ->groupBy(fn (AuditLog $log): string => ($log->user_id ?? 0).'|'.$log->auditable_type)
            ->map(function (Collection $group): array {
                /** @var AuditLog $first */
                $first = $group->first();
                $count = fn (array $events): int => $group->whereIn('event', $events)->count();

                return [
                    'id' => 0,
                    'user_name' => $first->user?->full_name ?: 'System',
                    'model' => class_basename($first->auditable_type),
                    'created' => $count(['created']),
                    'updated' => $count(['updated']),
                    'deleted' => $count(['deleted', 'force_deleted']),
                    'total' => $group->count(),
                    'last_activity' => $group->max('created_at')?->format('Y-m-d H:i'),
                ];
            })
            ->when($search !== '', fn (Collection $rows) => $rows->filter(fn (array $row): bool => str_contains(strtolower($row['user_name'].' '.$row['model']), strtolower($search))))
            ->values()
            ->map(function (array $row, int $index): array {
                $row['id'] = $index + 1;

                return $row;
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'users' => $rows->pluck('user_name')->unique()->count(),
            'created' => (int) $rows->sum('created'),
            'updated' => (int) $rows->sum('updated'),
            'deleted' => (int) $rows->sum('deleted'),
            'total' => (int) $rows->sum('total'),
        ];
    }
}
