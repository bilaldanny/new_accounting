<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PermissionController extends Controller
{
    /**
     * Display a listing of the resource.

     *

     * @return Response
     */
    public function index()
    {

        //

    }

    /**
     * Show the form for creating a new resource.

     *

     * @return Response
     */
    public function create()
    {

        //

    }

    /**
     * Store a newly created resource in storage.

     *

     * @return Response
     */
    public function store(Request $request)
    {
        $this->authorizeMenuPermission('/role/:id/permission');

        if (! Menu::assignerCanManageMenu((int) $request->user()->role_id, (int) $request->menuid)) {
            return response()->json(['message' => 'You are not allowed to manage this permission.'], 403);
        }

        $applyScope = function ($query) use ($request) {
            $query->where('role_id', $request->integer('role_id'));

            if ($request->filled('department_id')) {
                $query->where('department_id', $request->integer('department_id'));
            }

            if ($request->filled('company_id')) {
                $query->where('company_id', $request->integer('company_id'));
            }

            if ($request->filled('branch_id')) {
                $query->where('branch_id', $request->integer('branch_id'));
            }
        };

        $permission = Permission::query()->tap($applyScope)->get();

        if (count($permission) === 0) {

            $menu = Menu::where('is_active', '=', 1)->get();

            foreach ($menu as $key => $value) {

                Permission::CreatePermission($request, $value->id, 0);

            }

            $menus = Menu::with('children.children')->find($request->menuid);

            /* Parent Menu */

            if (isset($menus) && $menus !== null) {

                $permission = Permission::where('menu_id', '=', $menus->id)
                    ->tap($applyScope)
                    ->first();

                if ($permission->status === 1) {

                    $status = 0;

                } else {

                    $status = 1;

                }

                if (isset($permission)) {

                    Permission::UpdatePermission($permission, $status);

                }

                /* Child Permission */

                if (count($menus->children) > 0) {

                    foreach ($menus->children as $k => $menu1) {

                        $childpermission = Permission::where('menu_id', '=', $menu1->id)
                            ->tap($applyScope)
                            ->first();

                        if (isset($childpermission)) {

                            Permission::UpdatePermission($childpermission, $status);

                        }

                        /* Sub Child Permission */

                        if (count($menu1->children) > 0) {

                            foreach ($menu1->children as $k2 => $menu2) {

                                $subchildpermission = Permission::where('menu_id', '=', $menu2->id)
                                    ->tap($applyScope)
                                    ->first();

                                if (isset($subchildpermission)) {

                                    Permission::UpdatePermission($subchildpermission, $status);

                                }

                            }

                        }

                        /* Sub Child Permission */

                    }

                }

                /* Child Permission */

            }

            /* Parent Menu */

        } else {

            $status = $request->get('status');

            /* Menu */

            $menus = Menu::with('children.children')->find($request->menuid);

            /* Menu */

            /* Parent Menu */

            if (isset($menus) && $menus !== null) {

                $permission = Permission::where('menu_id', '=', $menus->id)
                    ->tap($applyScope)
                    ->first();

                if (isset($permission)) {

                    Permission::UpdatePermission($permission, $status);

                } else {

                    Permission::CreatePermission($request, $menus->id, $status);

                }

                /* Child Permission */

                if (count($menus->children) > 0) {

                    foreach ($menus->children as $k => $menu1) {

                        $childpermission = Permission::where('menu_id', '=', $menu1->id)
                            ->tap($applyScope)
                            ->first();

                        if (isset($childpermission)) {

                            Permission::UpdatePermission($childpermission, $status);

                        } else {

                            Permission::CreatePermission($request, $menu1->id, $status);

                        }

                        /* Sub Child Permission */

                        if (count($menu1->children) > 0) {

                            foreach ($menu1->children as $k2 => $menu2) {

                                $subchildpermission = Permission::where('menu_id', '=', $menu2->id)
                                    ->tap($applyScope)
                                    ->first();

                                if (isset($subchildpermission)) {

                                    Permission::UpdatePermission($subchildpermission, $status);

                                } else {

                                    Permission::CreatePermission($request, $menu2->id, $status);

                                }

                            }

                        }

                        /* Sub Child Permission */

                    }

                }

                /* Child Permission */

            }

            /* Parent Menu */

        }

        forgetUserPermissionsCache((int) $request->get('role_id'));

        return response()->json(['message' => 'Successfully Saved']);

    }

    /**
     * Display the specified resource.

     *

     * @return Response
     */
    public function show(Permission $permission)
    {

        //

    }

    /**
     * Show the form for editing the specified resource.

     *

     * @return Response
     */
    public function edit(Permission $permission)
    {

        //

    }

    /**
     * Update the specified resource in storage.

     *

     * @return Response
     */
    public function update(Request $request, Permission $permission)
    {

        //

    }

    /**
     * Remove the specified resource from storage.

     *

     * @return Response
     */
    public function destroy(Permission $permission)
    {

        //

    }

    public function fetch(Request $request)
    {

        $query = Permission::query()->where('status', 1);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->integer('role_id'));
        }

        $permission = $query->pluck('menu_id')->toArray();

        return response()->json($permission);

    }
}
