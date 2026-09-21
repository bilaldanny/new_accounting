<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * An array is not a value to normalise (`strtoupper(trim(...))` on it is a TypeError, a 500). It is left as
     * it came so the field's `string` rule answers 422.
     */
    protected function normalizeUnlessArray(mixed $value, callable $normalize): mixed
    {
        return is_array($value) ? $value : $normalize($value);
    }

    protected function authorizeMenuPermission(string $key): void
    {
        abortUnlessMenuPermission($key);
    }

    protected function authorizeCompanySettingMenuPermission(): void
    {
        abortUnlessCompanySettingMenuPermission();
    }
}
