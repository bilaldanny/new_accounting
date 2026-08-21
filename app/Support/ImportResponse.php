<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ImportResponse
{
    /**
     * @return array{
     *     total: int,
     *     created: int,
     *     updated: int,
     *     failed: int
     * }
     */
    public static function summary(int $total, int $created, int $updated, int $failed = 0): array
    {
        return [
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
        ];
    }

    public static function success(int $total, int $created, int $updated, string $resourceLabel): JsonResponse
    {
        return response()->json([
            'message' => sprintf(
                'Successfully imported %d new and updated %d %s.',
                $created,
                $updated,
                $resourceLabel
            ),
            'summary' => self::summary($total, $created, $updated),
        ]);
    }

    /**
     * @param  list<string>  $errors
     */
    public static function failure(int $total, array $errors): JsonResponse
    {
        return response()->json([
            'message' => 'Import could not be completed.',
            'summary' => self::summary($total, 0, 0, $total),
            'errors' => $errors,
        ], 422);
    }
}
