<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RoleController extends Controller
{
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
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

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
            })
            ->orderBy($sort_by, $sort_type);

        // Resolve current page before pagination
        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $roles = $query->paginate($show_record);

        // If requested page is greater than last page → set to last page
        if ($cur_page > $roles->lastPage()) {
            Paginator::currentPageResolver(function () use ($roles) {
                return $roles->lastPage();
            });
            $roles = $query->paginate($show_record);
        }

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

            return response()->json(['errormessage' => $e]);
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

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function import(Request $request)
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string',
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

                if (Role::upsertFromImport($row) === 'created') {
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

        return response()->json([
            'message' => "Successfully imported {$created} new and updated {$updated} role records.",
        ]);
    }

    public function destroy($id)
    {
        if (deletepermission()) {
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
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Role::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Role::whereIn('id', $ids)->delete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        } else {
            return response()->json('406');
        }
    }

    /* Bulk Record Permanently Delete */
    public function bulk_delete_per(Request $request)
    {
        if (deletepermission()) {

            DB::beginTransaction();
            try {
                $ids = Role::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                Role::whereIn('id', $ids)->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        } else {
            return response()->json('406');
        }
    }

    /* Update Status */
    public function updatestatus(Request $request)
    {
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

                return response()->json(['errormessage' => $e]);
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
        if (deletepermission()) {
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

                return response()->json(['errormessage' => $e]);
            }
        } else {
            return response()->json('406');
        }
    }

    public function duplicate(Request $request)
    {
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

            return response()->json(['errormessage' => $e]);
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
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

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
            })
            ->orderBy($sort_by, $sort_type);

        // Resolve current page before pagination
        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $roles = $query->paginate($show_record);

        // If requested page is greater than last page → set to last page
        if ($cur_page > $roles->lastPage()) {
            Paginator::currentPageResolver(function () use ($roles) {
                return $roles->lastPage();
            });
            $roles = $query->paginate($show_record);
        }

        return response()->json(['data' => $roles]);
    }
}
