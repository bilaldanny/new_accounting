<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Country;
use App\Models\Timezone;
use App\Services\CountryStateCityApiSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
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

    public function fetchFromApi(Request $request, CountryStateCityApiSync $sync): JsonResponse
    {
        $this->authorizeMenuPermission('/timezone/add');

        $request->validate([
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
        ]);

        set_time_limit(60);

        $countryIso2 = null;

        if ($request->filled('country_id')) {
            $country = Country::query()->find($request->integer('country_id'));
            $countryIso2 = Country::normalizeIso2($country?->iso2);

            if ($countryIso2 === '') {
                return response()->json([
                    'errormessage' => 'The selected country does not have an ISO2 code.',
                ], 422);
            }
        }

        try {
            $result = $sync->syncTimezones($countryIso2);
        } catch (RuntimeException $e) {
            return response()->json(['errormessage' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['errormessage' => 'Failed to fetch timezones from the API.'], 422);
        }

        $countryLabel = $result['country'] && $result['iso2']
            ? "{$result['country']} ({$result['iso2']})"
            : 'the selected country';

        if ($result['country'] === null) {
            $message = 'No countries are available to fetch timezones.';
        } elseif ($result['done'] && $countryIso2 === null) {
            $message = "Fetched timezones for {$countryLabel}. Created {$result['created']}, updated {$result['updated']}. All countries are done. Click Fetch again to start from the first country.";
        } elseif ($countryIso2 === null) {
            $message = "Fetched timezones for {$countryLabel}. Created {$result['created']}, updated {$result['updated']}. {$result['remaining']} countries left. Click Fetch again to continue.";
        } else {
            $message = "Fetched timezones for {$countryLabel}. Created {$result['created']}, updated {$result['updated']}.";
        }

        return response()->json([
            'message' => $message,
            'created' => $result['created'],
            'updated' => $result['updated'],
            'countries' => $result['countries'],
            'remaining' => $result['remaining'],
            'done' => $result['done'],
            'country' => $result['country'],
            'iso2' => $result['iso2'],
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
