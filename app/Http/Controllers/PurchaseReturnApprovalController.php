<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approval Center (Phase 1, easy half): mirrors PurchaseApprovalController exactly, for purchase
 * returns instead of purchase orders.
 */
class PurchaseReturnApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/purchasereturn/approval');
        $status = $request->status ?? 'pending';
        $search = $request->search ?? '';

        $query = Transaction::query()
            ->purchaseReturns()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'contact:id,business_name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where('invoice_no', 'like', "%{$search}%"))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->company_id))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->branch_id));

        $returns = $query->orderByDesc('id')->paginate((int) ($request->show_record ?? 10));

        $returns->getCollection()->transform(fn (Transaction $return) => $return->presentForIndex());

        return response()->json(['data' => $returns]);
    }

    public function approve(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/purchasereturn/approval');

        Transaction::approvePurchaseReturn($id);

        return response()->json(['message' => 'Successfully Approved']);
    }
}
