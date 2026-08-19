<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BankController extends Controller
{
    /**
     * @return array<string, string>
     */
    protected function bankFormRules(): array
    {
        return [
            'company_id' => 'bail|required',
            'branch_id' => 'bail|required',
            'bank_name' => 'bail|required|string|max:255',
            'first_name' => 'bail|required|string|max:255',
            'mobile' => 'bail|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'landmark' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:255',
            'opening_balance' => 'nullable|numeric',
            'alternate_no' => 'nullable|string|max:255',
            'landline' => 'nullable|string|max:255',
            'type' => 'nullable|in:local,export',
        ];
    }

    public function generateCode(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'bail|required|integer',
            'branch_id' => 'nullable|integer',
        ]);

        $companyId = (int) $request->company_id;
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;

        $user = $request->user();

        if ($user !== null && ! $user->hasRole('superadmin') && (int) $user->company_id !== $companyId) {
            abort(403);
        }

        if ($user !== null && $branchId !== null && ! $user->hasRole('superadmin') && ! $user->hasRole('companyadmin')) {
            if ((int) $user->branch_id !== $branchId) {
                abort(403);
            }
        }

        return response()->json([
            'code' => Bank::generateCode($companyId, $branchId),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $sortBy = $request->sort_by ?? 'created_at';
        $sortType = $request->sort_type ?? 'desc';
        $showRecord = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $curPage = $request->cur_page ?? 1;

        $query = Bank::query()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'city:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny([
                        'code',
                        'bank_name',
                        'first_name',
                        'middle_name',
                        'last_name',
                        'mobile',
                        'email',
                    ], 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $banks = $query->paginate($showRecord);

        if ($curPage > $banks->lastPage()) {
            Paginator::currentPageResolver(function () use ($banks) {
                return $banks->lastPage();
            });
            $banks = $query->paginate($showRecord);
        }

        $banks->getCollection()->transform(function (Bank $bank) {
            $bank->company_name = $bank->company?->name;
            $bank->branch_name = $bank->branch?->name;
            $bank->city_name = $bank->city?->name;
            $bank->display_name = $bank->display_name;
            $bank->op_bal = 0;
            $bank->total_due = 0;
            $bank->return_due = 0;
            $bank->account_linked = filled($bank->gl_id);

            return $bank;
        });

        $trashCount = Bank::onlyTrashed()
            ->visibleToCurrentUser()
            ->count();

        return response()->json(['data' => $banks, 'trash_count' => $trashCount]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate($this->bankFormRules());

        DB::beginTransaction();
        try {
            Bank::createBank($request);
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

    public function show(int $id): JsonResponse
    {
        $bank = Bank::findVisibleBank($id);

        if ($bank === null) {
            abort(404);
        }

        $bank->load(['country', 'state', 'city', 'company', 'branch']);

        return response()->json($bank);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate($this->bankFormRules());

        DB::beginTransaction();
        try {
            Bank::updateBank($request, $id);
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

    public function destroy(int $id): JsonResponse
    {
        if (deletepermission()) {
            Bank::deleteBank($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function linkCoa(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'opening_balance' => 'nullable|numeric',
        ]);

        DB::beginTransaction();
        try {
            $bank = Bank::linkBankToChartOfAccount(
                $id,
                (float) ($request->opening_balance ?? 0),
            );
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json([
            'message' => 'Successfully Linked',
            'gl_id' => $bank->gl_id,
            'link_account' => $bank->link_account,
        ]);
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Bank::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Bank::whereIn('id', $ids)->delete();
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
                $ids = Bank::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                Bank::whereIn('id', $ids)->forceDelete();
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
        $banks = Bank::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if ($banks->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($banks as $bank) {
                if (isset($request->status)) {
                    $bank->active = $request->status;
                } else {
                    $bank->active = ! $bank->active;
                }
                $bank->save();
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Bank::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Bank::whereIn('id', $ids)->restore();
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
            $bank = Bank::findVisibleBank((int) $request->id);

            if ($bank === null) {
                abort(404);
            }

            $duplicator = $bank->replicate();
            $duplicator->code = Bank::generateCode(
                (int) $bank->company_id,
                (int) $bank->branch_id,
            );
            $duplicator->bank_name = $this->duplicateBankName(
                $bank->bank_name,
                (int) $bank->company_id,
                (int) $bank->branch_id,
            );
            $duplicator->gl_id = null;
            $duplicator->link_account = false;
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    private function duplicateBankName(string $name, int $companyId, int $branchId): string
    {
        $candidate = $name.' Copy';
        $suffix = 1;

        while (Bank::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('bank_name', $candidate)
            ->exists()) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
    }

    public function fetch(Request $request): JsonResponse
    {
        $banks = Bank::query()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->select('banks.*', 'bank_name as text')
            ->orderBy('bank_name')
            ->get();

        return response()->json($banks);
    }

    public function trash(Request $request): JsonResponse
    {
        $sortBy = $request->sort_by ?? 'created_at';
        $sortType = $request->sort_type ?? 'desc';
        $showRecord = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $curPage = $request->cur_page ?? 1;

        $query = Bank::onlyTrashed()
            ->visibleToCurrentUser()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny([
                        'code',
                        'bank_name',
                        'first_name',
                        'last_name',
                        'mobile',
                    ], 'like', "%{$search}%");
                });
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $banks = $query->paginate($showRecord);

        if ($curPage > $banks->lastPage()) {
            Paginator::currentPageResolver(function () use ($banks) {
                return $banks->lastPage();
            });
            $banks = $query->paginate($showRecord);
        }

        return response()->json(['data' => $banks]);
    }
}
