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

class CustomerController extends Controller
{
    /**
     * @return array<string, string>
     */
    protected function customerFormRules(): array
    {
        return [
            'company_id' => 'bail|required',
            'branch_id' => 'bail|required',
            'business_name' => 'bail|required|string|max:255',
            'first_name' => 'bail|required|string|max:255',
            'mobile' => 'bail|required|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'bail|required|string|max:1000',
            'address_line_2' => 'nullable|string|max:1000',
            'zipcode' => 'nullable|string|max:50',
            'landmark' => 'nullable|string|max:255',
            'street_name' => 'nullable|string|max:255',
            'building_number' => 'nullable|string|max:50',
            'secondary_number' => 'nullable|string|max:50',
            'ntn_number' => 'bail|required|string|max:255',
            'code' => 'nullable|string|max:255',
            'opening_balance' => 'nullable|numeric',
            'date_of_birth' => 'nullable|date',
            'alternate_no' => 'nullable|string|max:255',
            'landline' => 'nullable|string|max:255',
            'pay_term' => 'nullable|numeric',
            'user_type' => 'nullable|in:customer,both',
            'type' => 'nullable|in:local,export',
            'pay_type' => 'nullable|in:month,day,year',
            'customer_group_id' => 'nullable|integer|exists:customer_groups,id',
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
            'code' => Contact::generateCustomerCode($companyId, $branchId),
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

        $query = Contact::query()
            ->customers()
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

        $customers = $query->paginate($showRecord);

        if ($curPage > $customers->lastPage()) {
            Paginator::currentPageResolver(function () use ($customers) {
                return $customers->lastPage();
            });
            $customers = $query->paginate($showRecord);
        }

        $customers->getCollection()->transform(function (Contact $customer) {
            $customer->company_name = $customer->company?->name;
            $customer->branch_name = $customer->branch?->name;
            $customer->city_name = $customer->city?->name;
            $customer->display_name = $customer->display_name;
            $customer->total_due = 0;
            $customer->return_due = 0;
            $customer->account_linked = filled($customer->customer_gl_id);

            return $customer;
        });

        Contact::appendListOpeningBalances($customers->getCollection(), 'customer_gl_id');

        $trashCount = Contact::onlyTrashed()
            ->customers()
            ->visibleToCurrentUser()
            ->count();

        return response()->json(['data' => $customers, 'trash_count' => $trashCount]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate($this->customerFormRules());

        DB::beginTransaction();
        try {
            Contact::createCustomer($request);
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
        $customer = Contact::findVisibleCustomer($id);

        if ($customer === null) {
            abort(404);
        }

        $customer->load(['country', 'state', 'city', 'currency', 'company', 'branch']);
        $this->appendContactFinancialStats($customer);
        $customer->appendOpeningBalanceFromGl($customer->customer_gl_id);

        return response()->json($customer);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate($this->customerFormRules());

        DB::beginTransaction();
        try {
            Contact::updateCustomer($request, $id);
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
            Contact::deleteCustomer($id);

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
            $customer = Contact::linkCustomerToChartOfAccount(
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
            'customer_gl_id' => $customer->customer_gl_id,
            'link_account' => $customer->link_account,
        ]);
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Contact::query()
                    ->customers()
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
                    ->customers()
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
        $customers = Contact::query()
            ->customers()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if ($customers->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($customers as $customer) {
                if (isset($request->status)) {
                    $customer->active = $request->status;
                } else {
                    $customer->active = ! $customer->active;
                }
                $customer->save();
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
                    ->customers()
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
            $customer = Contact::findVisibleCustomer((int) $request->id);

            if ($customer === null) {
                abort(404);
            }

            $duplicator = $customer->replicate();
            $duplicator->code = Contact::generateCustomerCode(
                (int) $customer->company_id,
                (int) $customer->branch_id,
            );
            $duplicator->business_name = $this->duplicateBusinessName(
                $customer->business_name,
                (int) $customer->company_id,
                (int) $customer->branch_id,
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
            ->customers()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('business_name', $candidate)
            ->exists()) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
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
            ->customers()
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

        $customers = $query->paginate($showRecord);

        if ($curPage > $customers->lastPage()) {
            Paginator::currentPageResolver(function () use ($customers) {
                return $customers->lastPage();
            });
            $customers = $query->paginate($showRecord);
        }

        return response()->json(['data' => $customers]);
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
