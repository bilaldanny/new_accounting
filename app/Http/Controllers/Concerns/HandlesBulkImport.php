<?php

namespace App\Http\Controllers\Concerns;

use App\Support\ImportResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shared body for controller `import()` actions: one DB transaction for the
 * whole batch, upserting each row via `{Model}::upsertFromImport()`.
 *
 * Callers remain responsible for authorization and `$request->validate()`
 * (rules differ per resource) before invoking this method.
 */
trait HandlesBulkImport
{
    /**
     * @param  class-string  $modelClass  Must expose a static
     *                                    `upsertFromImport(array $row): string` returning 'created' or 'updated'.
     */
    protected function importRows(Request $request, string $modelClass, string $resourceLabel): JsonResponse
    {
        DB::beginTransaction();

        try {
            $created = 0;
            $updated = 0;

            foreach ($request->rows as $index => $row) {
                if (! is_array($row)) {
                    throw ValidationException::withMessages([
                        'rows' => ['Row '.($index + 1).' is invalid.'],
                    ]);
                }

                if ($modelClass::upsertFromImport($row) === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            }

            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return ImportResponse::success(
            count($request->rows),
            $created,
            $updated,
            $resourceLabel
        );
    }
}
