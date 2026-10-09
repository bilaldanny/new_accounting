<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionInvoice;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Billing > Customer Subscription Invoices. Manual-payment model: generating never auto-charges
 * anything, `markPaid`/`refund` are the admin's own out-of-band record of money that moved elsewhere.
 */
class CustomerSubscriptionInvoiceController extends Controller
{
    use HandlesIndexAndBulkDelete;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptioninvoices');

        CustomerSubscriptionInvoice::flagOverdue();

        $query = CustomerSubscriptionInvoice::query()
            ->visibleToCurrentUser()
            ->with(['contact:id,first_name,last_name,business_name'])
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->contact_id))
            ->when($request->status && $request->status !== 'all', fn ($q) => $q->where('status', $request->status));

        $invoices = $this->paginateSorted($query, $this->withSafeSort($request));

        $invoices->getCollection()->transform(function (CustomerSubscriptionInvoice $invoice) {
            $contact = $invoice->contact;
            $invoice->customer_name = $contact?->business_name ?: trim((string) $contact?->first_name.' '.(string) $contact?->last_name);

            return $invoice;
        });

        return response()->json(['data' => $invoices]);
    }

    public function show($id): JsonResponse
    {
        $invoice = CustomerSubscriptionInvoice::query()->visibleToCurrentUser()->with(['contact', 'subscription.plan'])->find($id);

        if ($invoice === null) {
            abort(404);
        }

        return response()->json($invoice);
    }

    /**
     * Generates the next cycle's invoice for every due subscription (its own Renewal/Auto-Renewal).
     */
    public function generate(): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptioninvoices/add');

        $generated = 0;

        CustomerSubscription::query()
            ->visibleToCurrentUser()
            ->whereIn('status', ['trial', 'active'])
            ->whereDate('current_period_ends_at', '<=', now()->toDateString())
            ->each(function (CustomerSubscription $subscription) use (&$generated): void {
                if (CustomerSubscriptionInvoice::renew($subscription) !== null) {
                    $generated++;
                }
            });

        return response()->json(['message' => "Generated {$generated} customer subscription invoice(s).", 'generated' => $generated]);
    }

    public function markPaid(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptioninvoices/:id/edit');

        $invoice = CustomerSubscriptionInvoice::query()->visibleToCurrentUser()->find($id);

        if ($invoice === null) {
            abort(404);
        }

        $request->validate([
            'method' => ['bail', 'required', Rule::in(Payment::METHODS)],
            'reference' => 'nullable|string|max:100',
            'paid_amount' => 'bail|required|numeric|min:0.01',
            'document' => 'nullable|string',
        ]);

        try {
            $document = Payment::storeDocument($request, null, (int) $invoice->company_id);
            $invoice->markPaid(
                $request->string('method')->toString(),
                $request->filled('reference') ? $request->string('reference')->toString() : null,
                round((float) $request->paid_amount, 2),
                $document,
                Auth::id(),
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Invoice marked as paid.']);
    }

    public function cancel($id): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptioninvoices/:id/edit');

        $invoice = CustomerSubscriptionInvoice::query()->visibleToCurrentUser()->find($id);

        if ($invoice === null) {
            abort(404);
        }

        $invoice->cancel();

        return response()->json(['message' => 'Invoice cancelled.']);
    }

    /**
     * Pro-rated (or full) refund against an already-paid invoice.
     */
    public function refund(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptioninvoices/:id/edit');

        $invoice = CustomerSubscriptionInvoice::query()->visibleToCurrentUser()->find($id);

        if ($invoice === null) {
            abort(404);
        }

        if ($invoice->status !== 'paid') {
            return response()->json(['errormessage' => 'Only a paid invoice can be refunded.'], 422);
        }

        $request->validate([
            'amount' => ['bail', 'required', 'numeric', 'min:0.01', 'max:'.(float) $invoice->total_amount],
        ]);

        $invoice->refund((float) $request->amount);

        return response()->json(['message' => 'Invoice refunded.']);
    }

    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), CustomerSubscriptionInvoice::SORTABLE, true)) {
            $request->merge(['sort_by' => 'due_date']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
