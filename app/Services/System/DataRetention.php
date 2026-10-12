<?php

namespace App\Services\System;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Retention of the logs and housekeeping tables that only grow: audit log, API log, sign-in events, notifications and reminder
 * log. It never touches a business document, a voucher or a stock movement - the entities below are the whole list and a rule
 * can only name one of them. A company rule applies to that company's rows; a platform rule (no company) applies to the rows of
 * every company that has no rule of its own. With `archive` on, the rows are written to a gzipped CSV before they are deleted.
 * The minimum is 30 days, so a rule cannot wipe the recent past.
 */
class DataRetention
{
    public const MIN_DAYS = 30;

    /**
     * Entity => [table, date column, label].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const ENTITIES = [
        'audit_logs' => ['audit_logs', 'created_at', 'Audit log'],
        'api_logs' => ['api_logs', 'created_at', 'API request log'],
        'login_events' => ['login_events', 'created_at', 'Sign-in events'],
        'app_notifications' => ['app_notifications', 'created_at', 'In-app notifications'],
        'document_reminder_log' => ['document_reminder_log', 'created_at', 'Invoice reminder log'],
    ];

    public static function available(): bool
    {
        return Schema::hasTable('data_retention_rules');
    }

    /**
     * Applies every active rule (of one company, or all) and returns what was removed per entity.
     *
     * @return array<string, int>
     */
    public function run(?int $companyId = null, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy();
        $removed = [];

        if (! self::available()) {
            return $removed;
        }

        $rules = DB::table('data_retention_rules')->where('is_active', true)->orderBy('id')->get();
        $companies = DB::table('companies')->when($companyId !== null, fn ($q) => $q->where('id', $companyId))->pluck('id');

        foreach ($rules->groupBy('entity') as $entity => $entityRules) {
            if (! isset(self::ENTITIES[$entity]) || ! Schema::hasTable(self::ENTITIES[$entity][0])) {
                continue;
            }

            [$table, $column] = self::ENTITIES[$entity];
            $own = $entityRules->whereNotNull('company_id')->keyBy('company_id');
            $platform = $entityRules->whereNull('company_id')->first();

            foreach ($companies as $company) {
                $rule = $own[$company] ?? $platform;

                if ($rule === null) {
                    continue;
                }

                $cutoff = $today->copy()->subDays(max(self::MIN_DAYS, (int) $rule->keep_days))->startOfDay();
                $query = DB::table($table)->where('company_id', $company)->where($column, '<', $cutoff);
                $count = (clone $query)->count();

                if ($count === 0) {
                    continue;
                }

                if ($rule->archive && ! $this->archive($entity, (int) $company, $query, $today)) {
                    continue;
                }

                $query->delete();
                $removed[$entity] = ($removed[$entity] ?? 0) + $count;
                DB::table('data_retention_rules')->where('id', $rule->id)->update(['last_run_at' => now(), 'last_removed' => $count, 'updated_at' => now()]);
            }

            foreach ($entityRules as $rule) {
                DB::table('data_retention_rules')->where('id', $rule->id)->update(['last_run_at' => now(), 'updated_at' => now()]);
            }
        }

        return $removed;
    }

    /**
     * Writes the rows to storage/app/archives/<entity>-<company>-<date>.csv.gz; false (and nothing is deleted) when that fails.
     */
    private function archive(string $entity, int $companyId, $query, Carbon $today): bool
    {
        try {
            $stream = fopen('php://temp', 'r+');
            $first = true;

            (clone $query)->orderBy('id')->chunk(500, function ($rows) use ($stream, &$first): void {
                foreach ($rows as $row) {
                    $data = (array) $row;

                    if ($first) {
                        fputcsv($stream, array_keys($data));
                        $first = false;
                    }

                    fputcsv($stream, array_map(fn ($value): string => is_scalar($value) || $value === null ? (string) $value : '', $data));
                }
            });

            rewind($stream);
            Storage::disk('local')->put("archives/{$entity}-{$companyId}-{$today->format('Ymd')}-".now()->format('His').'.csv.gz', (string) gzencode((string) stream_get_contents($stream), 6));
            fclose($stream);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
