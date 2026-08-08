<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Throwable;

class RoleController extends Controller
{
    public function index(Request $request){
        $sort_by     = $request->sort_by ?? 'created_at';
        $sort_type   = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status      = $request->status ?? 'all';
        $search      = $request->search ?? '';
        $cur_page    = $request->cur_page ?? 1;

        // Base Query
        $query = Role::query()
                    ->where('is_admin','=',0)
                    ->when($status !== 'all', function ($q) use ($status) {
                        $q->where('is_active', $status);
                    })
                    ->when($status === 'all', function ($q) {
                        $q->whereIn('is_active', [0, 1]);
                    })
                    ->when($search, function ($q) use ($search) {
                        $q->where(function ($sub) use ($search) {
                            $sub->whereAny(['name','sort_order'], 'like', "%{$search}%");
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

        $trash_count = Role::onlyTrashed()->count();

        return response()->json(['data' => $roles, 'trash_count' => $trash_count]);

    }

    public function store(Request $request){
        $request->validate([
            'name' => 'bail|required'
        ]);

        DB::beginTransaction();
        try{
            Role::CreateRole($request);
            DB::commit();
        }catch (Throwable $e){
            DB::rollBack();
            return response()->json(['errormessage'=>$e]);
        }

        return response()->json(['message'=>'Successfully Saved']);
    }

    public function show($id){
        $role = Role::find($id);
        return response()->json($role);
    }

    public function update(Request $request, $id){
        $request->validate([
            'name' => 'bail|required'
        ]);

        DB::beginTransaction();
        try{
            //New Data
            $data = Role::UpdateRole($request, $id);
            DB::commit();
        }catch (Throwable $e){
            DB::rollBack();
            return response()->json(['errormessage'=>$e]);
        }
        return response()->json(['message'=>'Successfully Saved']);
    }

    public function destroy($id){
        if(deletepermission()){
            Role::DeleteRole($id);
            return response()->json(['message'=>'Successfully Deleted']);
        }else{
            return response()->json('406');
        }
    }

    /* Bulk Record Delete */
    public function bulk_delete(Request $request){
        if(deletepermission()){
            DB::beginTransaction();
            try{
                // Perform the deletion
                Role::whereIn('id', $request->all())->delete();
                DB::commit();
                return response()->json(['message'=>'Successfully Deleted']);
            }catch (Throwable $e){
                DB::rollBack();
                return response()->json(['errormessage'=>$e]);
            }
        }else{
            return response()->json('406');
        }
    }

    /* Bulk Record Permanently Delete */
    public function bulk_delete_per(Request $request){
        if(deletepermission()){

            DB::beginTransaction();
            try{
                // Perform the deletion
                $ids = (array) $request->all();
                Role::whereIn('id', $ids)->forceDelete();
                DB::commit();
                return response()->json(['message'=>'Successfully Deleted']);
            }catch (Throwable $e){
                DB::rollBack();
                return response()->json(['errormessage'=>$e]);
            }
        }else{
            return response()->json('406');
        }
    }

    /* Update Status */
    public function updatestatus(Request $request){
        $roles = Role::whereIn('id',$request->ids)->get();

        if(isset($roles)){
            DB::beginTransaction();
            try{
                foreach($roles as $k => $role){
                    if(isset($request->status)){
                        $role->is_active = $request->status;
                    }else{
                        if($role->is_active == false){
                            $role->is_active = "true";
                        }else{
                            $role->is_active = "false";
                        }
                    }
                    $role->save();
                }
                DB::commit();
            }catch (Throwable $e){
                DB::rollBack();
                return response()->json(['errormessage'=>$e]);
            }
        }else{
            return response()->json(['errormessage'=>'Something went wrong']);
        }
        return response()->json(['message'=>'Successfully Saved']);
    }

    public function fetchroles(){
        $role = Role::where('is_active','=',1)
                    ->where('is_admin','=',0)
                    ->select('name as text','roles.*')
                    ->get();

        return response()->json($role);
    }

    /* Bulk Record Permanently Delete */
    public function restore_records(Request $request){
        if(deletepermission()){
            DB::beginTransaction();
            try{
                // Perform the deletion
                Role::whereIn('id', $request->all())->restore();
                DB::commit();
                return response()->json(['message'=>'Successfully Restored']);
            }catch (Throwable $e){
                DB::rollBack();
                return response()->json(['errormessage'=>$e]);
            }
        }else{
            return response()->json('406');
        }
    }

    public function duplicate(Request $request){
        DB::beginTransaction();
        try{
            $role = Role::find($request->id);
            $duplicator = $role->replicate();
            $duplicator->name = $role->name.' Copy';
            $duplicator->save();
            DB::commit();

            return response()->json(['message'=>'Successfully Duplicated']);
        }catch (Throwable $e){
            DB::rollBack();
            return response()->json(['errormessage'=>$e]);
        }
    }

    public function trash(Request $request)
    {
        $sort_by     = $request->sort_by ?? 'created_at';
        $sort_type   = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status      = $request->status ?? 'all';
        $search      = $request->search ?? '';
        $cur_page    = $request->cur_page ?? 1;

        // Base Query
        $query = Role::onlyTrashed()
                    ->when($status !== 'all', function ($q) use ($status) {
                        $q->where('is_active', $status);
                    })
                    ->when($status === 'all', function ($q) {
                        $q->whereIn('is_active', [0, 1]);
                    })
                    ->when($search, function ($q) use ($search) {
                        $q->where(function ($sub) use ($search) {
                            $sub->whereAny(['name','sort_order'], 'like', "%{$search}%");
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
