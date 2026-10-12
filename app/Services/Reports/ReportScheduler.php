<?php

namespace App\Services\Reports;

use App\Http\Controllers\Reports\AnalyticsReportController;
use App\Mail\ScheduledReportMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Scheduled and emailed reports. A schedule is a saved report (a standard report with its filters, or a custom report of the builder)
 * with a frequency and recipients. When it is due (the daily command `reports:send-scheduled`) the report is produced as the person
 * who created the schedule: their company and branch scope, and only while they are still an active user who may open that report
 * (their menu permission is checked on every run), then mailed to each recipient as a CSV attachment. A run is recorded with its
 * status and row count. Nothing here posts anything.
 */
class ReportScheduler
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const MAX_RECIPIENTS = 10;

    public function __construct(private readonly InsightReportCatalog $catalog, private readonly ReportBuilder $builder) {}

    /**
     * The standard reports a schedule can send: key => [title, menu permission path].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function available(): array
    {
        $reports = [];

        foreach (InsightReportCatalog::REPORTS as $key => [$title, $path]) {
            $reports[$key] = [$title, $path];
        }

        foreach (AnalyticsReportController::PERMISSIONS as $key => $path) {
            $reports[$key] = [ucwords(str_replace('-', ' ', $key)), $path];
        }

        return $reports;
    }

    public function isDue(object $schedule, Carbon $now): bool
    {
        if (! $schedule->is_active) {
            return false;
        }

        $last = $schedule->last_run_at !== null ? Carbon::parse($schedule->last_run_at) : null;

        if ($last !== null && $last->isSameDay($now)) {
            return false;
        }

        return match ($schedule->frequency) {
            'weekly' => $now->dayOfWeek === (int) $schedule->weekday,
            'monthly' => $now->day === min((int) $schedule->day_of_month, $now->daysInMonth),
            default => true,
        };
    }

    /**
     * Runs every due schedule.
     *
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function runDue(?Carbon $now = null): array
    {
        $now ??= now();
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        DB::table('report_schedules')->where('is_active', true)->orderBy('id')->get()->each(function (object $schedule) use ($now, &$result): void {
            if (! $this->isDue($schedule, $now)) {
                return;
            }

            $outcome = $this->run($schedule, $now);
            $result[$outcome['status'] === 'sent' ? 'sent' : ($outcome['status'] === 'failed' ? 'failed' : 'skipped')]++;
        });

        return $result;
    }

    /**
     * Produces one schedule and mails it. Always records a run; never throws.
     *
     * @return array{status: string, rows: int, message: string}
     */
    public function run(object $schedule, ?Carbon $at = null): array
    {
        $previous = Auth::user();
        $outcome = ['status' => 'failed', 'rows' => 0, 'message' => ''];

        try {
            $creator = User::query()->where('id', $schedule->user_id)->where('company_id', $schedule->company_id)->where('is_active', true)->first();

            if ($creator === null) {
                $outcome = ['status' => 'skipped', 'rows' => 0, 'message' => 'The person who created this schedule is no longer an active user of the company.'];
            } else {
                Auth::setUser($creator);
                $built = $this->produce($schedule);

                if ($built === null) {
                    $outcome = ['status' => 'skipped', 'rows' => 0, 'message' => 'The report is not available to the person who created this schedule any more.'];
                } else {
                    $csv = $this->csv($built['headers'], $built['rows']);
                    $recipients = (array) json_decode((string) $schedule->recipients, true);
                    $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $schedule->name).'-'.now()->format('Ymd').'.csv';

                    foreach ($recipients as $recipient) {
                        Mail::to($recipient)->send(new ScheduledReportMail((string) $schedule->name, $csv, $name, count($built['rows'])));
                    }

                    $outcome = ['status' => 'sent', 'rows' => count($built['rows']), 'message' => 'Sent to '.count($recipients).' recipient(s).'];
                }
            }
        } catch (Throwable $e) {
            $outcome = ['status' => 'failed', 'rows' => 0, 'message' => mb_substr($e->getMessage(), 0, 240)];
        } finally {
            if ($previous !== null) {
                Auth::setUser($previous);
            } else {
                Auth::forgetUser();
            }
        }

        DB::table('report_schedule_runs')->insert([
            'report_schedule_id' => $schedule->id, 'ran_at' => $at ?? now(), 'status' => $outcome['status'], 'row_count' => $outcome['rows'], 'message' => mb_substr($outcome['message'], 0, 250),
        ]);
        DB::table('report_schedules')->where('id', $schedule->id)->update([
            'last_run_at' => $at ?? now(), 'last_status' => mb_substr($outcome['status'].': '.$outcome['message'], 0, 190), 'updated_at' => now(),
        ]);

        return $outcome;
    }

    /**
     * The rows of a schedule's report, as the creator (who must be the signed-in user while this runs).
     *
     * @return array{headers: array<string, string>, rows: Collection<int, array<string, mixed>>}|null null when the report is not available to them
     */
    private function produce(object $schedule): ?array
    {
        $filters = (array) json_decode((string) ($schedule->filters ?? ''), true);
        $companyId = (int) $schedule->company_id;
        $branchId = $schedule->branch_id === null ? null : (int) $schedule->branch_id;

        if ($schedule->report_type === 'custom') {
            $report = DB::table('custom_reports')->where('id', $schedule->custom_report_id)->where('company_id', $companyId)->whereNull('deleted_at')->first();

            if ($report === null || ! hasMenuPermission('/reportbuilder') || (! $report->is_shared && (int) $report->user_id !== (int) $schedule->user_id)) {
                return null;
            }

            $built = $this->builder->run((string) $report->dataset, (array) json_decode((string) $report->definition, true), $companyId, $branchId);

            return ['headers' => collect($built['columns'])->pluck('label', 'key')->all(), 'rows' => $built['rows']];
        }

        $available = $this->available();
        $key = (string) $schedule->report_key;

        if (! isset($available[$key]) || ! hasMenuPermission($available[$key][1])) {
            return null;
        }

        if (isset(InsightReportCatalog::REPORTS[$key])) {
            $rows = $this->catalog->run($key, $companyId, $branchId, $filters)['rows'];
        } else {
            $rows = app(AnalyticsReportController::SERVICES[$key])->rows($companyId, $branchId, $filters);
        }

        $first = $rows->first();
        $headers = $first === null ? [] : collect(array_keys($first))->reject(fn (string $name): bool => $name === 'id')->mapWithKeys(fn (string $name): array => [$name => ucwords(str_replace('_', ' ', $name))])->all();

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function csv(array $headers, Collection $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, array_values($headers));

        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $key): string => $this->cell($row[$key] ?? ''), array_keys($headers)));
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * A value for a CSV cell. A text that starts like a spreadsheet formula is prefixed so a spreadsheet shows it as text.
     */
    private function cell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = is_scalar($value) || $value === null ? (string) $value : json_encode($value);

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@'], true) && ! is_numeric($text) ? "'".$text : $text;
    }
}
