<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\ImportResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'asc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Branch::query()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'phone', 'email', 'address'], 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('country_id'), function ($q) use ($request) {
                $q->where('country_id', $request->country_id);
            })
            ->when($request->filled('state_id'), function ($q) use ($request) {
                $q->where('state_id', $request->state_id);
            })
            ->when($request->filled('city_id'), function ($q) use ($request) {
                $q->where('city_id', $request->city_id);
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $branches = $query->paginate($show_record);

        if ($cur_page > $branches->lastPage()) {
            Paginator::currentPageResolver(function () use ($branches) {
                return $branches->lastPage();
            });
            $branches = $query->paginate($show_record);
        }

        $branches->getCollection()->transform(function (Branch $branch): Branch {
            $branch->company_name = $branch->company?->name;

            return $branch;
        });

        $trash_count = Branch::onlyTrashed()->visibleToCurrentUser()->count();

        $companyId = $request->filled('company_id')
            ? (int) $request->company_id
            : ($request->user()?->company_id ? (int) $request->user()->company_id : null);

        return response()->json([
            'data' => $branches,
            'trash_count' => $trash_count,
            'can_add_branch' => Branch::canAddBranchForCompany($companyId),
        ]);
    }

    public function generateCode(Request $request): JsonResponse
    {
        return response()->json([
            'code' => Branch::nextCode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'bail|required|min:3|max:200',
            'email' => 'bail|required|email',
            'phone' => 'nullable|numeric',
            'code' => ['nullable', 'string', 'regex:/^BR-\d{5}$/i', 'unique:branches,code'],
        ]);

        DB::beginTransaction();
        try {
            Branch::createBranch($request);
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

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string',
        ]);

        Branch::assertImportWithinBranchLimit($request->rows);

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

                if (Branch::upsertFromImport($row) === 'created') {
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

        return ImportResponse::success(
            count($request->rows),
            $created,
            $updated,
            'branch records'
        );
    }

    public function show($id): JsonResponse
    {
        $branch = Branch::query()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->find($id);

        return response()->json($branch);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $request->validate([
            'name' => 'bail|required|min:3|max:200',
            'email' => 'bail|required|email',
            'phone' => 'nullable|numeric',
        ]);

        DB::beginTransaction();
        try {
            Branch::updateBranch($request, $id);
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

    public function destroy($id): JsonResponse
    {
        if (deletepermission()) {
            Branch::deleteBranch($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                Branch::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->delete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = (array) $request->all();
                Branch::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $ids)
                    ->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $branches = Branch::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if (isset($branches)) {
            DB::beginTransaction();
            try {
                foreach ($branches as $branch) {
                    if (isset($request->status)) {
                        $branch->is_active = $request->status;
                    } else {
                        $branch->is_active = ! $branch->is_active;
                    }
                    $branch->save();
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

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                Branch::onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function duplicate(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $branch = Branch::query()->visibleToCurrentUser()->findOrFail($request->id);
            $duplicator = $branch->replicate();
            $duplicator->name = $branch->name.' Copy';
            $duplicator->code = Branch::nextCode();
            $duplicator->is_default = false;
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    public function fetch(Request $request): JsonResponse
    {
        if ($request->company_id === null || $request->company_id === 'undefined' || $request->company_id === '') {
            return response()->json([]);
        }

        $branches = Branch::query()
            ->where('is_active', true)
            ->where('company_id', $request->company_id)
            ->select('branches.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($branches);
    }

    public function trash(Request $request): JsonResponse
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'asc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Branch::onlyTrashed()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'phone', 'email', 'address'], 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('country_id'), function ($q) use ($request) {
                $q->where('country_id', $request->country_id);
            })
            ->when($request->filled('state_id'), function ($q) use ($request) {
                $q->where('state_id', $request->state_id);
            })
            ->when($request->filled('city_id'), function ($q) use ($request) {
                $q->where('city_id', $request->city_id);
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $branches = $query->paginate($show_record);

        if ($cur_page > $branches->lastPage()) {
            Paginator::currentPageResolver(function () use ($branches) {
                return $branches->lastPage();
            });
            $branches = $query->paginate($show_record);
        }

        $branches->getCollection()->transform(function (Branch $branch): Branch {
            $branch->company_name = $branch->company?->name;

            return $branch;
        });

        return response()->json(['data' => $branches]);
    }
}
