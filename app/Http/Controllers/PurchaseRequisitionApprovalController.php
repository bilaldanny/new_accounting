<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\PurchaseRequisition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approval list, approve and reject for Purchase Requisitions — the same shape as
 * PurchaseApprovalController, plus reject (a Purchase Order itself has no reject step, but a
 * requisition, being a pure internal-control gate, needs one).
 */
class PurchaseRequisitionApprovalController extends Controller
{
    use HandlesIndexAndBulkDelete;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/purchaserequisition/approval');

        $filters = $request->only(['status', 'search', 'company_id', 'branch_id']);
        $filters['status'] ??= 'pending';

        $query = PurchaseRequisition::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'contact:id,business_name,first_name,last_name', 'requestedBy:id,first_name,last_name'])
            ->matchingListFilters($filters);

        $requisitions = $this->paginateSorted($query, $request);
        $requisitions->getCollection()->transform(fn (PurchaseRequisition $requisition) => $requisition->presentForIndex());

        return response()->json(['data' => $requisitions]);
    }

    public function show(int $id): JsonResponse
    {
        $requisition = PurchaseRequisition::findVisible($id);

        if ($requisition === null) {
            abort(404);
        }

        return response()->json($requisition->presentForForm());
    }

    public function approve(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/purchaserequisition/:id/approve');

        PurchaseRequisition::approveRequisition($id);

        return response()->json(['message' => 'Successfully Approved']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/purchaserequisition/:id/reject');

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        PurchaseRequisition::rejectRequisition($id, $validated['reason'] ?? null);

        return response()->json(['message' => 'Successfully Rejected']);
    }
}
