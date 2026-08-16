<?php

namespace App\Http\Controllers;

use App\Models\Timezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TimezoneController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    protected function timezoneFormRules(?int $exceptId = null): array
    {
        return [
            'name' => [
                'bail',
                'required',
                'string',
                'max:255',
                Rule::unique('timezones', 'name')->ignore($exceptId),
            ],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $sortBy = $request->sort_by ?? 'created_at';
        $sortType = $request->sort_type ?? 'desc';
        $showRecord = $request->show_record ?? 10;
        $search = $request->search ?? '';
        $curPage = $request->cur_page ?? 1;

        $query = Timezone::query()
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $timezones = $query->paginate($showRecord);

        if ($curPage > $timezones->lastPage()) {
            Paginator::currentPageResolver(function () use ($timezones) {
                return $timezones->lastPage();
            });
            $timezones = $query->paginate($showRecord);
        }

        $trashCount = Timezone::onlyTrashed()->count();

        return response()->json(['data' => $timezones, 'trash_count' => $trashCount]);
    }

    public function checkName(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'except_id' => 'nullable|integer',
        ]);

        return response()->json([
            'name_taken' => Timezone::nameExists(
                $request->string('name')->toString(),
                $request->integer('except_id') ?: null,
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'name' => Timezone::normalizeName($request->input('name')),
        ]);

        $request->validate($this->timezoneFormRules());

        DB::beginTransaction();
        try {
            Timezone::storeFromRequest($request);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show(int $id): JsonResponse
    {
        $timezone = Timezone::query()->findOrFail($id);

        return response()->json($timezone);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->merge([
            'name' => Timezone::normalizeName($request->input('name')),
        ]);

        $request->validate($this->timezoneFormRules($id));

        DB::beginTransaction();
        try {
            $timezone = Timezone::query()->findOrFail($id);
            $timezone->updateFromRequest($request);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy(int $id): JsonResponse
    {
        if (deletepermission()) {
            Timezone::deleteTimezone($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                Timezone::query()->whereIn('id', $request->all())->delete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                Timezone::query()->whereIn('id', (array) $request->all())->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                Timezone::query()->whereIn('id', $request->all())->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function trash(Request $request): JsonResponse
    {
        $sortBy = $request->sort_by ?? 'created_at';
        $sortType = $request->sort_type ?? 'desc';
        $showRecord = $request->show_record ?? 10;
        $search = $request->search ?? '';
        $curPage = $request->cur_page ?? 1;

        $query = Timezone::onlyTrashed()
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $timezones = $query->paginate($showRecord);

        if ($curPage > $timezones->lastPage()) {
            Paginator::currentPageResolver(function () use ($timezones) {
                return $timezones->lastPage();
            });
            $timezones = $query->paginate($showRecord);
        }

        return response()->json(['data' => $timezones]);
    }

    public function fetch(): JsonResponse
    {
        $timezones = Timezone::query()
            ->select('timezones.*', 'name as text')
            ->orderBy('name')
            ->get();

        return response()->json($timezones);
    }
}
