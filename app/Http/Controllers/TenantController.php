<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Company;
use App\Models\SubscriptionPlan;
use App\Services\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Billing > Tenant Directory: every company as a SaaS tenant — its plan, status, trial/period dates and
 * usage against its limits. Superadmin-only, read-mostly over the same `companies` rows the existing
 * Company Management screen edits; this screen only ever touches `tenant_status` and
 * `subscription_plan_id`, never a tenant's own profile fields.
 */
class TenantController extends Controller
{
    use HandlesIndexAndBulkDelete;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/tenants');
        $this->authorizeSuperadmin($request);

        $query = Company::query()
            ->with('subscriptionPlan:id,name,billing_cycle,price')
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('tenant_status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'));

        $tenants = $this->paginateSorted($query, $request);

        $tenants->getCollection()->transform(function (Company $company) {
            $company->plan_name = $company->subscriptionPlan?->name;
            $company->users_count = $company->users()->count();
            $company->branches_count = $company->branches()->count();

            return $company;
        });

        return response()->json(['data' => $tenants]);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/tenants');
        $this->authorizeSuperadmin($request);

        $company = Company::query()->with('subscriptionPlan')->find($id);

        if ($company === null) {
            abort(404);
        }

        $company->users_count = $company->users()->count();
        $company->branches_count = $company->branches()->count();
        $company->invoices_this_month = $company->invoicesThisMonth();
        $company->storage_used_mb = round($company->storageUsedBytes() / 1024 / 1024, 2);

        return response()->json($company);
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/tenants/:id/edit');
        $this->authorizeSuperadmin($request);

        $request->validate([
            'status' => ['bail', 'required', Rule::in(Company::TENANT_STATUSES)],
        ]);

        $company = Company::query()->find($id);

        if ($company === null) {
            abort(404);
        }

        try {
            $status = $request->string('status')->toString();
            Company::assertValidTenantStatus($status);
            $company->tenant_status = $status;
            $company->save();

            if ($status === 'cancelled') {
                WebhookDispatcher::fire((int) $company->id, 'subscription.cancelled', [
                    'company_id' => $company->id,
                    'cancelled_at' => now()->toIso8601String(),
                ]);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Tenant status updated.']);
    }

    /**
     * Upgrade / Downgrade / Plan Change — assigns (or switches) the tenant's subscription plan.
     */
    public function changePlan(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/tenants/:id/edit');
        $this->authorizeSuperadmin($request);

        $request->validate([
            'subscription_plan_id' => 'bail|required|integer|exists:subscription_plans,id',
        ]);

        $company = Company::query()->find($id);

        if ($company === null) {
            abort(404);
        }

        $plan = SubscriptionPlan::query()->findOrFail($request->subscription_plan_id);
        $company->changePlan($plan);

        return response()->json(['message' => 'Tenant plan updated.']);
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
