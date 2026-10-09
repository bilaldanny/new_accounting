<?php

namespace App\Services\Reports;

use App\Models\AuditLog;
use Illuminate\Support\Collection;

/**
 * Data Change History (Old vs New Value): the Activity Log flattened to one row per changed field, in the range
 * (default the last 30 days). The search box takes a kind of record (Contact, Transaction, PriceList...), a
 * record number (digits) or a field name. Capped at 5000 entries of the log.
 */
class ChangeHistoryReport
{
    public const SORTABLE = [
        'created_at' => 'created_at',
        'user_name' => 'user_name',
        'model' => 'model',
        'record_id' => 'record_id',
        'event' => 'event',
        'field' => 'field',
    ];

    public const DEFAULT_SORT = 'created_at';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters);
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        $id = 0;

        return AuditLog::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->when(ctype_digit($search) && $search !== '', fn ($q) => $q->where('auditable_id', (int) $search))
            ->with('user:id,first_name,last_name')
            ->orderByDesc('id')
            ->limit(5000)
            ->get()
            ->flatMap(function (AuditLog $log) use (&$id): array {
                $old = $log->old_values ?? [];
                $new = $log->new_values ?? [];
                $fields = array_values(array_unique([...array_keys($old), ...array_keys($new)])) ?: [''];

                return array_map(function (string $field) use ($log, $old, $new, &$id): array {
                    return [
                        'id' => ++$id,
                        'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                        'user_name' => $log->user?->full_name ?: 'System',
                        'model' => class_basename($log->auditable_type),
                        'record_id' => (int) $log->auditable_id,
                        'event' => $log->event,
                        'field' => $field,
                        'old_value' => $this->show($old[$field] ?? null),
                        'new_value' => $this->show($new[$field] ?? null),
                    ];
                }, $fields);
            })
            ->when($search !== '' && ! ctype_digit($search), fn (Collection $rows) => $rows->filter(fn (array $row): bool => str_contains(strtolower($row['model'].' '.$row['field']), $search)))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'changes' => $rows->count(),
            'records' => $rows->map(fn (array $row): string => $row['model'].'#'.$row['record_id'])->unique()->count(),
            'users' => $rows->pluck('user_name')->unique()->count(),
        ];
    }

    private function show(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }
}
