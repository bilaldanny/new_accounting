<?php

namespace App\Services\Hrm;

/**
 * Minutes late, early and overtime of one day from the shift and the check-in / check-out (HH:MM). A shift that ends before it starts
 * runs over midnight. Late is counted after the shift's grace minutes; overtime only when at least the configured minimum was
 * worked after the shift end. Pure: no database.
 */
final class AttendanceCalculator
{
    /**
     * @param  array{start_time: string, end_time: string, grace_minutes: int|string}|null  $shift
     * @param  array<string, mixed>  $settings
     * @return array{worked: int, late: int, early: int, overtime: int}
     */
    public static function compute(?array $shift, ?string $checkIn, ?string $checkOut, array $settings): array
    {
        $in = self::minutes($checkIn);
        $out = self::minutes($checkOut);
        $result = ['worked' => 0, 'late' => 0, 'early' => 0, 'overtime' => 0];

        if ($in === null) {
            return $result;
        }

        if ($out !== null && $out < $in) {
            $out += 1440;
        }

        if ($out !== null) {
            $result['worked'] = max(0, $out - $in);
        }

        if ($shift === null) {
            return $result;
        }

        $start = self::minutes($shift['start_time']) ?? 0;
        $end = self::minutes($shift['end_time']) ?? 0;

        if ($end <= $start) {
            $end += 1440;
        }

        $grace = (int) $shift['grace_minutes'];
        $result['late'] = max(0, $in - ($start + $grace));

        if ($out !== null) {
            $result['early'] = max(0, $end - $out);
            $over = max(0, $out - $end);
            $result['overtime'] = $over >= (int) ($settings['overtime_min_minutes'] ?? 30) ? $over : 0;
        }

        return $result;
    }

    public static function minutes(?string $time): ?int
    {
        if ($time === null || preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
