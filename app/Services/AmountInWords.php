<?php

namespace App\Services;

/**
 * An amount in English words for a cheque: "One Lakh Twenty Three Thousand Four Hundred Fifty Six and 78/100 Only".
 * `south_asian` groups by thousand / lakh / crore, `international` by thousand / million / billion.
 */
class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function convert(float $amount, string $style = 'south_asian'): string
    {
        $amount = round(abs($amount), 2);
        $whole = (int) floor($amount);
        $cents = (int) round(($amount - $whole) * 100);

        $words = $whole === 0 ? 'Zero' : self::wholeNumber($whole, $style);

        return $words.' and '.str_pad((string) $cents, 2, '0', STR_PAD_LEFT).'/100 Only';
    }

    private static function wholeNumber(int $number, string $style): string
    {
        $parts = [];

        if ($style === 'international') {
            foreach ([1_000_000_000 => 'Billion', 1_000_000 => 'Million', 1_000 => 'Thousand'] as $size => $name) {
                if ($number >= $size) {
                    $parts[] = self::hundreds(intdiv($number, $size)).' '.$name;
                    $number %= $size;
                }
            }
        } else {
            foreach ([10_000_000 => 'Crore', 100_000 => 'Lakh', 1_000 => 'Thousand'] as $size => $name) {
                if ($number >= $size) {
                    $parts[] = self::wholeNumber(intdiv($number, $size), $style).' '.$name;
                    $number %= $size;
                }
            }
        }

        if ($number > 0) {
            $parts[] = self::hundreds($number);
        }

        return implode(' ', $parts);
    }

    private static function hundreds(int $number): string
    {
        $words = [];

        if ($number >= 100) {
            $words[] = self::ONES[intdiv($number, 100)].' Hundred';
            $number %= 100;
        }

        if ($number >= 20) {
            $words[] = self::TENS[intdiv($number, 10)].($number % 10 > 0 ? ' '.self::ONES[$number % 10] : '');
        } elseif ($number > 0) {
            $words[] = self::ONES[$number];
        }

        return implode(' ', $words);
    }
}
