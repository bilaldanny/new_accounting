<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\PriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The selling price of a variation for a customer and quantity (see PriceResolver), for the sale screens.
 */
class PricingController extends Controller
{
    public function __construct(private readonly PriceResolver $resolver) {}

    public function resolve(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/sell');

        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'variation_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'quantity' => 'nullable|numeric|gt:0',
        ]);

        $user = Auth::user();
        $product = Product::query()->findOrFail($data['product_id']);

        abort_if(! $user->hasRole('superadmin') && (int) $product->company_id !== (int) $user->company_id, 404);

        $branchId = $user->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin')
            ? (int) $user->branch_id
            : ($data['branch_id'] ?? null);

        return response()->json(['data' => $this->resolver->resolve(
            (int) $product->company_id,
            $branchId === null ? null : (int) $branchId,
            isset($data['contact_id']) ? (int) $data['contact_id'] : null,
            (int) $product->id,
            isset($data['variation_id']) ? (int) $data['variation_id'] : null,
            (float) ($data['quantity'] ?? 1),
        )]);
    }
}
