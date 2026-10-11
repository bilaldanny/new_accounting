<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\EarlyPaymentDiscount;
use App\Models\Payment;
use App\Models\PaymentTerm;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment terms with an early-payment discount ("2/10 net 30"): a term is assigned to customers and suppliers, and a document of such a
 * party that is paid inside the discount window can have the discount taken. Taking it posts a voucher through
 * Payment::createDocumentDiscount (sale: Dr discount account / Cr customer; purchase: Dr supplier / Cr discount account) and settles
 * that part of the document. Nothing happens by itself: a contact with no term, or a document outside its window, is untouched and
 * the discount is only ever taken by this action.
 */
class PaymentTermController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/paymentterm');

        $terms = PaymentTerm::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderBy('name')->get();

        $used = Contact::query()->whereIn('payment_term_id', $terms->pluck('id'))->selectRaw('payment_term_id, count(*) as c')->groupBy('payment_term_id')->pluck('c', 'payment_term_id');

        return response()->json(['data' => $terms->map(fn (PaymentTerm $term): array => $term->toArray() + ['contacts' => (int) ($used[$term->id] ?? 0)])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/paymentterm/add');

        $data = $this->validated($request);
        $term = PaymentTerm::query()->create(collect($data)->except('company_id')->all() + ['company_id' => $this->scopeCompanyId($request)]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $term], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/paymentterm/:id/edit');

        $term = PaymentTerm::query()->visibleToCurrentUser()->findOrFail($id);
        $term->update(collect($this->validated($request))->except('company_id')->all());

        return response()->json(['message' => 'Successfully Saved', 'data' => $term->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/paymentterm/delete');

        $term = PaymentTerm::query()->visibleToCurrentUser()->findOrFail($id);

        if (Contact::query()->where('payment_term_id', $term->id)->exists() || EarlyPaymentDiscount::query()->where('payment_term_id', $term->id)->exists()) {
            throw ValidationException::withMessages(['term' => ['This term is in use; switch it off instead.']]);
        }

        $term->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function assign(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/paymentterm/assign');

        $data = $request->validate(['contact_id' => 'required|integer', 'payment_term_id' => 'nullable|integer']);

        $contact = Contact::query()->visibleToCurrentUser()->find($data['contact_id']);

        if ($contact === null) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a contact you can see.']]);
        }

        if ($data['payment_term_id'] !== null) {
            $term = PaymentTerm::query()->where('company_id', $contact->company_id)->where('is_active', true)->find($data['payment_term_id']);

            if ($term === null) {
                throw ValidationException::withMessages(['payment_term_id' => ['Choose an active term of this company.']]);
            }
        }

        $contact->forceFill(['payment_term_id' => $data['payment_term_id']])->save();

        return response()->json(['message' => 'Successfully Saved']);
    }

    /**
     * Open documents of contacts that have a discount term, with the discount still available today.
     */
    public function eligible(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/paymentterm');

        $request->validate(['on' => 'nullable|date_format:Y-m-d', 'contact_id' => 'nullable|integer']);
        $on = $request->input('on', now()->toDateString());

        $documents = Transaction::query()->visibleToCurrentUser()
            ->whereIn('type', [Transaction::TYPE_SELL, Transaction::TYPE_PURCHASE])
            ->whereNotIn('status', array_merge(Transaction::UNPOSTED_SELL_STATUSES, ['rejected', 'cancelled']))
            ->where('payment_status', '!=', 'paid')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->integer('contact_id')))
            ->whereIn('contact_id', Contact::query()->whereNotNull('payment_term_id')->select('id'))
            ->orderBy('transaction_date')->limit(300)->get();

        $contacts = Contact::query()->whereIn('id', $documents->pluck('contact_id'))->get()->keyBy('id');
        $terms = PaymentTerm::query()->whereIn('id', $contacts->pluck('payment_term_id')->filter())->get()->keyBy('id');
        $taken = EarlyPaymentDiscount::query()->whereIn('transaction_id', $documents->pluck('id'))->pluck('transaction_id')->flip();

        $rows = $documents->map(function (Transaction $document) use ($contacts, $terms, $taken, $on): ?array {
            $term = $terms->get($contacts->get($document->contact_id)?->payment_term_id);

            if ($term === null || ! $term->is_active || (float) $term->discount_percent <= 0 || $taken->has($document->id)) {
                return null;
            }

            $until = $document->transaction_date->copy()->addDays((int) $term->discount_days)->toDateString();

            if ($on > $until) {
                return null;
            }

            $remaining = Payment::remainingAmountForTransaction($document);

            if ($remaining <= 0) {
                return null;
            }

            return [
                'transaction_id' => $document->id, 'invoice_no' => $document->invoice_no, 'type' => $document->type,
                'contact_id' => $document->contact_id, 'contact' => $contacts->get($document->contact_id)?->business_name,
                'term' => $term->name, 'discount_percent' => (float) $term->discount_percent, 'discount_until' => $until,
                'remaining' => $remaining, 'discount' => round($remaining * (float) $term->discount_percent / 100, 2),
            ];
        })->filter()->values();

        return response()->json(['data' => $rows]);
    }

    public function applyDiscount(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/paymentterm/discount');

        $data = $request->validate([
            'transaction_id' => 'required|integer',
            'discount_account_id' => 'required|integer',
            'applied_on' => 'required|date_format:Y-m-d',
        ]);

        $document = Transaction::query()->visibleToCurrentUser()
            ->whereIn('type', [Transaction::TYPE_SELL, Transaction::TYPE_PURCHASE])->find($data['transaction_id']);

        if ($document === null || in_array($document->status, array_merge(Transaction::UNPOSTED_SELL_STATUSES, ['rejected', 'cancelled']), true)) {
            throw ValidationException::withMessages(['transaction_id' => ['Choose a posted invoice or bill you can see.']]);
        }

        $account = ChartOfAccount::query()->where('company_id', $document->company_id)->find($data['discount_account_id']);

        if ($account === null) {
            throw ValidationException::withMessages(['discount_account_id' => ['Choose an account of this company for the discount.']]);
        }

        $contact = Contact::query()->find($document->contact_id);
        $term = $contact?->payment_term_id === null ? null : PaymentTerm::query()->where('company_id', $document->company_id)->where('is_active', true)->find($contact->payment_term_id);

        if ($term === null || (float) $term->discount_percent <= 0) {
            throw ValidationException::withMessages(['transaction_id' => ['This party has no early-payment discount term.']]);
        }

        if (EarlyPaymentDiscount::query()->where('transaction_id', $document->id)->exists()) {
            throw ValidationException::withMessages(['transaction_id' => ['A discount was already taken on this document.']]);
        }

        $until = $document->transaction_date->copy()->addDays((int) $term->discount_days)->toDateString();

        if ($data['applied_on'] > $until || $data['applied_on'] < $document->transaction_date->toDateString()) {
            throw ValidationException::withMessages(['applied_on' => ['The discount is available until '.$until.'.']]);
        }

        $remaining = Payment::remainingAmountForTransaction($document);
        $amount = round($remaining * (float) $term->discount_percent / 100, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['transaction_id' => ['Nothing is left to discount on this document.']]);
        }

        $row = DB::transaction(function () use ($document, $account, $amount, $data, $term): EarlyPaymentDiscount {
            $payment = Payment::createDocumentDiscount($document, $account, $amount, $data['applied_on'], 'Early payment discount ('.$term->name.')');

            return EarlyPaymentDiscount::query()->create([
                'company_id' => $document->company_id, 'transaction_id' => $document->id, 'payment_term_id' => $term->id, 'amount' => $amount,
                'applied_on' => $data['applied_on'], 't_account_id' => $payment->t_account_id, 'created_by' => Auth::id(),
            ]);
        });

        return response()->json(['message' => 'Discount taken', 'data' => $row], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'name' => 'bail|required|string|min:2|max:80',
            'net_days' => 'required|integer|min:0|max:3650',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'discount_days' => 'nullable|integer|min:0|max:3650',
            'is_active' => 'nullable|boolean',
        ]);

        $data['discount_percent'] ??= 0;
        $data['discount_days'] ??= 0;

        if ((float) $data['discount_percent'] > 0 && (int) $data['discount_days'] > (int) $data['net_days']) {
            throw ValidationException::withMessages(['discount_days' => ['The discount period cannot be longer than the net days.']]);
        }

        return $data;
    }
}
