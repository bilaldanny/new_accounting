<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Contact;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionPlan;
use App\Models\CustomerSubscriptionUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Staff-side management of a tenant's customer subscription contracts: create one for a customer,
 * change its plan (Upgrade/Downgrade), pause/resume/cancel it, and record metered usage against it.
 * The customer's own self-service actions on their own contract live in
 * {@see PortalSubscriptionController} instead.
 */
class CustomerSubscriptionController extends Controller
{
    use HandlesIndexAndBulkDelete;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptions');

        CustomerSubscription::flagExpired();

        $query = CustomerSubscription::query()
            ->visibleToCurrentUser()
            ->with(['contact:id,first_name,last_name,business_name', 'plan:id,name,billing_cycle,price'])
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->contact_id));

        $subscriptions = $this->paginateSorted($query, $request);

        $subscriptions->getCollection()->transform(function (CustomerSubscription $subscription) {
            $contact = $subscription->contact;
            $subscription->customer_name = $contact?->business_name ?: trim((string) $contact?->first_name.' '.(string) $contact?->last_name);
            $subscription->plan_name = $subscription->plan?->name;

            return $subscription;
        });

        return response()->json(['data' => $subscriptions]);
    }

    public function show($id): JsonResponse
    {
        $subscription = CustomerSubscription::query()->visibleToCurrentUser()->with(['contact', 'plan', 'invoices'])->find($id);

        if ($subscription === null) {
            abort(404);
        }

        return response()->json($subscription);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptions/add');

        $request->validate([
            'contact_id' => 'bail|required|integer|exists:contacts,id',
            'customer_subscription_plan_id' => 'bail|required|integer|exists:customer_subscription_plans,id',
        ]);

        $contact = Contact::query()->visibleToCurrentUser()->find($request->contact_id);
        $plan = CustomerSubscriptionPlan::query()->visibleToCurrentUser()->find($request->customer_subscription_plan_id);

        if ($contact === null || $plan === null) {
            abort(404);
        }

        $subscription = CustomerSubscription::subscribe((int) $contact->company_id, (int) $contact->id, $plan);

        return response()->json(['message' => 'Subscription created', 'data' => $subscription]);
    }

    public function changePlan(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptions/:id/edit');

        $request->validate([
            'customer_subscription_plan_id' => 'bail|required|integer|exists:customer_subscription_plans,id',
        ]);

        $subscription = CustomerSubscription::query()->visibleToCurrentUser()->find($id);

        if ($subscription === null) {
            abort(404);
        }

        $plan = CustomerSubscriptionPlan::query()->visibleToCurrentUser()->find($request->customer_subscription_plan_id);

        if ($plan === null) {
            abort(404);
        }

        $subscription->changePlan($plan);

        return response()->json(['message' => 'Subscription plan updated']);
    }

    public function pause($id): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptions/:id/edit');

        return $this->applyLifecycle($id, fn (CustomerSubscription $subscription) => $subscription->pause(), 'Subscription paused');
    }

    public function resume($id): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptions/:id/edit');

        return $this->applyLifecycle($id, fn (CustomerSubscription $subscription) => $subscription->resume(), 'Subscription resumed');
    }

    public function cancel(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptions/:id/edit');

        return $this->applyLifecycle($id, fn (CustomerSubscription $subscription) => $subscription->cancel($request->reason), 'Subscription cancelled');
    }

    public function recordUsage(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptions/:id/edit');

        $request->validate([
            'quantity' => 'bail|required|numeric|min:0.01',
            'note' => 'nullable|string|max:200',
        ]);

        $subscription = CustomerSubscription::query()->visibleToCurrentUser()->find($id);

        if ($subscription === null) {
            abort(404);
        }

        if (! $subscription->plan?->is_metered) {
            return response()->json(['errormessage' => 'This subscription is not on a metered plan.'], 422);
        }

        CustomerSubscriptionUsage::record($subscription, (float) $request->quantity, $request->note);

        return response()->json(['message' => 'Usage recorded']);
    }

    private function applyLifecycle($id, callable $action, string $message): JsonResponse
    {
        $subscription = CustomerSubscription::query()->visibleToCurrentUser()->find($id);

        if ($subscription === null) {
            abort(404);
        }

        try {
            $action($subscription);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => $message]);
    }
}
