<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Contact;
use App\Models\EcommerceConnection;
use App\Models\EcommerceOrder;
use App\Services\Ecommerce\EcommerceNotConfigured;
use App\Services\Ecommerce\EcommerceSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * E-commerce order sync: connections to a Shopify or WooCommerce shop (credentials are stored encrypted and never returned),
 * a manual sync that imports new orders as draft sales, and the orders with what became of them. The built-in `mock` platform
 * has its orders in its settings and needs no keys; the real platforms need their credentials and are not live until configured.
 */
class EcommerceConnectionController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/ecommerce');

        $rows = EcommerceConnection::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderBy('name')->get();

        $counts = EcommerceOrder::query()->whereIn('ecommerce_connection_id', $rows->pluck('id'))->selectRaw('ecommerce_connection_id, status, count(*) as c')->groupBy('ecommerce_connection_id', 'status')->get();

        return response()->json(['data' => $rows->map(fn (EcommerceConnection $connection): array => $connection->toArray() + [
            'configured' => $this->configured($connection),
            'orders' => $counts->where('ecommerce_connection_id', $connection->id)->pluck('c', 'status'),
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/ecommerce/add');

        $data = $this->validated($request);
        $companyId = $this->scopeCompanyId($request);
        $this->assertContact($companyId, $data['default_contact_id'] ?? null);

        $connection = EcommerceConnection::query()->create(collect($data)->except(['company_id', 'branch_id'])->all() + ['company_id' => $companyId, 'branch_id' => $this->scopeBranchId($request, $companyId)]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $connection], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/ecommerce/:id/edit');

        $connection = EcommerceConnection::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $this->validated($request);
        $this->assertContact((int) $connection->company_id, $data['default_contact_id'] ?? null);

        if (blank($data['credentials'] ?? null)) {
            unset($data['credentials']);
        }

        $connection->update(collect($data)->except(['company_id', 'branch_id'])->all());

        return response()->json(['message' => 'Successfully Saved', 'data' => $connection->fresh()]);
    }

    public function sync(int $id, EcommerceSync $sync): JsonResponse
    {
        $this->authorizeMenuPermission('/ecommerce/sync');

        $connection = EcommerceConnection::query()->visibleToCurrentUser()->findOrFail($id);

        if (! $connection->is_active) {
            throw ValidationException::withMessages(['connection' => ['This connection is switched off.']]);
        }

        try {
            $result = $sync->sync($connection);
        } catch (EcommerceNotConfigured $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['connection' => [$e->getMessage()]]], 422);
        }

        return response()->json(['message' => "Fetched {$result['fetched']} orders: {$result['imported']} imported, {$result['failed']} failed, {$result['skipped']} already imported.", 'data' => $result]);
    }

    public function orders(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/ecommerce');

        $connection = EcommerceConnection::query()->visibleToCurrentUser()->findOrFail($id);

        return response()->json(['data' => EcommerceOrder::query()->where('ecommerce_connection_id', $connection->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))->orderByDesc('id')->limit(200)->get()]);
    }

    public function retry(int $id, int $orderId, EcommerceSync $sync): JsonResponse
    {
        $this->authorizeMenuPermission('/ecommerce/sync');

        $connection = EcommerceConnection::query()->visibleToCurrentUser()->findOrFail($id);
        $order = EcommerceOrder::query()->where('ecommerce_connection_id', $connection->id)->findOrFail($orderId);

        if ($order->status === 'imported') {
            throw ValidationException::withMessages(['order' => ['This order was already imported.']]);
        }

        $ok = $sync->import($connection, $order);

        return response()->json(['message' => $ok ? 'Order imported' : 'The order still cannot be imported.', 'data' => $order->fresh()], $ok ? 200 : 422);
    }

    private function configured(EcommerceConnection $connection): bool
    {
        $credentials = (array) $connection->credentials;

        return match ($connection->platform) {
            'shopify' => filled($connection->store_url) && filled($credentials['access_token'] ?? null),
            'woocommerce' => filled($connection->store_url) && filled($credentials['consumer_key'] ?? null) && filled($credentials['consumer_secret'] ?? null),
            default => true,
        };
    }

    private function assertContact(int $companyId, mixed $contactId): void
    {
        if ($contactId !== null && ! Contact::query()->where('company_id', $companyId)->whereIn('user_type', ['customer', 'both'])->whereKey($contactId)->exists()) {
            throw ValidationException::withMessages(['default_contact_id' => ['Choose a customer of this company.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'platform' => ['required', Rule::in(EcommerceConnection::PLATFORMS)],
            'name' => 'bail|required|string|min:2|max:120',
            'store_url' => 'nullable|url|max:255',
            'credentials' => 'nullable|array',
            'default_contact_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
