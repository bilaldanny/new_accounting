<?php

namespace App\Http\Controllers;

use App\Models\PriceList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approval Center (Phase 1, easy half): mirrors PurchaseApprovalController exactly, for price lists
 * instead of purchase orders — a dedicated approve action under its own permission, distinct from the
 * general `/pricelist/:id/edit` permission.
 */
class PriceListApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/pricelist/approval');
        $search = $request->search ?? '';

        $query = PriceList::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'brand:id,name', 'contact:id,first_name,last_name,business_name'])
            ->where('status', 'pending')
            ->when($search, fn ($q) => $q->whereHas('brand', fn ($sub) => $sub->where('name', 'like', "%{$search}%")))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->company_id))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->branch_id));

        $priceLists = $query->orderByDesc('id')->paginate((int) ($request->show_record ?? 10));

        $priceLists->getCollection()->transform(fn (PriceList $priceList) => $priceList->presentForIndex());

        return response()->json(['data' => $priceLists]);
    }

    public function approve(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/pricelist/approval');

        PriceList::approve($id);

        return response()->json(['message' => 'Successfully Approved']);
    }
}
