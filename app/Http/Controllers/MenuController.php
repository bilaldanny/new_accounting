<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesBulkImport;
use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class MenuController extends Controller
{
    use HandlesBulkImport, HandlesIndexAndBulkDelete;

    public function index(Request $request)
    {
        $this->authorizeSuperadmin($request);
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        // Base Query
        $query = Menu::query()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'route_name', 'route_path', 'sort_order'], 'like', "%{$search}%");
                });
            });

        $menus = $this->paginateSorted($query, $request);

        $trash_count = Menu::onlyTrashed()->count();
        $active_count = Menu::where('is_active', 1)->count();
        $inactive_count = Menu::where('is_active', 0)->count();

        return response()->json([
            'data' => $menus,
            'trash_count' => $trash_count,
            'active_count' => $active_count,
            'inactive_count' => $inactive_count,
        ]);

    }

    public function store(Request $request)
    {
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/menu/add');
        $request->validate([
            'name' => 'bail|required',
            'type' => 'bail|required',
        ]);

        DB::beginTransaction();
        try {
            Menu::CreateMenu($request);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeSuperadmin($request);
        $menu = Menu::find($id);

        return response()->json($menu);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/menu/:id/edit');
        $request->validate([
            'name' => 'bail|required',
        ]);

        DB::beginTransaction();
        try {
            // New Data
            $data = Menu::UpdateMenu($request, $id);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeSuperadmin($request);
        if (deletepermission('/menu/delete')) {
            Menu::DeleteMenu($id);

            return response()->json(['message' => 'Successfully Deleted']);
        } else {
            return response()->json('406');
        }
    }

    /* Bulk Record Delete */
    public function bulk_delete(Request $request)
    {
        $this->authorizeSuperadmin($request);

        return $this->guardedBulkAction('/menu/delete', 'Successfully Deleted', function () use ($request) {
            // Perform the deletion
            Menu::whereIn('id', $request->all())->delete();
        });
    }

    /* Bulk Record Permanently Delete */
    public function bulk_delete_per(Request $request)
    {
        $this->authorizeSuperadmin($request);

        return $this->guardedBulkAction('/menu/delete', 'Successfully Deleted', function () use ($request) {
            // Perform the deletion
            $ids = (array) $request->all();
            Menu::whereIn('id', $ids)->forceDelete();
        });
    }

    /* Update Status */
    public function updatestatus(Request $request)
    {
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/menu/:id/edit');
        $menus = Menu::whereIn('id', $request->ids)->get();

        if (isset($menus)) {
            DB::beginTransaction();
            try {
                foreach ($menus as $k => $menu) {
                    if (isset($request->status)) {
                        $menu->is_active = $request->status;
                    } else {
                        if ($menu->is_active == false) {
                            $menu->is_active = 'true';
                        } else {
                            $menu->is_active = 'false';
                        }
                    }
                    $menu->save();
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

    /* Update Sort Order (inline row stepper) */
    public function updateSortOrder(Request $request, $id)
    {
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/menu/:id/edit');
        $request->validate([
            'sort_order' => 'bail|required|integer|min:0',
        ]);

        $menu = Menu::findOrFail($id);
        $menu->sort_order = $request->sort_order;
        $menu->save();

        return response()->json(['message' => 'Successfully Saved', 'sort_order' => $menu->sort_order]);
    }

    public function fetchmenus(Request $request)
    {
        $this->authorizeSuperadmin($request);
        $menu = Menu::with('children.children')
            ->where('is_active', '=', 1)
            ->where('is_hidden', '=', 0)
            ->select('name as text', 'menus.*')
            ->orderBy('sort_order', 'ASC')
            ->get();

        return response()->json($menu);
    }

    public function fetchpermenus(Request $request)
    {
        $roleId = (int) $request->user()->role_id;

        return response()->json(Menu::permissionMenusForAssigner($roleId));
    }

    public function getpermission(Request $request)
    {
        return response()->json($request->user()->getPermissionPaths());
    }

    /* Bulk Record Permanently Delete */
    public function restore_records(Request $request)
    {
        $this->authorizeSuperadmin($request);
        if (deletepermission('/menu/restore')) {
            DB::beginTransaction();
            try {
                Menu::whereIn('id', $request->all())->restore();
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

    public function import(Request $request)
    {
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/menu/import');
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string',
            'rows.*.type' => 'bail|required',
        ]);

        return $this->importRows($request, Menu::class, 'menu records');
    }

    public function duplicate(Request $request)
    {
        $this->authorizeSuperadmin($request);
        $this->authorizeMenuPermission('/menu/add');
        DB::beginTransaction();
        try {
            $menu = Menu::find($request->id);
            $duplicator = $menu->replicate();
            $duplicator->name = $menu->name.' Copy';
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }
    }

    public function trash(Request $request)
    {
        $this->authorizeSuperadmin($request);
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        // Base Query
        $query = Menu::onlyTrashed()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'route_name', 'route_path', 'sort_order'], 'like', "%{$search}%");
                });
            });

        $menus = $this->paginateSorted($query, $request);

        return response()->json(['data' => $menus]);
    }

    private function isSuperadmin(Request $request): bool
    {
        $user = $request->user();
        $roleName = strtolower(str_replace(' ', '', (string) ($user?->rolename ?? '')));

        return $roleName === 'superadmin';
    }

    private function authorizeSuperadmin(Request $request): void
    {
        if (! $this->isSuperadmin($request)) {
            abort(403);
        }
    }
}
