<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Country;
use App\Services\CountryStateCityApiSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CountryController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function countryFormRules(?int $exceptId = null): array
    {
        return [
            'name' => [
                'bail',
                'required',
                'string',
                'max:100',
                Rule::unique('countries', 'name')->ignore($exceptId),
            ],
            'iso2' => [
                'bail',
                'required',
                'string',
                'size:2',
                Rule::unique('countries', 'iso2')->ignore($exceptId),
            ],
            'iso3' => 'nullable|string|size:3',
            'phonecode' => 'nullable|string|max:255',
            'capital' => 'nullable|string|max:255',
            'nationality' => 'nullable|string|max:255',
            'flag' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $allowedSorts = ['name', 'iso2', 'iso3', 'phonecode', 'capital', 'flag', 'created_at'];
        $sortBy = in_array($request->sort_by, $allowedSorts, true) ? $request->sort_by : 'created_at';

        $query = Country::query()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('flag', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('flag', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'iso2', 'iso3', 'phonecode', 'capital'], 'like', "%{$search}%");
                });
            });

        $countries = $this->paginateSorted($query, $request->merge(['sort_by' => $sortBy]));

        $trashCount = Country::onlyTrashed()->count();

        return response()->json(['data' => $countries, 'trash_count' => $trashCount]);
    }

    public function checkName(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'except_id' => 'nullable|integer',
        ]);

        return response()->json([
            'name_taken' => Country::nameExists(
                $request->string('name')->toString(),
                $request->integer('except_id') ?: null,
            ),
        ]);
    }

    public function checkIso2(Request $request): JsonResponse
    {
        $request->validate([
            'iso2' => 'required|string|size:2',
            'except_id' => 'nullable|integer',
        ]);

        return response()->json([
            'iso2_taken' => Country::iso2Exists(
                $request->string('iso2')->toString(),
                $request->integer('except_id') ?: null,
            ),
        ]);
    }

    public function fetchFromApi(CountryStateCityApiSync $sync): JsonResponse
    {
        $this->authorizeMenuPermission('/country/add');

        set_time_limit(180);

        try {
            $result = $sync->syncCountries();
        } catch (RuntimeException $e) {
            return response()->json(['errormessage' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['errormessage' => 'Failed to fetch countries from the API.'], 422);
        }

        return response()->json([
            'message' => "Fetched countries from API. Created {$result['created']}, updated {$result['updated']}.",
            'created' => $result['created'],
            'updated' => $result['updated'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/country/add');

        $request->merge([
            'name' => Country::normalizeName($request->input('name')),
            'iso2' => Country::normalizeIso2($request->input('iso2')),
            'iso3' => Country::normalizeIso3($request->input('iso3')),
        ]);

        $request->validate($this->countryFormRules());

        DB::beginTransaction();
        try {
            Country::storeFromRequest($request);
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
        $country = Country::query()->findOrFail($id);

        return response()->json($country);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/country/:id/edit');

        $request->merge([
            'name' => Country::normalizeName($request->input('name')),
            'iso2' => Country::normalizeIso2($request->input('iso2')),
            'iso3' => Country::normalizeIso3($request->input('iso3')),
        ]);

        $request->validate($this->countryFormRules($id));

        DB::beginTransaction();
        try {
            $country = Country::query()->findOrFail($id);
            $country->updateFromRequest($request);
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
        if (deletepermission('/country/delete')) {
            Country::deleteCountry($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/country/delete', 'Successfully Deleted', function () use ($request) {
            Country::query()->whereIn('id', $request->all())->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/country/delete', 'Successfully Deleted', function () use ($request) {
            Country::query()->whereIn('id', (array) $request->all())->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission('/country/restore')) {
            DB::beginTransaction();
            try {
                Country::query()->whereIn('id', $request->all())->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/country/:id/edit');
        $countries = Country::query()->whereIn('id', $request->ids)->get();

        if ($countries->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($countries as $country) {
                if (isset($request->status)) {
                    $country->flag = $request->status;
                } else {
                    $country->flag = ! $country->flag;
                }
                $country->save();
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function trash(Request $request): JsonResponse
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $allowedSorts = ['name', 'iso2', 'iso3', 'phonecode', 'capital', 'flag', 'created_at'];
        $sortBy = in_array($request->sort_by, $allowedSorts, true) ? $request->sort_by : 'created_at';

        $query = Country::onlyTrashed()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('flag', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('flag', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'iso2', 'iso3', 'phonecode', 'capital'], 'like', "%{$search}%");
                });
            });

        $countries = $this->paginateSorted($query, $request->merge(['sort_by' => $sortBy]));

        return response()->json(['data' => $countries]);
    }

    public function fetch(): JsonResponse
    {
        $countries = Country::query()
            ->where('flag', true)
            ->select('countries.*', 'name as text')
            ->orderBy('name')
            ->get();

        return response()->json($countries);
    }
}
