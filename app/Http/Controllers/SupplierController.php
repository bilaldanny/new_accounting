<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupplierController extends Controller
{
    /**
     * @return array<string, string>
     */
    protected function supplierFormRules(): array
    {
        return [
            'company_id' => 'bail|required',
            'branch_id' => 'bail|required',
            'business_name' => 'bail|required|string|max:255',
            'first_name' => 'bail|required|string|max:255',
            'mobile' => 'bail|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'bail|required|string|max:1000',
            'ntn_number' => 'bail|required|string|max:255',
            'alternate_no' => 'nullable|string|max:255',
            'landline' => 'nullable|string|max:255',
            'pay_term' => 'nullable|numeric',
            'user_type' => 'nullable|in:supplier,both',
            'type' => 'nullable|in:local,export',
            'pay_type' => 'nullable|in:month,day,year',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $sortBy = $request->sort_by ?? 'created_at';
        $sortType = $request->sort_type ?? 'desc';
        $showRecord = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $curPage = $request->cur_page ?? 1;

        $query = Contact::query()
            ->suppliers()
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
                        'business_name',
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

        $suppliers = $query->paginate($showRecord);

        if ($curPage > $suppliers->lastPage()) {
            Paginator::currentPageResolver(function () use ($suppliers) {
                return $suppliers->lastPage();
            });
            $suppliers = $query->paginate($showRecord);
        }

        $suppliers->getCollection()->transform(function (Contact $supplier) {
            $supplier->company_name = $supplier->company?->name;
            $supplier->branch_name = $supplier->branch?->name;
            $supplier->city_name = $supplier->city?->name;
            $supplier->display_name = $supplier->display_name;
            $supplier->op_bal = 0;
            $supplier->total_due = 0;
            $supplier->return_due = 0;
            $supplier->account_linked = filled($supplier->supplier_gl_id);

            return $supplier;
        });

        $trashCount = Contact::onlyTrashed()
            ->suppliers()
            ->visibleToCurrentUser()
            ->count();

        return response()->json(['data' => $suppliers, 'trash_count' => $trashCount]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate($this->supplierFormRules());

        DB::beginTransaction();
        try {
            Contact::createSupplier($request);
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
        $supplier = Contact::findVisibleSupplier($id);

        if ($supplier === null) {
            abort(404);
        }

        $supplier->load(['country', 'state', 'city', 'currency', 'company', 'branch']);

        return response()->json($supplier);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate($this->supplierFormRules());

        DB::beginTransaction();
        try {
            Contact::updateSupplier($request, $id);
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
            Contact::deleteSupplier($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function linkCoa(int $id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $supplier = Contact::linkSupplierToChartOfAccount($id);
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
            'supplier_gl_id' => $supplier->supplier_gl_id,
            'link_account' => $supplier->link_account,
        ]);
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Contact::query()
                    ->suppliers()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Contact::whereIn('id', $ids)->delete();
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
                $ids = Contact::query()
                    ->onlyTrashed()
                    ->suppliers()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                Contact::whereIn('id', $ids)->forceDelete();
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
        $suppliers = Contact::query()
            ->suppliers()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if ($suppliers->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($suppliers as $supplier) {
                if (isset($request->status)) {
                    $supplier->active = $request->status;
                } else {
                    $supplier->active = ! $supplier->active;
                }
                $supplier->save();
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
                $ids = Contact::query()
                    ->onlyTrashed()
                    ->suppliers()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Contact::whereIn('id', $ids)->restore();
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
            $supplier = Contact::findVisibleSupplier((int) $request->id);

            if ($supplier === null) {
                abort(404);
            }

            $duplicator = $supplier->replicate();
            $duplicator->code = Contact::generateCode(
                (int) $supplier->company_id,
                (int) $supplier->branch_id,
            );
            $duplicator->business_name = $this->duplicateBusinessName(
                $supplier->business_name,
                (int) $supplier->company_id,
                (int) $supplier->branch_id,
            );
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    private function duplicateBusinessName(string $name, int $companyId, int $branchId): string
    {
        $candidate = $name.' Copy';
        $suffix = 1;

        while (Contact::query()
            ->suppliers()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('business_name', $candidate)
            ->exists()) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
    }

    public function fetch(Request $request): JsonResponse
    {
        $suppliers = Contact::query()
            ->suppliers()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->select('contacts.*', 'business_name as text')
            ->orderBy('business_name')
            ->get();

        return response()->json($suppliers);
    }

    public function contactDetail(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id' => 'required|integer',
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
        ]);

        $query = Contact::query()
            ->suppliers()
            ->visibleToCurrentUser()
            ->where('id', $request->contact_id);

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $contact = $query
            ->with([
                'country',
                'state',
                'city',
                'currency',
                'company',
                'branch',
            ])
            ->first();

        if ($contact === null) {
            abort(404);
        }

        $this->appendContactFinancialStats($contact);

        return response()->json($contact);
    }

    public function trash(Request $request): JsonResponse
    {
        $sortBy = $request->sort_by ?? 'created_at';
        $sortType = $request->sort_type ?? 'desc';
        $showRecord = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $curPage = $request->cur_page ?? 1;

        $query = Contact::onlyTrashed()
            ->suppliers()
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
                        'business_name',
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

        $suppliers = $query->paginate($showRecord);

        if ($curPage > $suppliers->lastPage()) {
            Paginator::currentPageResolver(function () use ($suppliers) {
                return $suppliers->lastPage();
            });
            $suppliers = $query->paginate($showRecord);
        }

        return response()->json(['data' => $suppliers]);
    }

    private function appendContactFinancialStats(Contact $contact): void
    {
        if (! Schema::hasTable('transactions')) {
            $contact->total_purchase = 0;
            $contact->paid_purchase = 0;
            $contact->due_purchase = 0;
            $contact->total_sell = 0;
            $contact->paid_sell = 0;
            $contact->due_sell = 0;

            return;
        }

        $transactionBase = function () use ($contact) {
            return DB::table('transactions')
                ->where('company_id', $contact->company_id)
                ->where('branch_id', $contact->branch_id)
                ->where('contact_id', $contact->id);
        };

        if (in_array($contact->user_type, ['supplier', 'both'], true)) {
            $contact->total_purchase = (float) $transactionBase()->where('type', 'purchaseorder')->sum('final_amount');
            $contact->due_purchase = (float) $transactionBase()->where('type', 'purchaseorder')->where('payment_status', 'due')->sum('final_amount');
            $paidPurchase = (float) $transactionBase()->where('type', 'purchaseorder')->where('payment_status', 'paid')->sum('final_amount');
            $partialPurchase = 0.0;

            if (Schema::hasTable('payments')) {
                $partialPurchase = (float) DB::table('transactions')
                    ->join('payments', 'payments.transaction_id', '=', 'transactions.id')
                    ->where('transactions.company_id', $contact->company_id)
                    ->where('transactions.branch_id', $contact->branch_id)
                    ->where('transactions.contact_id', $contact->id)
                    ->where('transactions.type', 'purchaseorder')
                    ->where('transactions.payment_status', 'partial')
                    ->sum('payments.amount');
            }

            $contact->paid_purchase = $paidPurchase + $partialPurchase;
        }

        if (in_array($contact->user_type, ['customer', 'both'], true)) {
            $contact->total_sell = (float) $transactionBase()->where('type', 'sell')->sum('final_amount');
            $contact->due_sell = (float) $transactionBase()->where('type', 'sell')->where('payment_status', 'due')->sum('final_amount');
            $paidSell = (float) $transactionBase()->where('type', 'sell')->where('payment_status', 'paid')->sum('final_amount');
            $partialSell = 0.0;

            if (Schema::hasTable('payments')) {
                $partialSell = (float) DB::table('transactions')
                    ->join('payments', 'payments.transaction_id', '=', 'transactions.id')
                    ->where('transactions.company_id', $contact->company_id)
                    ->where('transactions.branch_id', $contact->branch_id)
                    ->where('transactions.contact_id', $contact->id)
                    ->where('transactions.type', 'sell')
                    ->where('transactions.payment_status', 'partial')
                    ->sum('payments.amount');
            }

            $contact->paid_sell = $paidSell + $partialSell;
        }
    }
}
