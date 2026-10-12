<?php

namespace App\Http\Controllers;

use App\Models\AdvanceRefund;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentBatch;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Supplier advances and one payment allocated across several bills. A batch pays each chosen bill through
 * Payment::createPurchasePayment (the same voucher a payment made on the bill itself posts) and keeps what no bill takes as a
 * supplier advance (Payment::createPurchaseAdvance: the supplier's account debited, cash / bank credited). The advance settles
 * later bills without moving cash again. Reversing a batch removes its payments and vouchers and puts the bills right.
 */
class SupplierPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/supplierpayment');

        $rows = SupplierPaymentBatch::query()
            ->visibleToCurrentUser()
            ->withCount('allocations')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->integer('contact_id')))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        $rows->getCollection()->transform(function (SupplierPaymentBatch $batch): SupplierPaymentBatch {
            $batch->setAttribute('advance_remaining', $batch->advanceRemaining());

            return $batch;
        });

        return response()->json(['data' => $rows]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/supplierpayment');

        $batch = SupplierPaymentBatch::query()->visibleToCurrentUser()->with('allocations')->findOrFail($id);

        return response()->json($batch->toArray() + ['advance_remaining' => $batch->advanceRemaining()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/supplierpayment/add');

        $data = $request->validate([
            'contact_id' => 'required|integer',
            'branch_id' => 'nullable|integer',
            'paid_on' => 'required|date_format:Y-m-d',
            'payment_account' => 'required|integer',
            'method' => 'required|in:cash,bank,cheque,card,online,other',
            'amount' => 'required|numeric|gt:0',
            'keep_advance' => 'nullable|boolean',
            'note' => 'nullable|string|max:1000',
            'cheque_number' => 'nullable|string|max:100',
            'allocations' => 'nullable|array|max:100',
            'allocations.*.transaction_id' => 'required|integer',
            'allocations.*.amount' => 'required|numeric|gt:0',
        ]);

        $supplier = Contact::query()->visibleToCurrentUser()->whereIn('user_type', ['supplier', 'both'])->find($data['contact_id']);

        if ($supplier === null) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a supplier you can see.']]);
        }

        $account = ChartOfAccount::query()->where('company_id', $supplier->company_id)->find($data['payment_account']);

        if ($account === null) {
            throw ValidationException::withMessages(['payment_account' => ['Choose a cash or bank account of this company.']]);
        }

        $allocations = array_values($data['allocations'] ?? []);
        $branchId = (int) ($data['branch_id'] ?? $supplier->branch_id);
        $allocated = round(array_sum(array_column($allocations, 'amount')), 2);
        $amount = round((float) $data['amount'], 2);
        $advance = round($amount - $allocated, 2);

        if ($advance < 0) {
            throw ValidationException::withMessages(['amount' => ['The bills take more than the payment.']]);
        }

        if ($advance > 0 && ! $request->boolean('keep_advance')) {
            throw ValidationException::withMessages(['amount' => ["{$advance} is not allocated to any bill. Allocate it, or keep it as a supplier advance."]]);
        }

        $bills = Transaction::query()
            ->where('company_id', $supplier->company_id)
            ->where('type', Transaction::TYPE_PURCHASE)
            ->where('contact_id', $supplier->id)
            ->whereIn('id', array_column($allocations, 'transaction_id'))
            ->get()
            ->keyBy('id');

        foreach ($allocations as $index => $allocation) {
            $bill = $bills->get((int) $allocation['transaction_id']);

            if ($bill === null || $bill->status === 'draft') {
                throw ValidationException::withMessages(["allocations.{$index}.transaction_id" => ['Choose a posted bill of this supplier.']]);
            }

            if ((float) $allocation['amount'] > Payment::remainingAmountForTransaction($bill) + 0.000001) {
                throw ValidationException::withMessages(["allocations.{$index}.amount" => ['The amount is more than the bill still owes.']]);
            }
        }

        $batch = DB::transaction(function () use ($data, $supplier, $branchId, $allocations, $bills, $amount, $advance, $account): SupplierPaymentBatch {
            $batch = SupplierPaymentBatch::query()->create([
                'company_id' => $supplier->company_id, 'branch_id' => $branchId, 'contact_id' => $supplier->id, 'reference' => 'TMP',
                'paid_on' => $data['paid_on'], 'payment_account' => $account->id, 'method' => $data['method'], 'amount' => $amount,
                'advance_amount' => $advance, 'status' => 'completed', 'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
            $batch->forceFill(['reference' => 'SPB-'.str_pad((string) $batch->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();

            foreach ($allocations as $allocation) {
                $bill = $bills->get((int) $allocation['transaction_id']);
                $payment = Payment::createPurchasePayment((object) [
                    'transaction_id' => $bill->id,
                    'amount' => $allocation['amount'],
                    'paid_on' => $data['paid_on'],
                    'method' => $data['method'],
                    'payment_account' => $account->id,
                    'note' => trim('Payment '.$batch->reference.(($data['note'] ?? null) ? ': '.$data['note'] : '')),
                    'cheque_number' => $data['cheque_number'] ?? null,
                ]);
                SupplierPaymentAllocation::query()->create([
                    'supplier_payment_batch_id' => $batch->id, 'transaction_id' => $bill->id, 'payment_id' => $payment->id,
                    'amount' => $allocation['amount'], 'kind' => SupplierPaymentAllocation::KIND_CASH,
                ]);
            }

            if ($advance > 0) {
                $payment = Payment::createPurchaseAdvance($supplier, (int) $supplier->company_id, $branchId, $advance, $data['paid_on'], (int) $account->id, 'Advance '.$batch->reference);
                $batch->update(['advance_payment_id' => $payment->id]);
            }

            return $batch;
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $batch->fresh('allocations')], 201);
    }

    /**
     * Settles bills of the supplier out of the advance a batch kept; no cash moves and nothing is posted (the supplier's
     * account already holds the debit).
     */
    public function applyAdvance(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/supplierpayment/add');

        $data = $request->validate([
            'paid_on' => 'required|date_format:Y-m-d',
            'allocations' => 'required|array|min:1|max:100',
            'allocations.*.transaction_id' => 'required|integer',
            'allocations.*.amount' => 'required|numeric|gt:0',
        ]);

        $batch = SupplierPaymentBatch::query()->visibleToCurrentUser()->findOrFail($id);

        if ($batch->status !== 'completed') {
            throw ValidationException::withMessages(['status' => ['This payment has been reversed.']]);
        }

        $total = round(array_sum(array_column($data['allocations'], 'amount')), 2);

        if ($total > $batch->advanceRemaining() + 0.000001) {
            throw ValidationException::withMessages(['allocations' => ['The bills take more than is left of the advance ('.$batch->advanceRemaining().').']]);
        }

        DB::transaction(function () use ($batch, $data): void {
            foreach ($data['allocations'] as $index => $allocation) {
                $bill = Transaction::query()
                    ->where('company_id', $batch->company_id)->where('type', Transaction::TYPE_PURCHASE)->where('contact_id', $batch->contact_id)
                    ->where('status', '!=', 'draft')->find($allocation['transaction_id']);

                if ($bill === null) {
                    throw ValidationException::withMessages(["allocations.{$index}.transaction_id" => ['Choose a posted bill of this supplier.']]);
                }

                $payment = Payment::createPurchasePaymentFromAdvance($bill, (float) $allocation['amount'], $data['paid_on'], 'Advance '.$batch->reference);
                SupplierPaymentAllocation::query()->create([
                    'supplier_payment_batch_id' => $batch->id, 'transaction_id' => $bill->id, 'payment_id' => $payment->id,
                    'amount' => $allocation['amount'], 'kind' => SupplierPaymentAllocation::KIND_ADVANCE,
                ]);
            }
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $batch->fresh('allocations')]);
    }

    /**
     * The supplier pays back part or all of the advance a batch kept and no bill took: cash / bank is debited and the supplier's
     * account credited (Payment::createPurchaseAdvanceRefund), and the advance's remaining balance falls by the same amount.
     */
    public function refundAdvance(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/supplierpayment/refund');

        $data = $request->validate([
            'refunded_on' => 'required|date_format:Y-m-d',
            'amount' => 'required|numeric|gt:0',
            'payment_account' => 'required|integer',
            'method' => 'nullable|in:cash,bank,cheque,card,online,other',
            'note' => 'nullable|string|max:255',
        ]);

        $batch = SupplierPaymentBatch::query()->visibleToCurrentUser()->findOrFail($id);

        if ($batch->status !== 'completed') {
            throw ValidationException::withMessages(['status' => ['This payment has been reversed.']]);
        }

        $amount = round((float) $data['amount'], 2);

        if ($amount > $batch->advanceRemaining() + 0.000001) {
            throw ValidationException::withMessages(['amount' => ['The refund is more than is left of the advance ('.$batch->advanceRemaining().').']]);
        }

        $supplier = Contact::query()->where('company_id', $batch->company_id)->findOrFail($batch->contact_id);

        $refund = DB::transaction(function () use ($batch, $supplier, $data, $amount): AdvanceRefund {
            $payment = Payment::createPurchaseAdvanceRefund(
                $supplier, (int) $batch->company_id, (int) $batch->branch_id, $amount, $data['refunded_on'], (int) $data['payment_account'],
                $data['method'] ?? 'cash', trim('Advance refund '.$batch->reference.(($data['note'] ?? null) ? ': '.$data['note'] : '')),
            );

            return AdvanceRefund::query()->create([
                'company_id' => $batch->company_id, 'branch_id' => $batch->branch_id, 'party' => 'supplier', 'contact_id' => $batch->contact_id,
                'supplier_payment_batch_id' => $batch->id, 'payment_id' => $payment->id, 'amount' => $amount,
                'refunded_on' => $data['refunded_on'], 'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
        });

        return response()->json(['message' => 'Refund recorded', 'data' => $refund, 'advance_remaining' => $batch->fresh()->advanceRemaining()], 201);
    }

    public function reverse(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/supplierpayment/reverse');

        $batch = SupplierPaymentBatch::query()->visibleToCurrentUser()->with('allocations')->findOrFail($id);

        if ($batch->status !== 'completed') {
            throw ValidationException::withMessages(['status' => ['This payment has already been reversed.']]);
        }

        DB::transaction(function () use ($batch): void {
            // bills settled out of the advance first, then the cash payments, then the advance itself
            foreach ($batch->allocations->sortByDesc(fn (SupplierPaymentAllocation $a): int => $a->kind === 'advance' ? 1 : 0) as $allocation) {
                $payment = Payment::query()->find($allocation->payment_id);

                if ($payment !== null) {
                    Payment::removeCollectedPayment($payment);
                }
            }

            // money paid back out of the advance comes out before the advance itself
            if (Schema::hasTable('advance_refunds')) {
                foreach (AdvanceRefund::query()->where('supplier_payment_batch_id', $batch->id)->get() as $refund) {
                    $payment = $refund->payment_id === null ? null : Payment::query()->find($refund->payment_id);

                    if ($payment !== null) {
                        Payment::removeCollectedPayment($payment);
                    }

                    $refund->delete();
                }
            }

            $advance = $batch->advance_payment_id === null ? null : Payment::query()->find($batch->advance_payment_id);

            if ($advance !== null) {
                Payment::removeCollectedPayment($advance);
            }

            $batch->update(['status' => 'reversed']);
        });

        return response()->json(['message' => 'Payment reversed', 'data' => $batch->fresh('allocations')]);
    }
}
