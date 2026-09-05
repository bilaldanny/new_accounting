<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Timezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class TimezoneController extends Controller
{
    use HandlesIndexAndBulkDelete;

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
        $search = $request->search ?? '';

        $query = Timezone::query()
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });

        $timezones = $this->paginateSorted($query, $request);

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
        $this->authorizeMenuPermission('/timezone/add');

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

            return response()->json(['errormessage' => $e->getMessage()], 500);
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
        $this->authorizeMenuPermission('/timezone/:id/edit');

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

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy(int $id): JsonResponse
    {
        if (deletepermission('/timezone/delete')) {
            Timezone::deleteTimezone($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/timezone/delete', 'Successfully Deleted', function () use ($request) {
            Timezone::query()->whereIn('id', $request->all())->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/timezone/delete', 'Successfully Deleted', function () use ($request) {
            Timezone::query()->whereIn('id', (array) $request->all())->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission('/timezone/restore')) {
            DB::beginTransaction();
            try {
                Timezone::query()->whereIn('id', $request->all())->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function trash(Request $request): JsonResponse
    {
        $search = $request->search ?? '';

        $query = Timezone::onlyTrashed()
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });

        $timezones = $this->paginateSorted($query, $request);

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
