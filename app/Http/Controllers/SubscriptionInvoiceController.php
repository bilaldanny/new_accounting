<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Company;
use App\Models\Payment;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Billing > Subscription Invoices. Superadmin sees every tenant's invoices; a company's own users
 * (companyadmin) see only their own, read-only — only the superadmin marks one paid or cancels it.
 */
class SubscriptionInvoiceController extends Controller
{
    use HandlesIndexAndBulkDelete;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/subscriptioninvoices');

        SubscriptionInvoice::flagOverdue();

        $query = SubscriptionInvoice::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'subscriptionPlan:id,name'])
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->company_id))
            ->when($request->status && $request->status !== 'all', fn ($q) => $q->where('status', $request->status));

        $invoices = $this->paginateSorted($query, $this->withSafeSort($request));

        $invoices->getCollection()->transform(function (SubscriptionInvoice $invoice) {
            $invoice->company_name = $invoice->company?->name;
            $invoice->plan_name = $invoice->subscriptionPlan?->name;

            return $invoice;
        });

        return response()->json(['data' => $invoices]);
    }

    public function show($id): JsonResponse
    {
        $invoice = SubscriptionInvoice::query()->visibleToCurrentUser()->with(['company', 'subscriptionPlan', 'coupon'])->find($id);

        if ($invoice === null) {
            abort(404);
        }

        return response()->json($invoice);
    }

    /**
     * Generates the next cycle's invoice for every company whose plan's current period is due (or that
     * has none yet). Idempotent — a company already invoiced for its current period is skipped.
     */
    public function generate(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/subscriptioninvoices/add');
        $this->authorizeSuperadmin($request);

        $generated = 0;

        Company::query()
            ->whereNotNull('subscription_plan_id')
            ->whereIn('tenant_status', ['trial', 'active'])
            ->where(function ($q) {
                $q->whereNull('current_period_ends_at')
                    ->orWhereDate('current_period_ends_at', '<=', now()->toDateString());
            })
            ->each(function (Company $company) use (&$generated): void {
                if (SubscriptionInvoice::generateForCompany($company) !== null) {
                    $generated++;
                }
            });

        return response()->json(['message' => "Generated {$generated} subscription invoice(s).", 'generated' => $generated]);
    }

    public function markPaid(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/subscriptioninvoices/:id/edit');
        $this->authorizeSuperadmin($request);

        $invoice = SubscriptionInvoice::query()->find($id);

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

    public function cancel(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/subscriptioninvoices/:id/edit');
        $this->authorizeSuperadmin($request);

        $invoice = SubscriptionInvoice::query()->find($id);

        if ($invoice === null) {
            abort(404);
        }

        $invoice->cancel();

        return response()->json(['message' => 'Invoice cancelled.']);
    }

    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), SubscriptionInvoice::SORTABLE, true)) {
            $request->merge(['sort_by' => 'due_date']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }

    private function authorizeSuperadmin(Request $request): void
    {
        $user = $request->user();
        $roleName = strtolower(str_replace(' ', '', (string) ($user?->rolename ?? '')));

        if ($roleName !== 'superadmin') {
            abort(403);
        }
    }
}
