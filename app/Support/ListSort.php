<?php

namespace App\Support;

/**
 * Cleans the sort and paging values a list request carries (`sort_by`, `sort_type`, `show_record`,
 * `cur_page`) so a bad query string means "the default" instead of a 500 from the database or the
 * paginator. It does not know which columns a list may sort by: whitelisting those stays with the
 * controller (the newer ones do); this only keeps garbage out of ORDER BY and out of paginate().
 */
final class ListSort
{
    /**
     * A plain, optionally table-qualified, identifier; anything else is $default.
     */
    public static function column(mixed $column, string $default = 'created_at'): string
    {
        $column = is_string($column) ? trim($column) : '';

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) === 1 ? $column : $default;
    }

    /**
     * `asc` or `desc` in any case; anything else is $default.
     *
     * @param  'asc'|'desc'  $default
     * @return 'asc'|'desc'
     */
    public static function direction(mixed $direction, string $default = 'desc'): string
    {
        $direction = is_string($direction) ? strtolower(trim($direction)) : '';

        return in_array($direction, ['asc', 'desc'], true) ? $direction : $default;
    }

    /**
     * A whole number of at least 1 (and at most $max when given), else $default: `show_record=abc`, `0`,
     * `-5` and `1.5` all mean the default instead of an error.
     */
    public static function wholeNumber(mixed $value, int $default, ?int $max = null): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($number === false) {
            return $default;
        }

        return $max === null ? $number : min($number, $max);
    }
}
