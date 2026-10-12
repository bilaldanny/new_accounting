<?php

namespace App\Services\Messaging;

/**
 * Turns the way a number was typed into the international digits the providers want (E.164 without the plus).
 */
final class PhoneNumber
{
    public static function digits(string $raw, string $defaultCountry = '92'): ?string
    {
        $number = preg_replace('/[^0-9]/', '', trim($raw)) ?? '';

        if ($number === '') {
            return null;
        }

        if (str_starts_with($number, '00')) {
            $number = substr($number, 2);
        } elseif (str_starts_with($number, '0')) {
            $number = $defaultCountry.substr($number, 1);
        } elseif (strlen($number) <= 10 && ! str_starts_with($number, $defaultCountry)) {
            $number = $defaultCountry.$number;
        }

        return strlen($number) >= 8 && strlen($number) <= 15 ? $number : null;
    }
}
