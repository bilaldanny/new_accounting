<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The response of the reports whose figures are worked out per row rather than read off one table:
 * the whole filtered set is built, then sorted and cut into the requested page here. The shape is the
 * same as every other report: the page of rows (`data`, a Laravel paginator), `summary` (totals over
 * the whole filtered set) and `trash_count`.
 */
trait PaginatesReportRows
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     * @param  array<string, string>  $sortable  sort key sent by the table => field of the row
     */
    protected function reportResponse(Request $request, Collection $rows, array $summary, array $sortable, string $defaultSort, bool $defaultDescending = true): JsonResponse
    {
        $sortBy = (string) $request->input('sort_by');
        $isKnownSort = array_key_exists($sortBy, $sortable);
        $field = $isKnownSort ? $sortable[$sortBy] : $sortable[$defaultSort];
        $descending = $isKnownSort ? $request->input('sort_type') !== 'asc' : $defaultDescending;

        return response()->json([
            'data' => $this->paginateRows($rows, $field, $descending, $request),
            'summary' => $summary,
            'trash_count' => 0,
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function paginateRows(Collection $rows, string $field, bool $descending, Request $request): LengthAwarePaginator
    {
        $sorted = $rows->sort(function (array $a, array $b) use ($field, $descending): int {
            $left = $a[$field] ?? null;
            $right = $b[$field] ?? null;
            $order = is_string($left) || is_string($right)
                ? strcasecmp((string) $left, (string) $right)
                : $left <=> $right;

            return ($descending ? -$order : $order) ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        })->values();

        $perPage = $request->integer('show_record', 10);
        $lastPage = max((int) ceil($sorted->count() / $perPage), 1);
        $page = min(max($request->integer('cur_page', 1), 1), $lastPage);

        return new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url()],
        );
    }
}
