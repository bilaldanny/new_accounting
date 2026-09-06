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

class SellPaymentController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function paymentFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'bail|required',
            'contact_id' => 'bail|required',
            'transaction_id' => 'bail|required|integer',
            'amount' => 'bail|required|numeric|min:0.01',
            'paid_on' => 'bail|required',
            'method' => 'bail|required|in:'.implode(',', Payment::METHODS),
            'payment_account' => 'bail|required|integer|exists:chart_of_accounts,id',
            'document' => 'nullable',
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
            ->forSells()
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
            $payment->customer_name = $payment->contact?->business_name ?? $payment->contact?->first_name;
            $payment->invoice_no = $payment->transaction?->invoice_no;
            $payment->payment_account_name = $payment->paymentAccount
                ? trim($payment->paymentAccount->code.' - '.$payment->paymentAccount->name)
                : null;
            $payment->method_label = str_replace('_', ' ', (string) $payment->method);
            $payment->formatted_amount = number_format((float) $payment->amount, 2);
            $payment->paid_on_label = $payment->paid_on;
            $payment->sell_payment_status = $payment->transaction?->payment_status;

            return $payment;
        });

        return response()->json(['data' => $payments, 'trash_count' => 0]);
    }

    public function eligibleSells(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->hasRole('superadmin')
            ? Transaction::resolveScopedId($request->company_id)
            : Transaction::resolveScopedId(Auth::user()?->company_id ?? $request->company_id);

        $sells = Transaction::query()
            ->sells()
            ->visibleToCurrentUser()
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->when(Transaction::resolveScopedId($request->branch_id), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when(Transaction::resolveScopedId($request->contact_id), fn ($query, $contactId) => $query->where('contact_id', $contactId))
            ->orderByDesc('id')
            ->get(['id', 'invoice_no', 'final_amount', 'payment_status', 'contact_id', 'company_id', 'branch_id', 'transaction_date'])
            ->map(function (Transaction $sell) {
                $remaining = Payment::remainingAmountForTransaction($sell);

                $sell->remaining_amount = $remaining;
                $sell->text = trim(($sell->invoice_no ?: '#'.$sell->id).' ('.$remaining.' due)');

                return $sell;
            })
            ->filter(fn (Transaction $sell) => (float) $sell->remaining_amount > 0)
            ->values();

        return response()->json($sells);
    }

    public function sell(int $id): JsonResponse
    {
        $sell = Transaction::query()
            ->sells()
            ->visibleToCurrentUser()
            ->with(['contact:id,business_name,first_name', 'branch:id,name'])
            ->find($id);

        if ($sell === null) {
            abort(404);
        }

        $remaining = Payment::remainingAmountForTransaction($sell);

        return response()->json([
            'id' => $sell->id,
            'company_id' => $sell->company_id,
            'branch_id' => $sell->branch_id,
            'contact_id' => $sell->contact_id,
            'invoice_no' => $sell->invoice_no,
            'final_amount' => $sell->final_amount,
            'payment_status' => $sell->payment_status,
            'remaining_amount' => $remaining,
            'customer_name' => $sell->contact?->first_name,
            'business_name' => $sell->contact?->business_name,
            'branch_name' => $sell->branch?->name,
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
        $this->authorizeMenuPermission('/sell/payment/add');

        $request->validate($this->paymentFormRules());

        DB::beginTransaction();
        try {
            Payment::createSellPayment($request);
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
            ->forSells()
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
        $this->authorizeMenuPermission('/sell/payment/:id/edit');

        $request->validate($this->paymentFormRules());

        DB::beginTransaction();
        try {
            Payment::updateSellPayment($request, $id);
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
        if (deletepermission('/sell/payment/delete')) {
            Payment::deleteSellPayment($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/sell/payment/delete', 'Successfully Deleted', function () use ($request): void {
            Payment::deleteSellPayments((array) $request->all());
        });
    }
}
