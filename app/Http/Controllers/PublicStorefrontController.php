<?php

namespace App\Http\Controllers;

use App\Services\PriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public shop of a company (`/shop/{code}`), for anyone, no sign-in. It is tenant-scoped by the shop code the company chose and
 * switched on; it shows only the products the company published, with the price the shop would charge, and takes an order as a
 * request: the order is stored apart from the books and stock until staff turn it into a sale. Prices and totals are always worked out
 * on the server, quantities are bounded, a filled honeypot looks like success, and the routes are rate limited.
 */
class PublicStorefrontController extends Controller
{
    public function page(string $code): Response
    {
        $shop = $this->shop($code);

        return Inertia::render('public/storefront', ['code' => $code, 'title' => $shop->title ?: 'Online shop']);
    }

    public function catalog(string $code): JsonResponse
    {
        $shop = $this->shop($code);
        $prices = app(PriceResolver::class);

        $products = DB::table('storefront_products as s')->join('products as p', 'p.id', '=', 's.product_id')->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('s.company_id', $shop->company_id)->where('s.is_published', true)->where('p.company_id', $shop->company_id)->where('p.active', 1)->whereNull('p.deleted_at')
            ->orderBy('p.name')->limit(300)->get(['s.product_id', 's.variation_id', 's.price', 'p.name', 'u.short_name as unit'])
            ->map(function (object $row) use ($prices, $shop): array {
                $price = $row->price !== null ? (float) $row->price : (float) $prices->resolve((int) $shop->company_id, $shop->branch_id === null ? null : (int) $shop->branch_id, null, (int) $row->product_id, (int) $row->variation_id, 1)['price'];

                return ['product_id' => (int) $row->product_id, 'variation_id' => (int) $row->variation_id, 'name' => $row->name, 'unit' => $row->unit, 'price' => round($price, 2)];
            })->all();

        return response()->json(['data' => ['title' => $shop->title ?: 'Online shop', 'intro' => $shop->intro, 'products' => $products]]);
    }

    public function order(Request $request, string $code): JsonResponse
    {
        $shop = $this->shop($code);
        $data = $request->validate([
            'name' => 'bail|required|string|min:2|max:100', 'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'], 'email' => 'nullable|email|max:150',
            'address' => 'nullable|string|max:255', 'note' => 'nullable|string|max:300',
            'lines' => 'required|array|min:1|max:30', 'lines.*.product_id' => 'required|integer', 'lines.*.variation_id' => 'required|integer', 'lines.*.quantity' => 'required|integer|min:1|max:1000',
            'website_url' => 'nullable|string|max:500',
        ]);

        if ($request->filled('website_url')) {
            return response()->json(['message' => 'Thank you. We will call you to confirm your order.']);
        }

        $prices = app(PriceResolver::class);
        $lines = [];
        $total = 0.0;
        $names = [];

        foreach ($data['lines'] as $index => $row) {
            $listed = DB::table('storefront_products as s')->join('products as p', 'p.id', '=', 's.product_id')->where('s.company_id', $shop->company_id)->where('s.is_published', true)
                ->where('s.product_id', (int) $row['product_id'])->where('s.variation_id', (int) $row['variation_id'])->where('p.active', 1)->first(['s.price', 'p.name']);

            if ($listed === null) {
                return response()->json(['message' => 'An item is not on sale any more.', 'errors' => ["lines.{$index}.product_id" => ['This item is not on sale.']]], 422);
            }

            $price = $listed->price !== null ? (float) $listed->price : (float) $prices->resolve((int) $shop->company_id, $shop->branch_id === null ? null : (int) $shop->branch_id, null, (int) $row['product_id'], (int) $row['variation_id'], (int) $row['quantity'])['price'];
            $key = $row['product_id'].':'.$row['variation_id'];
            $names[$key] = $listed->name;

            if (isset($lines[$key])) {
                $lines[$key]['quantity'] += (int) $row['quantity'];
            } else {
                $lines[$key] = ['product_id' => (int) $row['product_id'], 'variation_id' => (int) $row['variation_id'], 'name' => $listed->name, 'quantity' => (int) $row['quantity'], 'unit_price' => round($price, 2)];
            }
        }

        foreach ($lines as $line) {
            $total += $line['unit_price'] * $line['quantity'];
        }

        $number = DB::transaction(function () use ($shop, $data, $lines, $total, $request): string {
            $next = (int) DB::table('storefront_orders')->where('company_id', $shop->company_id)->count() + 1;

            do {
                $number = 'WEB-'.str_pad((string) $next++, 5, '0', STR_PAD_LEFT);
            } while (DB::table('storefront_orders')->where('company_id', $shop->company_id)->where('order_no', $number)->exists());

            DB::table('storefront_orders')->insert([
                'company_id' => $shop->company_id, 'order_no' => $number, 'customer_name' => strip_tags($data['name']), 'phone' => $data['phone'], 'email' => $data['email'] ?? null,
                'address' => isset($data['address']) ? strip_tags($data['address']) : null, 'note' => isset($data['note']) ? strip_tags($data['note']) : null,
                'lines' => json_encode(array_values($lines)), 'total' => round($total, 2), 'status' => 'new', 'ip' => $request->ip(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $number;
        });

        return response()->json(['message' => 'Thank you. We will call you to confirm your order.', 'data' => ['order_no' => $number, 'total' => round($total, 2)]], 201);
    }

    private function shop(string $code): object
    {
        $shop = DB::table('storefront_settings as s')->join('companies as c', 'c.id', '=', 's.company_id')->where('s.code', $code)->where('s.enabled', true)->where('c.is_active', 1)
            ->first(['s.company_id', 's.title', 's.intro', 's.branch_id']);
        abort_if($shop === null, 404);

        return $shop;
    }
}
