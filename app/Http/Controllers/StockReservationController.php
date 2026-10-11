<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\StockReservation;
use App\Services\StockMovements;
use App\Services\StockReservations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Stock reservation, allocation and release: hold a quantity of a product for a customer or an order, see what is
 * available (on hand less what is held), and release or fulfil the hold. A reservation changes no stock; it only reduces
 * what a sale may take when the company has switched `reservation_mode` to `enforce`.
 */
class StockReservationController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/stockreservation');

        $rows = StockReservation::query()
            ->visibleToCurrentUser()
            ->with(['product:id,name,sku', 'contact:id,business_name,first_name'])
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return response()->json(['data' => $rows]);
    }

    /**
     * On hand, held and available for one variation in a branch.
     */
    public function availability(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/stockreservation');

        $request->validate(['product_id' => 'required|integer', 'variation_id' => 'nullable|integer', 'branch_id' => 'nullable|integer', 'company_id' => 'nullable|integer']);

        $product = Product::query()->visibleToCurrentUser()->findOrFail($request->integer('product_id'));
        $variation = $request->filled('variation_id')
            ? ProductDetail::query()->where('product_id', $product->id)->findOrFail($request->integer('variation_id'))
            : ProductDetail::query()->where('product_id', $product->id)->orderBy('id')->firstOrFail();
        $branchId = $this->scopeBranchId($request, (int) $product->company_id);

        $onHand = StockMovements::baseStock((int) $product->id, (int) $variation->id, $branchId);
        $held = app(StockReservations::class)->heldForOthers((int) $product->company_id, (int) $product->id, (int) $variation->id, $branchId, null);

        return response()->json([
            'on_hand' => round($onHand, 4),
            'reserved' => $held,
            'available' => round($onHand - $held, 4),
            'enforced' => StockReservations::enforced((int) $product->company_id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/stockreservation/add');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'product_id' => 'required|integer',
            'variation_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'quantity' => 'required|numeric|gt:0|max:1000000000',
            'reference' => 'nullable|string|max:100',
            'expires_on' => 'nullable|date_format:Y-m-d',
            'note' => 'nullable|string|max:1000',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        $product = Product::query()->where('company_id', $companyId)->find($data['product_id']);

        if ($product === null || ! StockMovements::productKeepsStock((int) $product->id)) {
            throw ValidationException::withMessages(['product_id' => ['Choose a stocked product of this company.']]);
        }

        $variationId = $data['variation_id'] ?? null;

        if ($variationId !== null && ! ProductDetail::query()->where('product_id', $product->id)->whereKey($variationId)->exists()) {
            throw ValidationException::withMessages(['variation_id' => ['That variation does not belong to this product.']]);
        }

        if (isset($data['contact_id']) && ! Contact::query()->where('company_id', $companyId)->whereKey($data['contact_id'])->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a customer of this company.']]);
        }

        $reservation = StockReservation::query()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'variation_id' => $variationId,
            'contact_id' => $data['contact_id'] ?? null,
            'quantity' => $data['quantity'],
            'reference' => $data['reference'] ?? null,
            'status' => 'active',
            'expires_on' => $data['expires_on'] ?? null,
            'note' => $data['note'] ?? null,
            'reserved_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $reservation], 201);
    }

    /**
     * Releases (the customer no longer needs it) or fulfils (it went out on a sale) an active reservation.
     */
    public function close(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/stockreservation/:id/edit');

        $data = $request->validate(['outcome' => 'required|in:released,fulfilled']);

        $reservation = StockReservation::query()->visibleToCurrentUser()->findOrFail($id);

        if ($reservation->status !== 'active') {
            throw ValidationException::withMessages(['status' => ['This reservation is already closed.']]);
        }

        $reservation->update(['status' => $data['outcome'], 'closed_by' => Auth::id(), 'closed_at' => now()]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $reservation->fresh()]);
    }
}
