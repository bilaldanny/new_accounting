<?php



namespace App\Http\Controllers;



use App\Models\Permission;

use App\Models\Menu;

use Illuminate\Http\Request;

use DB;



class PermissionController extends Controller

{

    /**

     * Display a listing of the resource.

     *

     * @return \Illuminate\Http\Response

     */

    public function index()

    {

        //

    }



    /**

     * Show the form for creating a new resource.

     *

     * @return \Illuminate\Http\Response

     */

    public function create()

    {

        //

    }



    /**

     * Store a newly created resource in storage.

     *

     * @param  \Illuminate\Http\Request  $request

     * @return \Illuminate\Http\Response

     */

    public function store(Request $request)

    {

        $raw = "role_id = ".$request->get('role_id');



        $permission = Permission::whereRaw($raw)->get();



        if(count($permission) === 0){

            $menu = Menu::where('is_active','=',1)->get();



            foreach ($menu as $key => $value) {

                Permission::CreatePermission($request,$value->id,0);

            }



            $menus = Menu::with('children.children')->find($request->menuid);



            /* Parent Menu */

                if(isset($menus) && $menus !== null){

                    $permission = Permission::where('menu_id','=',$menus->id)

                                        ->whereRaw($raw)

                                        ->first();



                    if($permission->status === 1){

                        $status = 0;

                    }else{

                        $status = 1;

                    }



                    if(isset($permission)){

                        Permission::UpdatePermission($permission,$status);

                    }



                    /* Child Permission */

                        if(count($menus->children) > 0){

                            foreach($menus->children as $k => $menu1){

                                $childpermission = Permission::where('menu_id','=',$menu1->id)

                                                    ->whereRaw($raw)

                                                    ->first();



                                if(isset($childpermission)){

                                    Permission::UpdatePermission($childpermission,$status);

                                }



                                /* Sub Child Permission */

                                    if(count($menu1->children) > 0){

                                        foreach($menu1->children as $k2 => $menu2){

                                            $subchildpermission = Permission::where('menu_id','=',$menu2->id)

                                                                ->whereRaw($raw)

                                                                ->first();

                                            if(isset($subchildpermission)){

                                                Permission::UpdatePermission($subchildpermission,$status);

                                            }

                                        }

                                    }

                                /* Sub Child Permission */

                            }

                        }

                    /* Child Permission */

                    

                }

            /* Parent Menu */

        }else{

            $status = $request->get('status');



            /* Menu */

                $menus = Menu::with('children.children')->find($request->menuid);

            /* Menu */



            /* Parent Menu */

                if(isset($menus) && $menus !== null){



                    $permission = Permission::where('menu_id','=',$menus->id)

                                        ->whereRaw($raw)

                                        ->first();



                    if(isset($permission)){

                        Permission::UpdatePermission($permission,$status);

                    }else{

                        Permission::CreatePermission($request,$menus->id,$status);

                    }



                    /* Child Permission */

                        if(count($menus->children) > 0){

                            foreach($menus->children as $k => $menu1){

                                $childpermission = Permission::where('menu_id','=',$menu1->id)

                                                    ->whereRaw($raw)

                                                    ->first();



                                if(isset($childpermission)){

                                    Permission::UpdatePermission($childpermission,$status);

                                }else{

                                    Permission::CreatePermission($request,$menu1->id,$status);

                                }



                                /* Sub Child Permission */

                                    if(count($menu1->children) > 0){

                                        foreach($menu1->children as $k2 => $menu2){

                                            $subchildpermission = Permission::where('menu_id','=',$menu2->id)

                                                                ->whereRaw($raw)

                                                                ->first();

                                            if(isset($subchildpermission)){

                                                Permission::UpdatePermission($subchildpermission,$status);

                                            }else{

                                                Permission::CreatePermission($request,$menu2->id,$status);

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

        return response()->json(['message'=>'Successfully Saved']);

    }



    /**

     * Display the specified resource.

     *

     * @param  \App\Models\Permission  $permission

     * @return \Illuminate\Http\Response

     */

    public function show(Permission $permission)

    {

        //

    }



    /**

     * Show the form for editing the specified resource.

     *

     * @param  \App\Models\Permission  $permission

     * @return \Illuminate\Http\Response

     */

    public function edit(Permission $permission)

    {

        //

    }



    /**

     * Update the specified resource in storage.

     *

     * @param  \Illuminate\Http\Request  $request

     * @param  \App\Models\Permission  $permission

     * @return \Illuminate\Http\Response

     */

    public function update(Request $request, Permission $permission)

    {

        //

    }



    /**

     * Remove the specified resource from storage.

     *

     * @param  \App\Models\Permission  $permission

     * @return \Illuminate\Http\Response

     */

    public function destroy(Permission $permission)

    {

        //

    }



    public function fetch(Request $request)

    {

        $raw = 'status = 1';



        if($request->role_id != null){

            $raw .= ' and role_id = '.$request->role_id;

        }



        $permission = Permission::whereRaw($raw)->pluck('menu_id')->toArray();



        return response()->json($permission);

    }

}