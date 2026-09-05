<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesBulkImport;
use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class DepartmentController extends Controller
{
    use HandlesBulkImport, HandlesIndexAndBulkDelete;

    /**
     * @return array<string, string>
     */
    protected function departmentFormRules(): array
    {
        return [
            'name' => 'bail|required',
            'branch_id' => Auth::user()?->hasRole('companyadmin') ? 'required' : 'nullable',
        ];
    }

    public function index(Request $request)
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = Department::query()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name'], 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            });

        $departments = $this->paginateSorted($query, $request);

        $departments->getCollection()->transform(function (Department $department) {
            $department->company_name = $department->company?->name;
            $department->branch_name = $department->branch?->name;

            return $department;
        });

        $trash_count = Department::onlyTrashed()->count();

        return response()->json(['data' => $departments, 'trash_count' => $trash_count]);
    }

    public function checkName(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'except_id' => 'nullable|integer',
            'company_id' => 'nullable',
            'branch_id' => 'nullable',
        ]);

        return response()->json([
            'name_taken' => Department::nameExists(
                $request->string('name')->toString(),
                $request->integer('except_id') ?: null,
                Department::resolveScopedId($request->company_id),
                Department::resolveScopedId($request->branch_id),
            ),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeMenuPermission('/department/add');

        $request->validate($this->departmentFormRules());

        DB::beginTransaction();
        try {
            Department::createDepartment($request);
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
        $this->authorizeMenuPermission('/department/import');

        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string',
        ]);

        return $this->importRows($request, Department::class, 'department records');
    }

    public function show($id)
    {
        $department = Department::findVisibleToCurrentUser((int) $id);

        if ($department === null) {
            abort(404);
        }

        return response()->json($department);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeMenuPermission('/department/:id/edit');

        $request->validate($this->departmentFormRules());

        DB::beginTransaction();
        try {
            Department::updateDepartment($request, $id);
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
        if (deletepermission('/department/delete')) {
            $department = Department::findVisibleToCurrentUser((int) $id);

            if ($department === null) {
                abort(404);
            }

            Department::deleteDepartment($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        return $this->guardedBulkAction('/department/delete', 'Successfully Deleted', function () use ($request) {
            $ids = Department::query()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->pluck('id');

            Department::whereIn('id', $ids)->delete();
        });
    }

    public function bulk_delete_per(Request $request)
    {
        return $this->guardedBulkAction('/department/delete', 'Successfully Deleted', function () use ($request) {
            $ids = Department::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->pluck('id');

            Department::whereIn('id', $ids)->forceDelete();
        });
    }

    public function updatestatus(Request $request)
    {
        $this->authorizeMenuPermission('/department/:id/edit');
        $departments = Department::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if (isset($departments)) {
            DB::beginTransaction();
            try {
                foreach ($departments as $department) {
                    if (isset($request->status)) {
                        $department->active = $request->status;
                    } else {
                        if ($department->active == false) {
                            $department->active = 'true';
                        } else {
                            $department->active = 'false';
                        }
                    }
                    $department->save();
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
        if (deletepermission('/department/restore')) {
            DB::beginTransaction();
            try {
                $ids = Department::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Department::whereIn('id', $ids)->restore();
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
        $this->authorizeMenuPermission('/department/add');

        DB::beginTransaction();
        try {
            $department = Department::findVisibleToCurrentUser((int) $request->id);

            if ($department === null) {
                abort(404);
            }

            $duplicator = $department->replicate();
            $duplicator->name = $this->duplicateDepartmentName(
                $department->name,
                $department->company_id,
                $department->branch_id,
            );
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }
    }

    private function duplicateDepartmentName(string $name, mixed $companyId = null, mixed $branchId = null): string
    {
        $companyId = Department::resolveScopedId($companyId);
        $branchId = Department::resolveScopedId($branchId);
        $candidate = $name.' Copy';
        $suffix = 1;

        while (Department::nameExists($candidate, null, $companyId, $branchId)) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
    }

    public function fetch(Request $request)
    {
        $departments = Department::query()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->select('departments.*', 'name as text')
            ->get();

        return response()->json($departments);
    }

    public function trash(Request $request)
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = Department::onlyTrashed()
            ->visibleToCurrentUser()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name'], 'like', "%{$search}%");
                });
            });

        $departments = $this->paginateSorted($query, $request);

        return response()->json(['data' => $departments]);
    }
}
