<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesBulkImport;
use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class UnitController extends Controller
{
    use HandlesBulkImport, HandlesIndexAndBulkDelete;

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
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

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
            });

        $units = $this->paginateSorted($query, $request);

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
        $this->authorizeMenuPermission('/unit/add');

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

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function import(Request $request)
    {
        $this->authorizeMenuPermission('/unit/import');

        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string|min:3|max:200',
            'rows.*.short_name' => 'bail|required|string|max:200',
            'rows.*.type' => 'nullable|in:large,small',
        ]);

        return $this->importRows($request, Unit::class, 'unit records');
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
        $this->authorizeMenuPermission('/unit/:id/edit');

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

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id)
    {
        if (deletepermission('/unit/delete')) {
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
        return $this->guardedBulkAction('/unit/delete', 'Successfully Deleted', function () use ($request) {
            $ids = Unit::query()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->pluck('id');

            foreach ($ids as $unitId) {
                Unit::deleteUnit((int) $unitId);
            }
        });
    }

    public function bulk_delete_per(Request $request)
    {
        return $this->guardedBulkAction('/unit/delete', 'Successfully Deleted', function () use ($request) {
            $ids = Unit::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->pluck('id');

            Unit::whereIn('id', $ids)->forceDelete();
        });
    }

    public function updatestatus(Request $request)
    {
        $this->authorizeMenuPermission('/unit/:id/edit');
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

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        } else {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function restore_records(Request $request)
    {
        if (deletepermission('/unit/restore')) {
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

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function duplicate(Request $request)
    {
        $this->authorizeMenuPermission('/unit/add');

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

            return response()->json(['errormessage' => $e->getMessage()], 500);
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
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

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
            });

        $units = $this->paginateSorted($query, $request);

        return response()->json(['data' => $units]);
    }
}
