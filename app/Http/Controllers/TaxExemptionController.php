<?php

namespace App\Http\Controllers;

use App\Models\Tax;
use App\Models\TaxExemption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tax exemption rules: customers (or suppliers) and items that are charged no tax, for one tax or all of them.
 */
class TaxExemptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/taxexemption');

        $rules = TaxExemption::query()
            ->visibleToCurrentUser()
            ->with(['contact:id,business_name,first_name,last_name', 'product:id,name', 'tax:id,name'])
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->input('scope')))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->input('search').'%')->orWhere('certificate_no', 'like', '%'.$request->input('search').'%')))
            ->orderByDesc('id')
            ->paginate(min((int) ($request->input('show_record') ?: 25), 200));

        $rules->getCollection()->transform(fn (TaxExemption $rule) => $this->present($rule));

        return response()->json(['data' => $rules]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/taxexemption');

        return response()->json(['data' => $this->present(TaxExemption::query()->visibleToCurrentUser()->findOrFail($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/taxexemption/add');

        $companyId = (int) ($request->user()?->company_id ?: $request->integer('company_id'));

        if ($companyId === 0) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        $rule = TaxExemption::query()->create($this->validated($request, $companyId) + ['company_id' => $companyId]);

        return response()->json(['message' => 'Exemption saved', 'data' => $this->present($rule)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/taxexemption/:id/edit');

        $rule = TaxExemption::query()->visibleToCurrentUser()->findOrFail($id);
        $rule->update($this->validated($request, (int) $rule->company_id));

        return response()->json(['message' => 'Exemption saved', 'data' => $this->present($rule->refresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/taxexemption/delete');

        $rule = TaxExemption::query()->visibleToCurrentUser()->findOrFail($id);

        if ($this->isUsed($rule)) {
            throw ValidationException::withMessages(['exemption' => ['Sales or purchases were exempted by this rule, so it cannot be deleted. Switch it off instead.']]);
        }

        $rule->delete();

        return response()->json(['message' => 'Exemption deleted']);
    }

    private function isUsed(TaxExemption $rule): bool
    {
        return DB::table('sell_lines')->where('tax_exemption_id', $rule->id)->exists()
            || DB::table('purchase_lines')->where('tax_exemption_id', $rule->id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TaxExemption $rule): array
    {
        $rule->loadMissing(['contact:id,business_name,first_name,last_name', 'product:id,name', 'tax:id,name']);

        return [
            'id' => $rule->id,
            'company_id' => $rule->company_id,
            'name' => $rule->name,
            'scope' => $rule->scope,
            'contact_id' => $rule->contact_id,
            'contact_name' => $rule->contact?->business_name ?: trim($rule->contact?->first_name.' '.$rule->contact?->last_name),
            'product_id' => $rule->product_id,
            'product_name' => $rule->product?->name,
            'tax_id' => $rule->tax_id,
            'tax_name' => $rule->tax?->name,
            'certificate_no' => $rule->certificate_no,
            'reason' => $rule->reason,
            'valid_from' => $rule->valid_from?->toDateString(),
            'valid_to' => $rule->valid_to?->toDateString(),
            'is_active' => (bool) $rule->is_active,
            'used' => $this->isUsed($rule),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $companyId): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'scope' => ['required', Rule::in([TaxExemption::SCOPE_CUSTOMER, TaxExemption::SCOPE_ITEM])],
            'contact_id' => ['nullable', 'required_if:scope,customer', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'product_id' => ['nullable', 'required_if:scope,item', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'tax_id' => ['nullable', 'integer', Rule::exists('taxes', 'id')->where('company_id', $companyId)->where('kind', Tax::KIND_SALES)],
            'certificate_no' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:255',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'nullable|boolean',
        ]);

        $isCustomer = $data['scope'] === TaxExemption::SCOPE_CUSTOMER;

        return [
            'name' => $data['name'],
            'scope' => $data['scope'],
            'contact_id' => $isCustomer ? $data['contact_id'] : null,
            'product_id' => $isCustomer ? null : $data['product_id'],
            'tax_id' => $data['tax_id'] ?? null,
            'certificate_no' => $data['certificate_no'] ?? null,
            'reason' => $data['reason'] ?? null,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ];
    }
}
