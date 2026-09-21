<?php

namespace App\Support;

/**
 * The class of an account (equity, assets, liabilities, expenses, revenue, cost of goods sold) and the
 * side it normally carries, read from the first digit of its chart-of-accounts code: 1xx equity, 2xx
 * assets, 3xx liabilities, 4xx expenses, 5xx revenue, 6xx cost of goods sold.
 *
 * Reports use this instead of the stored `acc_nature`, `bs` and `pl` flags, which are wrong on many live
 * accounts (for example Sales sits under Revenue but is flagged as a debit account). It is for reading
 * balances; posting still takes the nature from the account itself.
 */
final class AccountClass
{
    /**
     * @var array<int, array{key: string, label: string, nature: string}>
     */
    public const CLASSES = [
        1 => ['key' => 'equity', 'label' => 'Equity', 'nature' => 'cr'],
        2 => ['key' => 'assets', 'label' => 'Assets', 'nature' => 'dr'],
        3 => ['key' => 'liabilities', 'label' => 'Liabilities', 'nature' => 'cr'],
        4 => ['key' => 'expenses', 'label' => 'Expenses', 'nature' => 'dr'],
        5 => ['key' => 'revenue', 'label' => 'Revenue', 'nature' => 'cr'],
        6 => ['key' => 'cogs', 'label' => 'Cost of Goods Sold', 'nature' => 'dr'],
    ];

    /**
     * The class of a code, or null when it does not start with 1-6.
     *
     * @return array{digit: int, key: string, label: string, nature: string}|null
     */
    public static function of(?string $code): ?array
    {
        $digit = (int) substr(trim((string) $code), 0, 1);

        return isset(self::CLASSES[$digit]) ? ['digit' => $digit] + self::CLASSES[$digit] : null;
    }

    /**
     * The side ('dr' or 'cr') balances of this account normally sit on.
     */
    public static function nature(?string $code, string $fallback = 'dr'): string
    {
        return self::of($code)['nature'] ?? $fallback;
    }

    public static function label(?string $code): string
    {
        return self::of($code)['label'] ?? 'Other';
    }
}
