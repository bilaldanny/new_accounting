<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Support\ImportResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class UnitController extends Controller
{
    /**
     * @return array<string, string>
     */
    protected function unitFormRules(): array
    {
        return [
            'name' => 'bail|required|string|min:3|max:200',
            'short_name' => 'bail|required|string|max:200',
            'type' => 'nullable|in:large,small',
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
        ];
    }

    public function index(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Unit::query()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'parentUnit:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'short_name'], 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $units = $query->paginate($show_record);

        if ($cur_page > $units->lastPage()) {
            Paginator::currentPageResolver(function () use ($units) {
                return $units->lastPage();
            });
            $units = $query->paginate($show_record);
        }

        $units->getCollection()->transform(function (Unit $unit) {
            $unit->company_name = $unit->company?->name;
            $unit->parent_name = $unit->parentUnit?->name;

            return $unit;
        });

        $trash_count = Unit::onlyTrashed()->count();

        return response()->json(['data' => $units, 'trash_count' => $trash_count]);
    }

    public function checkName(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'except_id' => 'nullable|integer',
            'company_id' => 'nullable',
        ]);

        return response()->json([
            'name_taken' => Unit::nameExists(
                $request->string('name')->toString(),
                $request->integer('except_id') ?: null,
                Unit::resolveScopedId($request->company_id),
            ),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate($this->unitFormRules());

        DB::beginTransaction();
        try {
            Unit::createUnit($request);
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

    public function import(Request $request)
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string|min:3|max:200',
            'rows.*.short_name' => 'bail|required|string|max:200',
            'rows.*.type' => 'nullable|in:large,small',
        ]);

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

                if (Unit::upsertFromImport($row) === 'created') {
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

            return response()->json(['errormessage' => $e]);
        }

        return ImportResponse::success(
            count($request->rows),
            $created,
            $updated,
            'unit records'
        );
    }

    public function show($id)
    {
        $unit = Unit::findVisibleToCurrentUser((int) $id);

        if ($unit === null) {
            abort(404);
        }

        return response()->json($unit);
    }

    public function update(Request $request, $id)
    {
        $request->validate($this->unitFormRules());

        DB::beginTransaction();
        try {
            Unit::updateUnit($request, (int) $id);
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

    public function destroy($id)
    {
        if (deletepermission()) {
            $unit = Unit::findVisibleToCurrentUser((int) $id);

            if ($unit === null) {
                abort(404);
            }

            Unit::deleteUnit((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Unit::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                foreach ($ids as $unitId) {
                    Unit::deleteUnit((int) $unitId);
                }

                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function bulk_delete_per(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Unit::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                Unit::whereIn('id', $ids)->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request)
    {
        $units = Unit::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if (isset($units)) {
            DB::beginTransaction();
            try {
                foreach ($units as $unit) {
                    if (isset($request->status)) {
                        $unit->active = $request->status;
                    } else {
                        if ($unit->active == false) {
                            $unit->active = 'true';
                        } else {
                            $unit->active = 'false';
                        }
                    }
                    $unit->save();
                }
                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        } else {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function restore_records(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Unit::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Unit::whereIn('id', $ids)->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function duplicate(Request $request)
    {
        DB::beginTransaction();
        try {
            $unit = Unit::findVisibleToCurrentUser((int) $request->id);

            if ($unit === null) {
                abort(404);
            }

            $duplicator = $unit->replicate();
            $duplicator->name = $this->duplicateUnitName($unit->name, $unit->company_id);
            $duplicator->short_name = $unit->short_name.' Copy';
            $duplicator->parent_id = null;
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    private function duplicateUnitName(string $name, mixed $companyId = null): string
    {
        $companyId = Unit::resolveScopedId($companyId);
        $candidate = $name.' Copy';
        $suffix = 1;

        while (Unit::nameExists($candidate, null, $companyId)) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
    }

    public function fetch(Request $request)
    {
        $units = Unit::query()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('except_id'), function ($q) use ($request) {
                $q->where('id', '!=', $request->except_id);
            })
            ->select('units.*', 'name as text')
            ->get();

        return response()->json($units);
    }

    public function trash(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Unit::onlyTrashed()
            ->visibleToCurrentUser()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'short_name'], 'like', "%{$search}%");
                });
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $units = $query->paginate($show_record);

        if ($cur_page > $units->lastPage()) {
            Paginator::currentPageResolver(function () use ($units) {
                return $units->lastPage();
            });
            $units = $query->paginate($show_record);
        }

        return response()->json(['data' => $units]);
    }
}
