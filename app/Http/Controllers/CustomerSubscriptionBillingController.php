<?php

namespace App\Http\Controllers;

use App\Models\CustomerSubscription;
use App\Services\SubscriptionBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer subscription billing automation (see Services\SubscriptionBilling): run it now for the company, the reminders it has
 * sent, and the pro-rata refund of a cancellation (quoted first, then confirmed).
 */
class CustomerSubscriptionBillingController extends Controller
{
    public function __construct(private readonly SubscriptionBilling $billing) {}

    public function run(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptioninvoices/add');

        $user = $request->user();
        $companyId = $user->hasRole('superadmin') ? ($request->integer('company_id') ?: null) : (int) $user->company_id;

        return response()->json(['data' => $this->billing->run($companyId)]);
    }

    public function reminders(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptions');

        $user = $request->user();
        $rows = DB::table('subscription_reminders as r')->join('customer_subscription_invoices as i', 'i.id', '=', 'r.customer_subscription_invoice_id')
            ->when(! $user->hasRole('superadmin'), fn ($q) => $q->where('r.company_id', $user->company_id))
            ->orderByDesc('r.id')->limit(200)->get(['r.id', 'i.invoice_no', 'r.kind', 'r.channel', 'r.status', 'r.created_at']);

        return response()->json(['data' => $rows]);
    }

    public function quote(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptions');

        return response()->json(['data' => $this->billing->quote($this->find($id))]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptions/:id/edit');

        $data = $request->validate(['reason' => 'nullable|string|max:255', 'refund' => 'nullable|boolean']);
        $result = $this->billing->cancelWithRefund($this->find($id), $data['reason'] ?? null, (bool) ($data['refund'] ?? false));

        return response()->json(['message' => 'Subscription cancelled', 'data' => $result]);
    }

    private function find(int $id): CustomerSubscription
    {
        return CustomerSubscription::query()->visibleToCurrentUser()->findOrFail($id);
    }
}
