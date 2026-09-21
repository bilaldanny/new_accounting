<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

abstract class Controller
{
    /**
     * The end of a `try { ... } catch (Throwable $e)` around a transaction: rolls it back, then answers 500 with
     * the message, as these controllers always have. An HTTP exception (`abort(404)` for a record that does not
     * exist) or a validation error is not a failure of the server, so it goes on as itself (404 / 422) instead of
     * being caught and turned into a 500.
     */
    protected function failedTransaction(Throwable $e): JsonResponse
    {
        DB::rollBack();

        if ($e instanceof HttpExceptionInterface || $e instanceof ValidationException) {
            throw $e;
        }

        return response()->json(['errormessage' => $e->getMessage()], 500);
    }

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
