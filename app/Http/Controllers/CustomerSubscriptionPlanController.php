<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\CustomerSubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A tenant's own subscription product catalog (Settings-style master data, company-scoped — see
 * {@see CustomerSubscriptionPlan}'s own docblock for how this differs from Module 17's global plans).
 */
class CustomerSubscriptionPlanController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function planFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'name' => 'bail|required|min:2|max:150',
            'code' => 'bail|required|string|max:50',
            'billing_cycle' => ['bail', 'required', Rule::in(CustomerSubscriptionPlan::BILLING_CYCLES)],
            'price' => 'bail|required|numeric|min:0',
            'setup_fee' => 'nullable|numeric|min:0',
            'trial_days' => 'nullable|integer|min:0|max:365',
            'is_metered' => 'nullable|boolean',
            'unit_label' => 'nullable|string|max:50',
            'included_units' => 'nullable|integer|min:0',
            'overage_rate' => 'nullable|numeric|min:0',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionplans');

        $query = CustomerSubscriptionPlan::query()
            ->visibleToCurrentUser()
            ->when($request->status && $request->status !== 'all', fn ($q) => $q->where('is_active', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'))
            ->withCount('subscriptions');

        $plans = $this->paginateSorted($query, $this->withSafeSort($request));
        $trash_count = CustomerSubscriptionPlan::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $plans, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionplans/add');
        $request->validate($this->planFormRules());

        try {
            CustomerSubscriptionPlan::createPlan($request);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show($id): JsonResponse
    {
        $plan = CustomerSubscriptionPlan::query()->visibleToCurrentUser()->find($id);

        if ($plan === null) {
            abort(404);
        }

        return response()->json($plan);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionplans/:id/edit');
        $request->validate($this->planFormRules());

        try {
            CustomerSubscriptionPlan::updatePlan($request, $id);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id): JsonResponse
    {
        if (deletepermission('/customersubscriptionplans/delete')) {
            CustomerSubscriptionPlan::deletePlan($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/customersubscriptionplans/delete', 'Successfully Deleted', function () use ($request) {
            CustomerSubscriptionPlan::query()->visibleToCurrentUser()->whereIn('id', (array) $request->all())->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/customersubscriptionplans/delete', 'Successfully Deleted', function () use ($request) {
            CustomerSubscriptionPlan::onlyTrashed()->visibleToCurrentUser()->whereIn('id', (array) $request->all())->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/customersubscriptionplans/restore', 'Successfully Restored', function () use ($request) {
            CustomerSubscriptionPlan::onlyTrashed()->visibleToCurrentUser()->whereIn('id', (array) $request->all())->restore();
        });
    }

    public function trash(Request $request): JsonResponse
    {
        $plans = $this->paginateSorted(CustomerSubscriptionPlan::onlyTrashed()->visibleToCurrentUser(), $this->withSafeSort($request));

        return response()->json(['data' => $plans]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $plans = CustomerSubscriptionPlan::query()
            ->visibleToCurrentUser()
            ->where('is_active', true)
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->select('customer_subscription_plans.*')
            ->selectRaw('name as text')
            ->orderBy('sort_order')
            ->get();

        return response()->json($plans);
    }

    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), CustomerSubscriptionPlan::SORTABLE, true)) {
            $request->merge(['sort_by' => 'sort_order']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'asc']);
        }

        return $request;
    }
}
