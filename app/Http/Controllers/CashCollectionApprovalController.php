<?php

namespace App\Http\Controllers;

use App\Models\CashCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approval Center (Phase 1, easy half): mirrors PurchaseApprovalController exactly, for cash
 * collections instead of purchase orders. Approving only stamps a review — it does not change
 * `status` or touch `CashCollectionController::complete()`'s posting logic.
 */
class CashCollectionApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/cashcollection/approval');
        $search = $request->search ?? '';

        $query = CashCollection::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'contact:id,business_name'])
            ->where('status', CashCollection::STATUS_PENDING)
            ->when($search, fn ($q) => $q->where('reference', 'like', "%{$search}%"))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->company_id))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->branch_id));

        $collections = $query->orderByDesc('id')->paginate((int) ($request->show_record ?? 10));

        $collections->getCollection()->transform(fn (CashCollection $collection) => [
            'id' => $collection->id,
            'reference' => $collection->reference,
            'company_name' => $collection->company?->name,
            'branch_name' => $collection->branch?->name,
            'contact_name' => $collection->contact?->business_name,
            'collected_on' => $collection->collected_on?->format('Y-m-d'),
            'amount' => $collection->amount,
            'status' => $collection->status,
            'approved_by' => $collection->approved_by,
            'approved_at' => $collection->approved_at?->format('Y-m-d H:i'),
        ]);

        return response()->json(['data' => $collections]);
    }

    public function approve(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/cashcollection/approval');

        CashCollection::approve($id);

        return response()->json(['message' => 'Successfully Approved']);
    }
}
