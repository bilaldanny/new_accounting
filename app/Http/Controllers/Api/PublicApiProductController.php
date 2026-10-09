<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/products — read-only, scoped to the API key owner's company exactly like the rest of the
 * app (`visibleToCurrentUser`), authenticated the same way "Settings > API Keys" already issues tokens.
 */
class PublicApiProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->visibleToCurrentUser()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'))
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return response()->json(ProductResource::collection($products)->response()->getData(true));
    }

    public function show($id): JsonResponse
    {
        $product = Product::query()->visibleToCurrentUser()->find($id);

        if ($product === null) {
            abort(404);
        }

        return response()->json(new ProductResource($product));
    }
}
