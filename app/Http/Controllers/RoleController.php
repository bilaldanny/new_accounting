<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesBulkImport;
use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RoleController extends Controller
{
    use HandlesBulkImport, HandlesIndexAndBulkDelete;

    /**
     * @return array<string, string>
     */
    protected function roleFormRules(): array
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

        // Base Query
        $query = Role::query()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
            ])
            ->where('is_admin', '=', 0)
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'sort_order'], 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            });

        $roles = $this->paginateSorted($query, $request);

        $roles->getCollection()->transform(function (Role $role) {
            $role->company_name = $role->company?->name;
            $role->branch_name = $role->branch?->name;

            return $role;
        });

        $trash_count = Role::onlyTrashed()->count();

        return response()->json(['data' => $roles, 'trash_count' => $trash_count]);

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
            'name_taken' => Role::nameExists(
                $request->string('name')->toString(),
                $request->integer('except_id') ?: null,
                Role::resolveScopedId($request->company_id),
                Role::resolveScopedId($request->branch_id),
            ),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeMenuPermission('/role/add');

        $request->validate($this->roleFormRules());

        DB::beginTransaction();
        try {
            Role::CreateRole($request);
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

    public function show($id)
    {
        $role = Role::findVisibleToCurrentUser((int) $id);

        if ($role === null) {
            abort(404);
        }

        return response()->json($role);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeMenuPermission('/role/:id/edit');

        $request->validate($this->roleFormRules());

        DB::beginTransaction();
        try {
            Role::UpdateRole($request, $id);
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
        $this->authorizeMenuPermission('/role/import');

        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string',
        ]);

        return $this->importRows($request, Role::class, 'role records');
    }

    public function destroy($id)
    {
        if (deletepermission('/role/delete')) {
            $role = Role::findVisibleToCurrentUser((int) $id);

            if ($role === null) {
                abort(404);
            }

            Role::DeleteRole($id);

            return response()->json(['message' => 'Successfully Deleted']);
        } else {
            return response()->json('406');
        }
    }

    /* Bulk Record Delete */
    public function bulk_delete(Request $request)
    {
        return $this->guardedBulkAction('/role/delete', 'Successfully Deleted', function () use ($request) {
            $ids = Role::query()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->pluck('id');

            Role::whereIn('id', $ids)->delete();
        });
    }

    /* Bulk Record Permanently Delete */
    public function bulk_delete_per(Request $request)
    {
        return $this->guardedBulkAction('/role/delete', 'Successfully Deleted', function () use ($request) {
            $ids = Role::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->pluck('id');

            Role::whereIn('id', $ids)->forceDelete();
        });
    }

    /* Update Status */
    public function updatestatus(Request $request)
    {
        $this->authorizeMenuPermission('/role/:id/edit');
        $roles = Role::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if (isset($roles)) {
            DB::beginTransaction();
            try {
                foreach ($roles as $k => $role) {
                    if (isset($request->status)) {
                        $role->is_active = $request->status;
                    } else {
                        if ($role->is_active == false) {
                            $role->is_active = 'true';
                        } else {
                            $role->is_active = 'false';
                        }
                    }
                    $role->save();
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

    public function fetchroles()
    {
        $role = Role::query()
            ->visibleToCurrentUser()
            ->where('is_active', '=', 1)
            ->where('is_admin', '=', 0)
            ->select('name as text', 'roles.*')
            ->get();

        return response()->json($role);
    }

    /* Bulk Record Permanently Delete */
    public function restore_records(Request $request)
    {
        if (deletepermission('/role/restore')) {
            DB::beginTransaction();
            try {
                $ids = Role::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Role::whereIn('id', $ids)->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        } else {
            return response()->json('406');
        }
    }

    public function duplicate(Request $request)
    {
        $this->authorizeMenuPermission('/role/add');

        DB::beginTransaction();
        try {
            $role = Role::findVisibleToCurrentUser((int) $request->id);

            if ($role === null) {
                abort(404);
            }

            $duplicator = $role->replicate();
            $duplicator->name = $this->duplicateRoleName($role->name, $role->company_id, $role->branch_id);
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }
    }

    private function duplicateRoleName(string $name, mixed $companyId = null, mixed $branchId = null): string
    {
        $companyId = Role::resolveScopedId($companyId);
        $branchId = Role::resolveScopedId($branchId);
        $candidate = $name.' Copy';
        $suffix = 1;

        while (Role::nameExists($candidate, null, $companyId, $branchId)) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
    }

    public function trash(Request $request)
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        // Base Query
        $query = Role::onlyTrashed()
            ->visibleToCurrentUser()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'sort_order'], 'like', "%{$search}%");
                });
            });

        $roles = $this->paginateSorted($query, $request);

        return response()->json(['data' => $roles]);
    }
}
