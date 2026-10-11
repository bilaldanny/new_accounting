<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\PurchaseApprovalRule;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Settings for multi-level purchase approval: amount ranges and the levels each needs (see App\Services\PurchaseApprovals).
 */
class PurchaseApprovalRuleController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/purchaseapprovalrule');

        $rows = PurchaseApprovalRule::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderByDesc('min_amount')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/purchaseapprovalrule/add');
        $companyId = $this->scopeCompanyId($request);

        $rule = PurchaseApprovalRule::query()->create($this->payload($request, $companyId) + ['company_id' => $companyId]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $rule], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/purchaseapprovalrule/:id/edit');

        $rule = PurchaseApprovalRule::query()->visibleToCurrentUser()->findOrFail($id);
        $rule->update($this->payload($request, (int) $rule->company_id));

        return response()->json(['message' => 'Successfully Saved', 'data' => $rule->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/purchaseapprovalrule/delete');

        PurchaseApprovalRule::query()->visibleToCurrentUser()->findOrFail($id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, int $companyId): array
    {
        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'name' => 'bail|required|string|min:2|max:150',
            'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'nullable|numeric|gte:min_amount',
            'levels' => 'required|array|min:1|max:5',
            'levels.*.label' => 'nullable|string|max:60',
            'levels.*.role_id' => 'nullable|integer',
            'forbid_creator' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        $levels = [];

        foreach ($data['levels'] as $index => $level) {
            $roleId = $level['role_id'] ?? null;

            if ($roleId !== null && ! Role::query()->whereKey($roleId)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->exists()) {
                throw ValidationException::withMessages(["levels.{$index}.role_id" => ['Choose a role of this company.']]);
            }

            $levels[] = ['label' => $level['label'] ?? 'Level '.($index + 1), 'role_id' => $roleId === null ? null : (int) $roleId];
        }

        return [
            'name' => $data['name'],
            'min_amount' => $data['min_amount'],
            'max_amount' => $data['max_amount'] ?? null,
            'levels' => $levels,
            'forbid_creator' => (bool) ($data['forbid_creator'] ?? true),
            'priority' => (int) ($data['priority'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
