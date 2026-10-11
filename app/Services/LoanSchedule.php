<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * The repayment schedule of a loan: equal instalments (annuity) at the periodic rate, the last one absorbing the rounding so the
 * principal parts add up to exactly the amount borrowed. A rate of 0 splits the principal evenly. The first instalment falls one
 * period after the start date.
 */
class LoanSchedule
{
    public const FREQUENCIES = ['monthly' => 12, 'quarterly' => 4];

    /**
     * @return list<array{number: int, due_on: string, principal: float, interest: float, total: float}>
     */
    public static function build(float $principal, float $annualRatePercent, int $installments, string $frequency, string $startDate): array
    {
        $perYear = self::FREQUENCIES[$frequency] ?? 12;
        $months = 12 / $perYear;
        $rate = $annualRatePercent / 100 / $perYear;
        $principal = round($principal, 2);
        $installments = max($installments, 1);

        $payment = $rate > 0
            ? $principal * $rate / (1 - (1 + $rate) ** -$installments)
            : $principal / $installments;

        $rows = [];
        $balance = $principal;
        $start = CarbonImmutable::parse($startDate);

        for ($number = 1; $number <= $installments; $number++) {
            $interest = round($balance * $rate, 2);
            $part = $number === $installments ? $balance : round($payment - $interest, 2);
            $part = min($part, $balance);

            $rows[] = [
                'number' => $number,
                'due_on' => $start->addMonthsNoOverflow((int) ($months * $number))->toDateString(),
                'principal' => round($part, 2),
                'interest' => $interest,
                'total' => round($part + $interest, 2),
            ];

            $balance = round($balance - $part, 2);
        }

        return $rows;
    }
}
