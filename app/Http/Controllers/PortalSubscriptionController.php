<?php

namespace App\Http\Controllers;

use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionInvoice;
use App\Models\CustomerSubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Customer Self-Service Portal: upgrade/downgrade, pause, resume, cancel — the same signed-in-portal-
 * account mechanism "Settings > Portal Users" already built (`users.contact_id`, `RestrictPortalUsers`
 * middleware). Never takes a contact id or subscription's owner from the request: every lookup is
 * scoped to the signed-in account's own `contact_id`, exactly like {@see PortalController::show()}.
 */
class PortalSubscriptionController extends Controller
{
    public function index(): JsonResponse
    {
        $contactId = $this->contactId();

        $subscriptions = CustomerSubscription::query()
            ->visibleToPortalContact($contactId)
            ->with('plan')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $subscriptions]);
    }

    /**
     * The tenant's own active plans, for the portal's own "change plan" picker.
     */
    public function availablePlans(): JsonResponse
    {
        $user = Auth::user();
        $this->contactId();

        $plans = CustomerSubscriptionPlan::query()
            ->where('company_id', $user?->company_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'price', 'billing_cycle']);

        return response()->json(['data' => $plans]);
    }

    public function invoices(): JsonResponse
    {
        $contactId = $this->contactId();

        $invoices = CustomerSubscriptionInvoice::query()
            ->visibleToPortalContact($contactId)
            ->orderByDesc('due_date')
            ->limit(50)
            ->get();

        return response()->json(['data' => $invoices]);
    }

    public function changePlan(Request $request, $id): JsonResponse
    {
        $request->validate([
            'customer_subscription_plan_id' => 'bail|required|integer|exists:customer_subscription_plans,id',
        ]);

        $subscription = $this->ownSubscription($id);
        $plan = CustomerSubscriptionPlan::query()
            ->where('company_id', $subscription->company_id)
            ->find($request->customer_subscription_plan_id);

        if ($plan === null) {
            abort(404);
        }

        $subscription->changePlan($plan);

        return response()->json(['message' => 'Your plan has been updated.']);
    }

    public function pause($id): JsonResponse
    {
        return $this->applyLifecycle($id, fn (CustomerSubscription $subscription) => $subscription->pause(), 'Your subscription is paused.');
    }

    public function resume($id): JsonResponse
    {
        return $this->applyLifecycle($id, fn (CustomerSubscription $subscription) => $subscription->resume(), 'Your subscription is active again.');
    }

    public function cancel(Request $request, $id): JsonResponse
    {
        return $this->applyLifecycle($id, fn (CustomerSubscription $subscription) => $subscription->cancel($request->reason), 'Your subscription has been cancelled.');
    }

    private function applyLifecycle($id, callable $action, string $message): JsonResponse
    {
        $subscription = $this->ownSubscription($id);

        try {
            $action($subscription);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => $message]);
    }

    private function ownSubscription($id): CustomerSubscription
    {
        $subscription = CustomerSubscription::query()->visibleToPortalContact($this->contactId())->find($id);

        if ($subscription === null) {
            abort(404);
        }

        return $subscription;
    }

    private function contactId(): int
    {
        $user = Auth::user();

        if ($user?->contact_id === null) {
            abort(403, 'There is no portal for this account.');
        }

        return (int) $user->contact_id;
    }
}
