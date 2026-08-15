<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

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
            })
            ->orderBy($sort_by, $sort_type);

        // Resolve current page before pagination
        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $menus = $query->paginate($show_record);

        // If requested page is greater than last page → set to last page
        if ($cur_page > $menus->lastPage()) {
            Paginator::currentPageResolver(function () use ($menus) {
                return $menus->lastPage();
            });
            $menus = $query->paginate($show_record);
        }

        $trash_count = Menu::onlyTrashed()->count();

        return response()->json(['data' => $menus, 'trash_count' => $trash_count]);

    }

    public function store(Request $request)
    {
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

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show($id)
    {
        $menu = Menu::find($id);

        return response()->json($menu);
    }

    public function update(Request $request, $id)
    {
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

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id)
    {
        if (deletepermission()) {
            Menu::DeleteMenu($id);

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
                // Perform the deletion
                Menu::whereIn('id', $request->all())->delete();
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
                // Perform the deletion
                $ids = (array) $request->all();
                Menu::whereIn('id', $ids)->forceDelete();
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

                return response()->json(['errormessage' => $e]);
            }
        } else {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function fetchmenus()
    {
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

    /* Bulk Record Permanently Delete */
    public function restore_records(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                // Perform the deletion
                Menu::whereIn('id', $request->all())->restore();
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

    public function import(Request $request)
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string',
            'rows.*.type' => 'bail|required',
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

                if (Menu::upsertFromImport($row) === 'created') {
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
            'message' => "Successfully imported {$created} new and updated {$updated} menu records.",
        ]);
    }

    public function duplicate(Request $request)
    {
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

            return response()->json(['errormessage' => $e]);
        }
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
            })
            ->orderBy($sort_by, $sort_type);

        // Resolve current page before pagination
        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $menus = $query->paginate($show_record);

        // If requested page is greater than last page → set to last page
        if ($cur_page > $menus->lastPage()) {
            Paginator::currentPageResolver(function () use ($menus) {
                return $menus->lastPage();
            });
            $menus = $query->paginate($show_record);
        }

        return response()->json(['data' => $menus]);
    }
}
