<?php

namespace App\Http\Controllers;

use App\Models\CustomerGroup;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomerGroupController extends Controller
{
    /**
     * @return array<string, string>
     */
    protected function customerGroupFormRules(): array
    {
        return [
            'name' => 'bail|required|string|max:255',
            'price_calculation_type' => 'bail|required|in:percentage',
            'calculation_percentage' => 'bail|required|numeric',
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

        $query = CustomerGroup::query()
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
                    $sub->whereAny(['name', 'price_calculation_type'], 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $customerGroups = $query->paginate($show_record);

        if ($cur_page > $customerGroups->lastPage()) {
            Paginator::currentPageResolver(function () use ($customerGroups) {
                return $customerGroups->lastPage();
            });
            $customerGroups = $query->paginate($show_record);
        }

        $customerGroups->getCollection()->transform(function (CustomerGroup $customerGroup) {
            $customerGroup->company_name = $customerGroup->company?->name;
            $customerGroup->branch_name = $customerGroup->branch?->name;

            return $customerGroup;
        });

        $trash_count = CustomerGroup::onlyTrashed()->count();

        return response()->json(['data' => $customerGroups, 'trash_count' => $trash_count]);
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
            'name_taken' => CustomerGroup::nameExists(
                $request->string('name')->toString(),
                $request->integer('except_id') ?: null,
                CustomerGroup::resolveScopedId($request->company_id),
                CustomerGroup::resolveScopedId($request->branch_id),
            ),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate($this->customerGroupFormRules());

        DB::beginTransaction();
        try {
            CustomerGroup::createCustomerGroup($request);
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
        $customerGroup = CustomerGroup::findVisibleToCurrentUser((int) $id);

        if ($customerGroup === null) {
            abort(404);
        }

        return response()->json($customerGroup);
    }

    public function update(Request $request, $id)
    {
        $request->validate($this->customerGroupFormRules());

        DB::beginTransaction();
        try {
            CustomerGroup::updateCustomerGroup($request, (int) $id);
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

    public function destroy($id)
    {
        if (deletepermission()) {
            $customerGroup = CustomerGroup::findVisibleToCurrentUser((int) $id);

            if ($customerGroup === null) {
                abort(404);
            }

            CustomerGroup::deleteCustomerGroup((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = CustomerGroup::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                CustomerGroup::whereIn('id', $ids)->delete();
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
                $ids = CustomerGroup::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                CustomerGroup::whereIn('id', $ids)->forceDelete();
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
        $customerGroups = CustomerGroup::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if (isset($customerGroups)) {
            DB::beginTransaction();
            try {
                foreach ($customerGroups as $customerGroup) {
                    if (isset($request->status)) {
                        $customerGroup->active = $request->status;
                    } else {
                        if ($customerGroup->active == false) {
                            $customerGroup->active = 'true';
                        } else {
                            $customerGroup->active = 'false';
                        }
                    }
                    $customerGroup->save();
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
                $ids = CustomerGroup::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                CustomerGroup::whereIn('id', $ids)->restore();
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
            $customerGroup = CustomerGroup::findVisibleToCurrentUser((int) $request->id);

            if ($customerGroup === null) {
                abort(404);
            }

            $duplicator = $customerGroup->replicate();
            $duplicator->name = $this->duplicateCustomerGroupName(
                $customerGroup->name,
                $customerGroup->company_id,
                $customerGroup->branch_id,
            );
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    private function duplicateCustomerGroupName(string $name, mixed $companyId = null, mixed $branchId = null): string
    {
        $companyId = CustomerGroup::resolveScopedId($companyId);
        $branchId = CustomerGroup::resolveScopedId($branchId);
        $candidate = $name.' Copy';
        $suffix = 1;

        while (CustomerGroup::nameExists($candidate, null, $companyId, $branchId)) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
    }

    public function fetch(Request $request)
    {
        $customerGroups = CustomerGroup::query()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->select('customer_groups.*', 'name as text')
            ->get();

        return response()->json($customerGroups);
    }

    public function trash(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = CustomerGroup::onlyTrashed()
            ->visibleToCurrentUser()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'price_calculation_type'], 'like', "%{$search}%");
                });
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $customerGroups = $query->paginate($show_record);

        if ($cur_page > $customerGroups->lastPage()) {
            Paginator::currentPageResolver(function () use ($customerGroups) {
                return $customerGroups->lastPage();
            });
            $customerGroups = $query->paginate($show_record);
        }

        return response()->json(['data' => $customerGroups]);
    }
}
