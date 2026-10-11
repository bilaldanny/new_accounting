<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\ChequeRecord;
use App\Models\Contact;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The cheque register: every cheque received or issued with its status along ChequeRecord::FLOW. A cheque may be linked to the
 * payment that recorded it; bouncing a received cheque removes that payment through the ordinary payment removal (its voucher
 * goes with it and the invoice is due again). Depositing and clearing are dated steps; the payment keeps posting to the account
 * it was recorded against, so no second voucher is made.
 */
class ChequeRecordController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/chequemanagement');

        $rows = ChequeRecord::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->boolean('due_soon'), fn ($q) => $q->whereNotNull('due_date')->whereDate('due_date', '<=', now()->addDays(7)->toDateString())->whereIn('status', ['received', 'issued', 'deposited']))
            ->orderByRaw('case when due_date is null then 1 else 0 end, due_date')
            ->paginate(min($request->integer('per_page', 25), 100));

        $names = Contact::query()->whereIn('id', $rows->getCollection()->pluck('contact_id'))->pluck('business_name', 'id');
        $rows->getCollection()->transform(function (ChequeRecord $cheque) use ($names): ChequeRecord {
            $cheque->setAttribute('contact_name', $names[$cheque->contact_id] ?? null);

            return $cheque;
        });

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/chequemanagement/add');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'direction' => 'required|in:received,issued',
            'cheque_no' => 'required|string|max:60',
            'bank_name' => 'nullable|string|max:120',
            'contact_id' => 'nullable|integer',
            'amount' => 'required|numeric|gt:0',
            'cheque_date' => 'nullable|date_format:Y-m-d',
            'due_date' => 'nullable|date_format:Y-m-d',
            'payment_id' => 'nullable|integer',
            'note' => 'nullable|string|max:500',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        if (isset($data['contact_id']) && ! Contact::query()->where('company_id', $companyId)->whereKey($data['contact_id'])->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a customer or supplier of this company.']]);
        }

        $payment = null;

        if (isset($data['payment_id'])) {
            $payment = Payment::query()->where('company_id', $companyId)->find($data['payment_id']);

            if ($payment === null || ChequeRecord::query()->where('payment_id', $payment->id)->where('status', '!=', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['payment_id' => ['Choose a payment of this company that has no cheque record yet.']]);
            }
        }

        $cheque = ChequeRecord::query()->create(collect($data)->except(['company_id', 'branch_id'])->all() + [
            'company_id' => $companyId, 'branch_id' => $branchId ?? $payment?->branch_id, 'status' => $data['direction'] === 'issued' ? 'issued' : 'received',
            'transaction_id' => $payment?->transaction_id, 'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $cheque], 201);
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/chequemanagement/:id/edit');

        $data = $request->validate([
            'status' => 'required|in:deposited,cleared,bounced,cancelled',
            'on' => 'nullable|date_format:Y-m-d',
            'bank_account_id' => 'nullable|integer',
            'reason' => 'nullable|string|max:255',
        ]);

        $cheque = ChequeRecord::query()->visibleToCurrentUser()->findOrFail($id);

        if (! in_array($data['status'], ChequeRecord::FLOW[$cheque->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => ["A cheque that is {$cheque->status} cannot become {$data['status']}."]]);
        }

        $day = $data['on'] ?? now()->toDateString();

        DB::transaction(function () use ($cheque, $data, $day): void {
            match ($data['status']) {
                'deposited' => $cheque->fill(['deposited_on' => $day, 'bank_account_id' => $data['bank_account_id'] ?? $cheque->bank_account_id]),
                'cleared' => $cheque->fill(['cleared_on' => $day]),
                'bounced' => $cheque->fill(['bounced_on' => $day, 'bounce_reason' => $data['reason'] ?? null]),
                default => null,
            };

            $cheque->status = $data['status'];
            $cheque->save();

            if ($data['status'] === 'bounced' && $cheque->payment_id !== null) {
                $payment = Payment::query()->find($cheque->payment_id);

                if ($payment !== null) {
                    Payment::removeCollectedPayment($payment);
                }
            }
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $cheque->fresh()]);
    }
}
