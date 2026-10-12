<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Services\SaleDocumentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The staff side of the online shop: the shop settings (code, on / off, title, the customer web orders are made out to), which
 * products are published and at what price, and the orders that came in. Reading needs `/storefront`; changing settings and products
 * and handling orders needs `/storefront/manage`. Converting an order makes an ordinary draft sale (SaleDocumentBuilder), so stock and
 * the ledger follow when the sale is finalised the normal way.
 */
class StorefrontController extends Controller
{
    use ScopesToCompany;

    public function settings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/storefront');
        $companyId = $this->scopeCompanyId($request);
        $row = DB::table('storefront_settings')->where('company_id', $companyId)->first();

        return response()->json(['data' => $row === null ? ['code' => null, 'enabled' => false, 'title' => null, 'intro' => null, 'default_contact_id' => null, 'branch_id' => null] : (array) $row]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/storefront/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('storefront_settings', 'code')->where(fn ($q) => $q->where('company_id', '!=', $companyId))],
            'enabled' => 'nullable|boolean', 'title' => 'nullable|string|max:100', 'intro' => 'nullable|string|max:300', 'default_contact_id' => 'nullable|integer', 'branch_id' => 'nullable|integer', 'company_id' => 'nullable|integer',
        ]);

        foreach (['default_contact_id' => 'contacts', 'branch_id' => 'branches'] as $field => $table) {
            if (! empty($data[$field]) && ! DB::table($table)->where('company_id', $companyId)->where('id', (int) $data[$field])->exists()) {
                throw ValidationException::withMessages([$field => ['Choose a record of this company.']]);
            }
        }

        DB::table('storefront_settings')->updateOrInsert(['company_id' => $companyId], [
            'code' => $data['code'], 'enabled' => (bool) ($data['enabled'] ?? false), 'title' => isset($data['title']) ? strip_tags($data['title']) : null, 'intro' => isset($data['intro']) ? strip_tags($data['intro']) : null,
            'default_contact_id' => ! empty($data['default_contact_id']) ? (int) $data['default_contact_id'] : null, 'branch_id' => ! empty($data['branch_id']) ? (int) $data['branch_id'] : null,
            'updated_at' => now(), 'created_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function products(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/storefront');
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => DB::table('storefront_products as s')->join('products as p', 'p.id', '=', 's.product_id')->where('s.company_id', $companyId)->orderBy('p.name')
            ->get(['s.id', 's.product_id', 's.variation_id', 'p.name', 's.price', 's.is_published'])]);
    }

    public function saveProduct(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/storefront/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['product_id' => 'required|integer', 'variation_id' => 'nullable|integer', 'price' => 'nullable|numeric|min:0', 'is_published' => 'nullable|boolean', 'company_id' => 'nullable|integer']);
        $product = Product::query()->where('company_id', $companyId)->where('active', 1)->find((int) $data['product_id']);
        $variation = $product === null ? null : (! empty($data['variation_id'])
            ? ProductDetail::query()->where('product_id', $product->id)->find((int) $data['variation_id'])
            : ProductDetail::query()->where('product_id', $product->id)->orderBy('id')->first());

        if ($product === null || $variation === null) {
            throw ValidationException::withMessages(['product_id' => ['Choose an active product of this company.']]);
        }

        DB::table('storefront_products')->updateOrInsert(['company_id' => $companyId, 'product_id' => $product->id, 'variation_id' => $variation->id], [
            'price' => isset($data['price']) ? round((float) $data['price'], 2) : null, 'is_published' => (bool) ($data['is_published'] ?? true), 'updated_at' => now(), 'created_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function orders(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/storefront');
        $companyId = $this->scopeCompanyId($request);
        $status = (string) $request->query('status', '');

        return response()->json(['data' => DB::table('storefront_orders')->where('company_id', $companyId)->when($status !== '', fn ($q) => $q->where('status', $status))->orderByDesc('id')->limit(300)->get()
            ->map(fn (object $row): array => (array) $row + ['items' => json_decode((string) $row->lines, true)])->all()]);
    }

    public function convert(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/storefront/manage');
        $companyId = $this->scopeCompanyId($request);
        $order = DB::table('storefront_orders')->where('company_id', $companyId)->where('id', $id)->first();
        abort_if($order === null, 404);
        $data = $request->validate(['contact_id' => 'nullable|integer', 'company_id' => 'nullable|integer']);

        if ($order->status !== 'new') {
            throw ValidationException::withMessages(['status' => ['This order was already handled.']]);
        }

        $shop = DB::table('storefront_settings')->where('company_id', $companyId)->first();
        $contactId = ! empty($data['contact_id']) ? (int) $data['contact_id'] : ($shop->default_contact_id ?? null);

        if ($contactId === null || ! DB::table('contacts')->where('company_id', $companyId)->where('id', (int) $contactId)->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['Choose the customer this web order is made out to, or set a default customer in the shop settings.']]);
        }

        $branchId = $this->scopeBranchId($request, $companyId) ?? ($shop->branch_id ?? null);
        $builder = app(SaleDocumentBuilder::class);
        $rows = array_map(fn (array $l): array => ['product_id' => $l['product_id'], 'variation_id' => $l['variation_id'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price']], (array) json_decode((string) $order->lines, true));

        $sale = DB::transaction(function () use ($builder, $companyId, $branchId, $contactId, $rows, $order, $id) {
            $built = $builder->lines($companyId, $branchId === null ? null : (int) $branchId, (int) $contactId, $rows);
            $sale = $builder->create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'contact_id' => $contactId, 'transaction_date' => now()->toDateString(),
                'note' => 'Web order '.$order->order_no.' - '.$order->customer_name.' '.$order->phone.($order->address ? ' - '.$order->address : ''),
            ], $built['lines'], $built['total'], 'draft');
            DB::table('storefront_orders')->where('id', $id)->update(['status' => 'converted', 'sale_id' => $sale->id, 'updated_at' => now()]);

            return $sale;
        });

        return response()->json(['message' => 'Draft sale made', 'data' => ['sale_id' => (int) $sale->id]], 201);
    }

    public function rejectOrder(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/storefront/manage');
        $affected = DB::table('storefront_orders')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->where('status', 'new')->update(['status' => 'rejected', 'updated_at' => now()]);
        abort_if($affected === 0, 404);

        return response()->json(['message' => 'Order rejected']);
    }
}
