<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\ChartOfAccount;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PurchasePaymentController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function paymentFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            // branch_id/contact_id are not persisted from the request: they are
            // always derived from the looked-up transaction (see Payment::createPurchasePayment).
            'branch_id' => 'nullable',
            'contact_id' => 'nullable',
            'transaction_id' => 'bail|required|integer',
            'amount' => 'bail|required|numeric|min:0.01',
            'paid_on' => 'bail|required',
            'method' => 'bail|required|in:'.implode(',', Payment::METHODS),
            'payment_account' => 'bail|required|integer|exists:chart_of_accounts,id',
            'document' => 'nullable',
            'attachment' => 'nullable',
            'note' => 'nullable|string',
            'card_number' => 'required_if:method,card|nullable|string|max:255',
            'card_holder_name' => 'required_if:method,card|nullable|string|max:255',
            'card_type' => 'required_if:method,card|nullable|in:cc,dc,visa,mastercard',
            'card_transaction_number' => 'required_if:method,card|nullable|string|max:255',
            'card_month' => 'required_if:method,card|nullable|string|max:20',
            'card_year' => 'required_if:method,card|nullable|string|max:20',
            'card_security' => 'required_if:method,card|nullable|string|max:20',
            'cheque_number' => 'required_if:method,cheque|nullable|string|max:255',
            'bank_account_number' => 'required_if:method,bank_transfer|nullable|string|max:255',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $search = $request->search ?? '';

        $query = Payment::query()
            ->forPurchases()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'contact:id,business_name,first_name',
                'transaction:id,invoice_no,final_amount,payment_status,contact_id',
                'paymentAccount:id,name,code',
            ])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('payment_ref_no', 'like', "%{$search}%")
                        ->orWhere('method', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%")
                        ->orWhereHas('transaction', function ($transactionQuery) use ($search) {
                            $transactionQuery->where('invoice_no', 'like', "%{$search}%");
                        })
                        ->orWhereHas('contact', function ($contactQuery) use ($search) {
                            $contactQuery->where('business_name', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->when($request->filled('contact_id'), function ($q) use ($request) {
                $q->where('contact_id', $request->contact_id);
            })
            ->when($request->filled('transaction_id'), function ($q) use ($request) {
                $q->where('transaction_id', $request->transaction_id);
            })
            ->when($request->filled('method') && $request->method !== 'all', function ($q) use ($request) {
                $q->where('method', $request->method);
            })
            ->when($request->filled('payment_status') && $request->payment_status !== 'all', function ($q) use ($request) {
                $q->whereHas('transaction', function ($transactionQuery) use ($request) {
                    $transactionQuery->where('payment_status', $request->payment_status);
                });
            });

        $payments = $this->paginateSorted($query, $request);

        $payments->getCollection()->transform(function (Payment $payment) {
            $payment->company_name = $payment->company?->name;
            $payment->branch_name = $payment->branch?->name;
            $payment->supplier_name = $payment->contact?->business_name ?? $payment->contact?->first_name;
            $payment->invoice_no = $payment->transaction?->invoice_no;
            $payment->payment_account_name = $payment->paymentAccount
                ? trim($payment->paymentAccount->code.' - '.$payment->paymentAccount->name)
                : null;
            $payment->method_label = str_replace('_', ' ', (string) $payment->method);
            $payment->formatted_amount = number_format((float) $payment->amount, 2);
            $payment->paid_on_label = $payment->paid_on;
            $payment->purchase_payment_status = $payment->transaction?->payment_status;

            return $payment;
        });

        return response()->json(['data' => $payments, 'trash_count' => 0]);
    }

    public function eligiblePurchases(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->hasRole('superadmin')
            ? Transaction::resolveScopedId($request->company_id)
            : Transaction::resolveScopedId(Auth::user()?->company_id ?? $request->company_id);

        $purchases = Transaction::query()
            ->purchases()
            ->visibleToCurrentUser()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->when(Transaction::resolveScopedId($request->branch_id), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when(Transaction::resolveScopedId($request->contact_id), fn ($query, $contactId) => $query->where('contact_id', $contactId))
            ->orderByDesc('id')
            ->get(['id', 'invoice_no', 'final_amount', 'payment_status', 'contact_id', 'company_id', 'branch_id', 'transaction_date'])
            ->map(function (Transaction $purchase) {
                $remaining = Payment::remainingAmountForTransaction($purchase);

                $purchase->remaining_amount = $remaining;
                $purchase->text = trim(($purchase->invoice_no ?: '#'.$purchase->id).' ('.$remaining.' due)');

                return $purchase;
            })
            ->filter(fn (Transaction $purchase) => (float) $purchase->remaining_amount > 0)
            ->values();

        return response()->json($purchases);
    }

    public function purchase(int $id): JsonResponse
    {
        $purchase = Transaction::query()
            ->purchases()
            ->visibleToCurrentUser()
            ->with(['contact:id,business_name,first_name', 'branch:id,name'])
            ->find($id);

        if ($purchase === null) {
            abort(404);
        }

        $remaining = Payment::remainingAmountForTransaction($purchase);

        return response()->json([
            'id' => $purchase->id,
            'company_id' => $purchase->company_id,
            'branch_id' => $purchase->branch_id,
            'contact_id' => $purchase->contact_id,
            'invoice_no' => $purchase->invoice_no,
            'final_amount' => $purchase->final_amount,
            'payment_status' => $purchase->payment_status,
            'remaining_amount' => $remaining,
            'supplier_name' => $purchase->contact?->first_name,
            'business_name' => $purchase->contact?->business_name,
            'branch_name' => $purchase->branch?->name,
        ]);
    }

    public function paymentAccounts(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->hasRole('superadmin')
            ? Transaction::resolveScopedId($request->company_id)
            : Transaction::resolveScopedId(Auth::user()?->company_id ?? $request->company_id);

        $branchId = Auth::user()?->hasRole('companyadmin') || Auth::user()?->hasRole('superadmin')
            ? Transaction::resolveScopedId($request->branch_id)
            : Transaction::resolveScopedId(Auth::user()?->branch_id ?? $request->branch_id);

        return response()->json(ChartOfAccount::bankAndCashAccountOptions($companyId, $branchId));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/purchase/payment/add');

        $request->validate($this->paymentFormRules());

        DB::beginTransaction();
        try {
            Payment::createPurchasePayment($request);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show(int $id): JsonResponse
    {
        $payment = Payment::query()
            ->forPurchases()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'contact:id,business_name,first_name', 'transaction'])
            ->find($id);

        if ($payment === null) {
            abort(404);
        }

        return response()->json($payment->toFormArray());
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/purchase/payment/:id/edit');

        $request->validate($this->paymentFormRules());

        DB::beginTransaction();
        try {
            Payment::updatePurchasePayment($request, $id);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy(int $id): JsonResponse
    {
        if (deletepermission('/purchase/payment/delete')) {
            Payment::deletePurchasePayment($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/purchase/payment/delete', 'Successfully Deleted', function () use ($request): void {
            Payment::deletePurchasePayments((array) $request->all());
        });
    }
}
