<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Settings > Subscription Plans: the SaaS plan catalog itself. Superadmin-only, like Company management
 * — a tenant's own users never see or edit this.
 */
class SubscriptionPlanController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function planFormRules(?int $planId = null): array
    {
        $codeRule = Rule::unique('subscription_plans', 'code');

        if ($planId !== null) {
            $codeRule = $codeRule->ignore($planId);
        }

        return [
            'name' => 'bail|required|min:2|max:150',
            'code' => ['bail', 'required', 'string', 'max:50', $codeRule],
            'billing_cycle' => ['bail', 'required', Rule::in(SubscriptionPlan::BILLING_CYCLES)],
            'price' => 'bail|required|numeric|min:0',
            'trial_days' => 'nullable|integer|min:0|max:365',
            'max_users' => 'nullable|integer|min:1',
            'max_branches' => 'nullable|integer|min:1',
            'max_warehouses' => 'nullable|integer|min:1',
            'max_products' => 'nullable|integer|min:1',
            'max_invoices_per_month' => 'nullable|integer|min:1',
            'max_storage_mb' => 'nullable|integer|min:1',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/subscriptionplans');

        $query = SubscriptionPlan::query()
            ->when($request->status && $request->status !== 'all', fn ($q) => $q->where('is_active', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'))
            ->withCount('companies');

        $plans = $this->paginateSorted($query, $this->withSafeSort($request));
        $trash_count = SubscriptionPlan::onlyTrashed()->count();

        return response()->json(['data' => $plans, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/subscriptionplans/add');
        $request->validate($this->planFormRules());

        try {
            SubscriptionPlan::createPlan($request);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show($id): JsonResponse
    {
        $plan = SubscriptionPlan::query()->find($id);

        if ($plan === null) {
            abort(404);
        }

        return response()->json($plan);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/subscriptionplans/:id/edit');
        $request->validate($this->planFormRules((int) $id));

        try {
            SubscriptionPlan::updatePlan($request, $id);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id): JsonResponse
    {
        if (deletepermission('/subscriptionplans/delete')) {
            SubscriptionPlan::deletePlan($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function fetch(): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->select('subscription_plans.*')
            ->selectRaw('name as text')
            ->get();

        return response()->json($plans);
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/subscriptionplans/delete', 'Successfully Deleted', function () use ($request) {
            SubscriptionPlan::query()->whereIn('id', (array) $request->all())->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/subscriptionplans/delete', 'Successfully Deleted', function () use ($request) {
            SubscriptionPlan::onlyTrashed()->whereIn('id', (array) $request->all())->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/subscriptionplans/restore', 'Successfully Restored', function () use ($request) {
            SubscriptionPlan::onlyTrashed()->whereIn('id', (array) $request->all())->restore();
        });
    }

    public function trash(Request $request): JsonResponse
    {
        $query = SubscriptionPlan::onlyTrashed()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'));

        $plans = $this->paginateSorted($query, $this->withSafeSort($request));

        return response()->json(['data' => $plans]);
    }

    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), SubscriptionPlan::SORTABLE, true)) {
            $request->merge(['sort_by' => 'sort_order']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'asc']);
        }

        return $request;
    }
}
