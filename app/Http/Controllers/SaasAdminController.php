<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SubscriptionInvoice;
use App\Services\Saas\PlanFeatures;
use App\Services\Saas\TenantLifecycle;
use App\Services\Saas\TenantServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The platform side of the SaaS billing wave, for the superadmin only: plan feature switches and package settings, usage history
 * of any tenant, add-ons, partners and their commission statement, the billing run and the due deletions.
 */
class SaasAdminController extends Controller
{
    public function __construct(private readonly TenantLifecycle $lifecycle, private readonly TenantServices $services) {}

    public function overview(Request $request): JsonResponse
    {
        $this->superadmin($request);

        return response()->json(['data' => [
            'features' => collect(PlanFeatures::CATALOG)->map(fn (array $d, string $key): array => ['key' => $key, 'label' => $d[0]])->values(),
            'plans' => DB::table('subscription_plans')->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'code', 'price', 'features', 'settings'])
                ->map(fn (object $p): array => (array) $p + ['features' => $p->features === null ? null : json_decode((string) $p->features, true), 'settings' => json_decode((string) $p->settings, true)])->all(),
            'partners' => DB::table('partners')->orderBy('name')->get(),
            'addons' => DB::table('addons')->orderBy('name')->get(),
        ]]);
    }

    public function savePlan(Request $request, int $id): JsonResponse
    {
        $this->superadmin($request);

        $data = $request->validate([
            'features' => 'present|nullable|array', 'features.*' => ['string', Rule::in(array_keys(PlanFeatures::CATALOG))],
            'settings' => 'nullable|array',
        ]);
        abort_unless(DB::table('subscription_plans')->where('id', $id)->exists(), 404);

        DB::table('subscription_plans')->where('id', $id)->update([
            'features' => $data['features'] === null ? null : json_encode(array_values(array_unique($data['features']))),
            'settings' => $data['settings'] === null || ! isset($data['settings']) ? null : json_encode($data['settings']),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function usage(Request $request, int $companyId): JsonResponse
    {
        $this->superadmin($request);
        abort_unless(Company::query()->whereKey($companyId)->exists(), 404);

        return response()->json(['data' => $this->lifecycle->trend($companyId, min(max($request->integer('days', 30), 1), 365))]);
    }

    public function snapshot(Request $request): JsonResponse
    {
        $this->superadmin($request);

        return response()->json(['data' => ['companies' => $this->lifecycle->snapshotAll()]]);
    }

    public function savePartner(Request $request, ?int $id = null): JsonResponse
    {
        $this->superadmin($request);

        $data = $request->validate(['name' => 'required|string|min:2|max:120', 'email' => 'nullable|email|max:190', 'commission_percent' => 'required|numeric|min:0|max:100', 'is_active' => 'nullable|boolean']);
        $row = $data + ['is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now()];

        if ($id === null) {
            $id = DB::table('partners')->insertGetId($row + ['created_at' => now()]);
        } else {
            abort_unless(DB::table('partners')->where('id', $id)->update($row) || DB::table('partners')->where('id', $id)->exists(), 404);
        }

        return response()->json(['message' => 'Successfully Saved', 'id' => $id], 201);
    }

    public function assignPartner(Request $request, int $companyId): JsonResponse
    {
        $this->superadmin($request);

        $data = $request->validate(['partner_id' => 'nullable|integer']);
        abort_unless(Company::query()->whereKey($companyId)->exists(), 404);

        if (! empty($data['partner_id']) && ! DB::table('partners')->where('id', $data['partner_id'])->exists()) {
            throw ValidationException::withMessages(['partner_id' => ['Choose an existing partner.']]);
        }

        DB::table('companies')->where('id', $companyId)->update(['partner_id' => $data['partner_id'] ?? null, 'updated_at' => now()]);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function statement(Request $request, int $partnerId): JsonResponse
    {
        $this->superadmin($request);

        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);

        return response()->json(['data' => $this->services->commissionStatement($partnerId, $data['from'], $data['to'])]);
    }

    public function saveAddon(Request $request, ?int $id = null): JsonResponse
    {
        $this->superadmin($request);

        $data = $request->validate(['name' => 'required|string|min:2|max:120', 'code' => ['required', 'string', 'max:40', Rule::unique('addons', 'code')->ignore($id)], 'price' => 'required|numeric|min:0', 'is_active' => 'nullable|boolean']);
        $row = $data + ['is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now()];

        if ($id === null) {
            $id = DB::table('addons')->insertGetId($row + ['created_at' => now()]);
        } else {
            abort_unless(DB::table('addons')->where('id', $id)->exists(), 404);
            DB::table('addons')->where('id', $id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved', 'id' => $id], 201);
    }

    public function assignAddon(Request $request, int $companyId): JsonResponse
    {
        $this->superadmin($request);

        $data = $request->validate(['addon_id' => 'required|integer|exists:addons,id', 'quantity' => 'nullable|integer|min:1|max:1000', 'starts_on' => 'nullable|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d']);
        abort_unless(Company::query()->whereKey($companyId)->exists(), 404);

        $id = DB::table('tenant_addons')->insertGetId([
            'company_id' => $companyId, 'addon_id' => $data['addon_id'], 'quantity' => $data['quantity'] ?? 1, 'starts_on' => $data['starts_on'] ?? now()->toDateString(),
            'ends_on' => $data['ends_on'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Add-on assigned', 'id' => $id], 201);
    }

    public function removeAddon(Request $request, int $companyId, int $id): JsonResponse
    {
        $this->superadmin($request);

        $changed = DB::table('tenant_addons')->where('company_id', $companyId)->where('id', $id)->update(['ends_on' => now()->subDay()->toDateString(), 'updated_at' => now()]);
        abort_if($changed === 0, 404);

        return response()->json(['message' => 'Add-on ended']);
    }

    public function runBilling(Request $request): JsonResponse
    {
        $this->superadmin($request);

        return response()->json(['data' => $this->lifecycle->runBilling($request->integer('company_id') ?: null)]);
    }

    public function purgeDue(Request $request): JsonResponse
    {
        $this->superadmin($request);

        return response()->json(['data' => $this->lifecycle->purgeDue($request->integer('company_id') ?: null)]);
    }

    /**
     * A superadmin's mark-paid also brings a company suspended for non-payment back, through the same call.
     */
    public function reactivate(Request $request, int $companyId): JsonResponse
    {
        $this->superadmin($request);

        return response()->json(['data' => ['reactivated' => $this->lifecycle->reactivateIfSettled($companyId), 'unpaid' => SubscriptionInvoice::query()->where('company_id', $companyId)->whereIn('status', ['unpaid', 'overdue'])->count()]]);
    }

    private function superadmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('superadmin'), 403);
    }
}
