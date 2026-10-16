<?php

namespace App\Services\Hrm;

/**
 * The public holidays of Pakistan a company can import with one click. The national days fall on the same date every year; the
 * Islamic holidays follow the moon sighting, so they are listed only for the years below with the dates the government calendar gives
 * and marked "approximate": a company must confirm them each year (the page says so and every imported row can be edited or deleted).
 */
final class PakistanHolidays
{
    /** @var array<string, string> month-day => name */
    private const FIXED = [
        '02-05' => 'Kashmir Solidarity Day',
        '03-23' => 'Pakistan Day',
        '05-01' => 'Labour Day',
        '08-14' => 'Independence Day',
        '12-25' => 'Quaid-e-Azam Day and Christmas',
    ];

    /** @var array<int, array<string, string>> year => date => name (moon dependent, approximate) */
    private const ISLAMIC = [
        2026 => [
            '2026-03-21' => 'Eid ul Fitr (approximate)', '2026-03-22' => 'Eid ul Fitr (approximate)', '2026-03-23' => 'Eid ul Fitr (approximate)',
            '2026-05-27' => 'Eid ul Adha (approximate)', '2026-05-28' => 'Eid ul Adha (approximate)', '2026-05-29' => 'Eid ul Adha (approximate)',
            '2026-06-25' => 'Ashura, 9 Muharram (approximate)', '2026-06-26' => 'Ashura, 10 Muharram (approximate)',
            '2026-08-26' => 'Eid Milad un Nabi (approximate)',
        ],
        2027 => [
            '2027-03-10' => 'Eid ul Fitr (approximate)', '2027-03-11' => 'Eid ul Fitr (approximate)', '2027-03-12' => 'Eid ul Fitr (approximate)',
            '2027-05-17' => 'Eid ul Adha (approximate)', '2027-05-18' => 'Eid ul Adha (approximate)', '2027-05-19' => 'Eid ul Adha (approximate)',
            '2027-06-14' => 'Ashura, 9 Muharram (approximate)', '2027-06-15' => 'Ashura, 10 Muharram (approximate)',
            '2027-08-15' => 'Eid Milad un Nabi (approximate)',
        ],
    ];

    /**
     * A date can carry two holidays (Pakistan Day and an Eid in 2026), so this is a list, not a map.
     *
     * @return list<array{date: string, name: string}>
     */
    public static function forYear(int $year): array
    {
        $days = [];

        foreach (self::FIXED as $monthDay => $name) {
            $days[] = ['date' => "{$year}-{$monthDay}", 'name' => $name];
        }

        foreach (self::ISLAMIC[$year] ?? [] as $date => $name) {
            $days[] = ['date' => $date, 'name' => $name];
        }

        usort($days, fn (array $a, array $b): int => [$a['date'], $a['name']] <=> [$b['date'], $b['name']]);

        return $days;
    }

    /**
     * @return list<int>
     */
    public static function islamicYears(): array
    {
        return array_keys(self::ISLAMIC);
    }
}
