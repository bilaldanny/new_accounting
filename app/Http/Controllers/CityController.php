<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Services\CountryStateCityApiSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CityController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    protected function cityFormRules(?int $exceptId = null): array
    {
        return [
            'country_id' => 'bail|required|integer|exists:countries,id',
            'state_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('states', 'id')->where('country_id', request()->integer('country_id')),
            ],
            'name' => [
                'bail',
                'required',
                'string',
                'max:255',
                Rule::unique('cities', 'name')
                    ->where('state_id', request()->integer('state_id'))
                    ->ignore($exceptId),
            ],
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'flag' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $sortBy = $request->sort_by ?? 'created_at';
        $sortType = $request->sort_type ?? 'desc';
        $showRecord = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $curPage = $request->cur_page ?? 1;

        $sortColumns = [
            'name' => 'cities.name',
            'flag' => 'cities.flag',
            'created_at' => 'cities.created_at',
            'country_name' => 'countries.name',
            'state_name' => 'states.name',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? 'cities.created_at';

        $query = City::query()
            ->leftJoin((new Country)->getTable(), function ($join) {
                $join->on('countries.id', '=', 'cities.country_id')
                    ->whereNull('countries.deleted_at');
            })
            ->leftJoin((new State)->getTable(), function ($join) {
                $join->on('states.id', '=', 'cities.state_id')
                    ->whereNull('states.deleted_at');
            })
            ->select('cities.*', 'countries.name as country_name', 'states.name as state_name')
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('cities.flag', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('cities.flag', [0, 1]);
            })
            ->when($request->filled('country_id'), function ($q) use ($request) {
                $q->where('cities.country_id', $request->country_id);
            })
            ->when($request->filled('state_id'), function ($q) use ($request) {
                $q->where('cities.state_id', $request->state_id);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('cities.name', 'like', "%{$search}%")
                        ->orWhere('countries.name', 'like', "%{$search}%")
                        ->orWhere('states.name', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortColumn, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $cities = $query->paginate($showRecord);

        if ($curPage > $cities->lastPage()) {
            Paginator::currentPageResolver(function () use ($cities) {
                return $cities->lastPage();
            });
            $cities = $query->paginate($showRecord);
        }

        $trashCount = City::onlyTrashed()->count();

        return response()->json(['data' => $cities, 'trash_count' => $trashCount]);
    }

    public function checkName(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'state_id' => 'nullable|integer',
            'except_id' => 'nullable|integer',
        ]);

        $stateId = $request->integer('state_id');

        return response()->json([
            'name_taken' => $stateId > 0 && City::nameExists(
                $request->string('name')->toString(),
                $stateId,
                $request->integer('except_id') ?: null,
            ),
        ]);
    }

    public function fetchFromApi(Request $request, CountryStateCityApiSync $sync): JsonResponse
    {
        $this->authorizeMenuPermission('/city/add');

        $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
        ]);

        set_time_limit(300);

        $country = Country::query()->find($request->integer('country_id'));
        $countryIso2 = Country::normalizeIso2($country?->iso2);

        if ($countryIso2 === '') {
            return response()->json([
                'errormessage' => 'The selected country does not have an ISO2 code.',
            ], 422);
        }

        try {
            $result = $sync->syncCities($countryIso2);
        } catch (RuntimeException $e) {
            return response()->json(['errormessage' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['errormessage' => 'Failed to fetch cities from the API.'], 422);
        }

        return response()->json([
            'message' => "Fetched cities from API. Created {$result['created']}, updated {$result['updated']}.",
            'created' => $result['created'],
            'updated' => $result['updated'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/city/add');

        $request->merge([
            'name' => City::normalizeName($request->input('name')),
        ]);

        $request->validate($this->cityFormRules());

        DB::beginTransaction();
        try {
            City::storeFromRequest($request);
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
        $city = City::query()->findOrFail($id);

        return response()->json($city);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/city/:id/edit');

        $request->merge([
            'name' => City::normalizeName($request->input('name')),
        ]);

        $request->validate($this->cityFormRules($id));

        DB::beginTransaction();
        try {
            $city = City::query()->findOrFail($id);
            $city->updateFromRequest($request);
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
        if (deletepermission('/city/delete')) {
            City::deleteCity($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (deletepermission('/city/delete')) {
            DB::beginTransaction();
            try {
                City::query()->whereIn('id', $request->all())->delete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        if (deletepermission('/city/delete')) {
            DB::beginTransaction();
            try {
                City::query()->whereIn('id', (array) $request->all())->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission('/city/restore')) {
            DB::beginTransaction();
            try {
                City::query()->whereIn('id', $request->all())->restore();
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
        $this->authorizeMenuPermission('/city/:id/edit');
        $cities = City::query()->whereIn('id', $request->ids)->get();

        if ($cities->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($cities as $city) {
                if (isset($request->status)) {
                    $city->flag = $request->status;
                } else {
                    $city->flag = ! $city->flag;
                }
                $city->save();
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
        $sortBy = $request->sort_by ?? 'created_at';
        $sortType = $request->sort_type ?? 'desc';
        $showRecord = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $curPage = $request->cur_page ?? 1;

        $sortColumns = [
            'name' => 'cities.name',
            'flag' => 'cities.flag',
            'created_at' => 'cities.created_at',
            'country_name' => 'countries.name',
            'state_name' => 'states.name',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? 'cities.created_at';

        $query = City::onlyTrashed()
            ->leftJoin((new Country)->getTable(), function ($join) {
                $join->on('countries.id', '=', 'cities.country_id')
                    ->whereNull('countries.deleted_at');
            })
            ->leftJoin((new State)->getTable(), function ($join) {
                $join->on('states.id', '=', 'cities.state_id')
                    ->whereNull('states.deleted_at');
            })
            ->select('cities.*', 'countries.name as country_name', 'states.name as state_name')
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('cities.flag', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('cities.flag', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('cities.name', 'like', "%{$search}%")
                        ->orWhere('countries.name', 'like', "%{$search}%")
                        ->orWhere('states.name', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortColumn, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $cities = $query->paginate($showRecord);

        if ($curPage > $cities->lastPage()) {
            Paginator::currentPageResolver(function () use ($cities) {
                return $cities->lastPage();
            });
            $cities = $query->paginate($showRecord);
        }

        return response()->json(['data' => $cities]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $state = State::query()->find($request->state_id);

        $cities = [];

        if (isset($state)) {
            $cities = City::query()
                ->where('country_id', $state->country_id)
                ->where('state_id', $request->state_id)
                ->where('flag', true)
                ->select('name as text', 'cities.*')
                ->orderBy('name')
                ->get();
        }

        return response()->json($cities);
    }
}
