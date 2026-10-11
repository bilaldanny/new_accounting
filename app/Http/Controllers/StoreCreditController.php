<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\CustomerStoreCredit;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refunds and a named store-credit balance per customer. A sale return already credits the customer's account; keeping its
 * value as store credit records that the customer may take it later, and the balance is issued less refunded less applied.
 * A refund payout is posted like any payment to the customer (cash / bank credited, the customer's account debited, so their
 * receivable is back where it was before the return); applying credit to an invoice only settles the invoice's payment status
 * (the ledger already holds the credit). Nothing here changes how a return is posted.
 */
class StoreCreditController extends Controller
{
    public function balance(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/storecredit');

        $request->validate(['contact_id' => 'required|integer']);
        $customer = $this->customer($request->integer('contact_id'));

        return response()->json([
            'contact_id' => $customer->id,
            'balance' => $this->balanceOf($customer),
            'entries' => CustomerStoreCredit::query()->where('contact_id', $customer->id)->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/storecredit');

        $rows = CustomerStoreCredit::query()
            ->visibleToCurrentUser()
            ->selectRaw("contact_id, sum(case entry_type when 'issue' then amount else -amount end) as balance, count(*) as entries")
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->groupBy('contact_id')
            ->havingRaw("sum(case entry_type when 'issue' then amount else -amount end) <> 0")
            ->get();

        $names = Contact::query()->whereIn('id', $rows->pluck('contact_id'))->pluck('business_name', 'id');

        return response()->json(['data' => $rows->map(fn ($row): array => [
            'contact_id' => (int) $row->contact_id, 'customer' => $names[$row->contact_id] ?? null, 'balance' => round((float) $row->balance, 2), 'entries' => (int) $row->entries,
        ])->values()]);
    }

    /**
     * Keeps (part of) a sale return's value as store credit.
     */
    public function issue(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/storecredit/add');

        $data = $request->validate(['sell_return_id' => 'required|integer', 'amount' => 'required|numeric|gt:0', 'note' => 'nullable|string|max:500']);

        $return = Transaction::query()->visibleToCurrentUser()->where('type', Transaction::TYPE_SELL_RETURN)->find($data['sell_return_id']);

        if ($return === null || $return->contact_id === null) {
            throw ValidationException::withMessages(['sell_return_id' => ['Choose a sale return of a customer.']]);
        }

        $kept = (float) CustomerStoreCredit::query()->where('sell_return_id', $return->id)->where('entry_type', 'issue')->sum('amount');
        $left = round((float) $return->final_amount - $kept, 2);

        if ((float) $data['amount'] > $left + 0.000001) {
            throw ValidationException::withMessages(['amount' => ['At most '.$left.' of this return can still be kept as store credit.']]);
        }

        $entry = CustomerStoreCredit::query()->create([
            'company_id' => $return->company_id, 'branch_id' => $return->branch_id, 'contact_id' => $return->contact_id, 'entry_type' => 'issue',
            'amount' => $data['amount'], 'sell_return_id' => $return->id, 'reference' => $return->invoice_no, 'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $entry, 'balance' => $this->balanceOf(Contact::query()->findOrFail($return->contact_id))], 201);
    }

    /**
     * Pays store credit out in cash or by bank.
     */
    public function refund(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/storecredit/refund');

        $data = $request->validate([
            'contact_id' => 'required|integer',
            'amount' => 'required|numeric|gt:0',
            'paid_on' => 'required|date_format:Y-m-d',
            'payment_account' => 'required|integer',
            'method' => 'required|in:cash,bank,cheque,card,online,other',
            'note' => 'nullable|string|max:500',
        ]);

        $customer = $this->customer($data['contact_id']);
        $balance = $this->balanceOf($customer);

        if ((float) $data['amount'] > $balance + 0.000001) {
            throw ValidationException::withMessages(['amount' => ['The customer has '.$balance.' of store credit.']]);
        }

        $entry = DB::transaction(function () use ($data, $customer): CustomerStoreCredit {
            $payment = Payment::createSellRefund($customer, (int) $customer->company_id, (int) $customer->branch_id, (float) $data['amount'], $data['paid_on'], (int) $data['payment_account'], $data['method'], trim('Store credit refund'.(($data['note'] ?? null) ? ': '.$data['note'] : '')));

            return CustomerStoreCredit::query()->create([
                'company_id' => $customer->company_id, 'branch_id' => $customer->branch_id, 'contact_id' => $customer->id, 'entry_type' => 'refund',
                'amount' => $data['amount'], 'payment_id' => $payment->id, 'reference' => $payment->payment_ref_no, 'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
        });

        return response()->json(['message' => 'Refund paid', 'data' => $entry, 'balance' => $this->balanceOf($customer)], 201);
    }

    /**
     * Settles an invoice of the customer out of their store credit.
     */
    public function apply(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/storecredit/apply');

        $data = $request->validate(['contact_id' => 'required|integer', 'transaction_id' => 'required|integer', 'amount' => 'required|numeric|gt:0', 'paid_on' => 'required|date_format:Y-m-d']);

        $customer = $this->customer($data['contact_id']);
        $balance = $this->balanceOf($customer);

        if ((float) $data['amount'] > $balance + 0.000001) {
            throw ValidationException::withMessages(['amount' => ['The customer has '.$balance.' of store credit.']]);
        }

        $sale = Transaction::query()->sells()->visibleToCurrentUser()->where('contact_id', $customer->id)->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)->find($data['transaction_id']);

        if ($sale === null) {
            throw ValidationException::withMessages(['transaction_id' => ['Choose a posted invoice of this customer.']]);
        }

        $entry = DB::transaction(function () use ($data, $customer, $sale): CustomerStoreCredit {
            $payment = Payment::createSellPaymentFromAdvance($sale, (float) $data['amount'], $data['paid_on'], 'Store credit');

            return CustomerStoreCredit::query()->create([
                'company_id' => $customer->company_id, 'branch_id' => $sale->branch_id, 'contact_id' => $customer->id, 'entry_type' => 'apply',
                'amount' => $data['amount'], 'transaction_id' => $sale->id, 'payment_id' => $payment->id, 'reference' => $sale->invoice_no, 'created_by' => Auth::id(),
            ]);
        });

        return response()->json(['message' => 'Store credit applied', 'data' => $entry, 'balance' => $this->balanceOf($customer)], 201);
    }

    private function balanceOf(Contact $customer): float
    {
        $rows = CustomerStoreCredit::query()->where('contact_id', $customer->id)->selectRaw('entry_type, sum(amount) as total')->groupBy('entry_type')->pluck('total', 'entry_type');

        return round((float) ($rows['issue'] ?? 0) - (float) ($rows['refund'] ?? 0) - (float) ($rows['apply'] ?? 0), 2);
    }

    private function customer(int $id): Contact
    {
        $customer = Contact::query()->visibleToCurrentUser()->whereIn('user_type', ['customer', 'both'])->find($id);

        if ($customer === null) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a customer you can see.']]);
        }

        return $customer;
    }
}
