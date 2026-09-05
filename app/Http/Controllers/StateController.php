<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Country;
use App\Models\State;
use App\Services\CountryStateCityApiSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StateController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function stateFormRules(?int $exceptId = null): array
    {
        return [
            'country_id' => 'bail|required|integer|exists:countries,id',
            'name' => [
                'bail',
                'required',
                'string',
                'max:255',
                Rule::unique('states', 'name')
                    ->where('country_id', request()->integer('country_id'))
                    ->ignore($exceptId),
            ],
            'iso2' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:191',
            'flag' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $sortColumns = [
            'name' => 'states.name',
            'iso2' => 'states.iso2',
            'flag' => 'states.flag',
            'created_at' => 'states.created_at',
            'country_name' => 'countries.name',
        ];
        $request->merge([
            'sort_by' => $sortColumns[$request->sort_by ?? 'created_at'] ?? 'states.created_at',
        ]);

        $query = State::query()
            ->leftJoin((new Country)->getTable(), function ($join) {
                $join->on('countries.id', '=', 'states.country_id')
                    ->whereNull('countries.deleted_at');
            })
            ->select('states.*', 'countries.name as country_name')
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('states.flag', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('states.flag', [0, 1]);
            })
            ->when($request->filled('country_id'), function ($q) use ($request) {
                $q->where('states.country_id', $request->country_id);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('states.name', 'like', "%{$search}%")
                        ->orWhere('states.iso2', 'like', "%{$search}%")
                        ->orWhere('countries.name', 'like', "%{$search}%");
                });
            });

        $states = $this->paginateSorted($query, $request);

        $trashCount = State::onlyTrashed()->count();

        return response()->json(['data' => $states, 'trash_count' => $trashCount]);
    }

    public function checkName(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'country_id' => 'nullable|integer',
            'except_id' => 'nullable|integer',
        ]);

        $countryId = $request->integer('country_id');

        return response()->json([
            'name_taken' => $countryId > 0 && State::nameExists(
                $request->string('name')->toString(),
                $countryId,
                $request->integer('except_id') ?: null,
            ),
        ]);
    }

    public function fetchFromApi(Request $request, CountryStateCityApiSync $sync): JsonResponse
    {
        $this->authorizeMenuPermission('/state/add');

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
            $result = $sync->syncStates($countryIso2);
        } catch (RuntimeException $e) {
            return response()->json(['errormessage' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['errormessage' => 'Failed to fetch states from the API.'], 422);
        }

        $countryLabel = $result['country'] && $result['iso2']
            ? "{$result['country']} ({$result['iso2']})"
            : 'the selected country';

        if ($result['country'] === null) {
            $message = 'No countries are available to fetch states.';
        } elseif ($result['done'] && $countryIso2 === null) {
            $message = "Fetched states for {$countryLabel}. Created {$result['created']}, updated {$result['updated']}. All countries are done. Click Fetch again to start from the first country.";
        } elseif ($countryIso2 === null) {
            $message = "Fetched states for {$countryLabel}. Created {$result['created']}, updated {$result['updated']}. {$result['remaining']} countries left. Click Fetch again to continue.";
        } else {
            $message = "Fetched states for {$countryLabel}. Created {$result['created']}, updated {$result['updated']}.";
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
        $this->authorizeMenuPermission('/state/add');

        $request->merge([
            'name' => State::normalizeName($request->input('name')),
            'iso2' => State::normalizeIso2($request->input('iso2')),
        ]);

        $request->validate($this->stateFormRules());

        DB::beginTransaction();
        try {
            State::storeFromRequest($request);
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
        $state = State::query()->findOrFail($id);

        return response()->json($state);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/state/:id/edit');

        $request->merge([
            'name' => State::normalizeName($request->input('name')),
            'iso2' => State::normalizeIso2($request->input('iso2')),
        ]);

        $request->validate($this->stateFormRules($id));

        DB::beginTransaction();
        try {
            $state = State::query()->findOrFail($id);
            $state->updateFromRequest($request);
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
        if (deletepermission('/state/delete')) {
            State::deleteState($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/state/delete', 'Successfully Deleted', function () use ($request) {
            State::query()->whereIn('id', $request->all())->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/state/delete', 'Successfully Deleted', function () use ($request) {
            State::query()->whereIn('id', (array) $request->all())->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission('/state/restore')) {
            DB::beginTransaction();
            try {
                State::query()->whereIn('id', $request->all())->restore();
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
        $this->authorizeMenuPermission('/state/:id/edit');
        $states = State::query()->whereIn('id', $request->ids)->get();

        if ($states->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($states as $state) {
                if (isset($request->status)) {
                    $state->flag = $request->status;
                } else {
                    $state->flag = ! $state->flag;
                }
                $state->save();
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

        $sortColumns = [
            'name' => 'states.name',
            'iso2' => 'states.iso2',
            'flag' => 'states.flag',
            'created_at' => 'states.created_at',
            'country_name' => 'countries.name',
        ];
        $request->merge([
            'sort_by' => $sortColumns[$request->sort_by ?? 'created_at'] ?? 'states.created_at',
        ]);

        $query = State::onlyTrashed()
            ->leftJoin((new Country)->getTable(), function ($join) {
                $join->on('countries.id', '=', 'states.country_id')
                    ->whereNull('countries.deleted_at');
            })
            ->select('states.*', 'countries.name as country_name')
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('states.flag', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('states.flag', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('states.name', 'like', "%{$search}%")
                        ->orWhere('states.iso2', 'like', "%{$search}%")
                        ->orWhere('countries.name', 'like', "%{$search}%");
                });
            });

        $states = $this->paginateSorted($query, $request);

        return response()->json(['data' => $states]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $states = State::query()
            ->where('country_id', $request->country_id)
            ->where('flag', true)
            ->select('name as text', 'states.*')
            ->orderBy('name')
            ->get();

        return response()->json($states);
    }
}
