<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Throwable;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Department::query()
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
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $departments = $query->paginate($show_record);

        if ($cur_page > $departments->lastPage()) {
            Paginator::currentPageResolver(function () use ($departments) {
                return $departments->lastPage();
            });
            $departments = $query->paginate($show_record);
        }

        $trash_count = Department::onlyTrashed()->count();

        return response()->json(['data' => $departments, 'trash_count' => $trash_count]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'bail|required',
        ]);

        DB::beginTransaction();
        try {
            Department::createDepartment($request);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show($id)
    {
        $department = Department::find($id);

        return response()->json($department);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'bail|required',
        ]);

        DB::beginTransaction();
        try {
            Department::updateDepartment($request, $id);
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
            Department::deleteDepartment($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                Department::whereIn('id', $request->all())->delete();
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
                $ids = (array) $request->all();
                Department::whereIn('id', $ids)->forceDelete();
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
        $departments = Department::whereIn('id', $request->ids)->get();

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
                Department::whereIn('id', $request->all())->restore();
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
            $department = Department::find($request->id);
            $duplicator = $department->replicate();
            $duplicator->name = $department->name.' Copy';
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    public function fetch(Request $request)
    {
        $departments = Department::query()
            ->where('active', '=', 1)
            ->where('company_id', '=', $request->company_id)
            ->where('branch_id', '=', $request->branch_id)
            ->select('departments.*', 'name as text')
            ->get();

        return response()->json($departments);
    }

    public function trash(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Department::onlyTrashed()
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
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $departments = $query->paginate($show_record);

        if ($cur_page > $departments->lastPage()) {
            Paginator::currentPageResolver(function () use ($departments) {
                return $departments->lastPage();
            });
            $departments = $query->paginate($show_record);
        }

        return response()->json(['data' => $departments]);
    }
}
