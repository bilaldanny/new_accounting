<?php

namespace App\Http\Controllers\Concerns;

use App\Support\ListSort;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Shared mechanics for controller `index()`/`trash()` sorting+pagination and
 * `bulk_delete()`/`bulk_delete_per()` permission-guarded batch actions.
 *
 * Callers keep ownership of query construction (search/status/scoping) and
 * of the deletion logic itself; this only extracts the identical wrapper
 * code around both.
 */
trait HandlesIndexAndBulkDelete
{
    /**
     * Largest page a list request can ask for (the table export asks for at most 500 at a time).
     */
    protected const MAX_PAGE_SIZE = 1000;

    /**
     * Apply the common sort_by/sort_type/show_record/cur_page request params
     * to $query and paginate, re-resolving the page when cur_page overshoots
     * the last page (identical to the inline block every index()/trash() has).
     */
    protected function paginateSorted(Builder $query, Request $request): LengthAwarePaginator
    {
        $sortBy = ListSort::column($request->sort_by);
        $sortType = ListSort::direction($request->sort_type);
        $showRecord = ListSort::wholeNumber($request->show_record, 10, self::MAX_PAGE_SIZE);
        $curPage = ListSort::wholeNumber($request->cur_page, 1);

        $query->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $result = $query->paginate($showRecord);

        if ($curPage > $result->lastPage()) {
            Paginator::currentPageResolver(function () use ($result) {
                return $result->lastPage();
            });
            $result = $query->paginate($showRecord);
        }

        return $result;
    }

    /**
     * Run $action inside a DB transaction guarded by deletepermission($permissionPath),
     * matching the existing bulk_delete/bulk_delete_per/restore_records convention:
     * '406' JSON string when unauthorized, whole-batch rollback + 500 errormessage
     * on any Throwable, otherwise a success message.
     */
    protected function guardedBulkAction(string $permissionPath, string $successMessage, Closure $action): JsonResponse
    {
        if (! deletepermission($permissionPath)) {
            return response()->json('406');
        }

        return $this->runInTransaction($successMessage, $action);
    }

    /**
     * Same transaction/commit/rollback/response wrapper as guardedBulkAction(), for the
     * handful of controllers whose permission guard is more than a single
     * deletepermission() check (e.g. an additional superadmin gate) — callers do their
     * own guard clause, then delegate the transaction body to this.
     */
    protected function runInTransaction(string $successMessage, Closure $action): JsonResponse
    {
        DB::beginTransaction();
        try {
            $action();
            DB::commit();

            return response()->json(['message' => $successMessage]);
        } catch (Throwable $e) {
            return $this->failedTransaction($e);
        }
    }
}
