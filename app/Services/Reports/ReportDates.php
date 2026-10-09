<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;

/**
 * The range the analytics reports cover: the days given (inclusive), else the last `$defaultDays` days up to today.
 */
final class ReportDates
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: string} from and to, `Y-m-d`
     */
    public static function range(array $filters, int $defaultDays = 30): array
    {
        $to = trim((string) ($filters['end_date'] ?? ''));
        $from = trim((string) ($filters['start_date'] ?? ''));

        $to = $to !== '' ? $to : Carbon::today()->toDateString();
        $from = $from !== '' ? $from : Carbon::parse($to)->subDays($defaultDays)->toDateString();

        return [$from, $to];
    }
}
