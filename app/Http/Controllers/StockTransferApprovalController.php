<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approval Center (Phase 1, easy half): mirrors PurchaseApprovalController exactly, for stock
 * transfers instead of purchase orders.
 */
class StockTransferApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/stocktransfer/approval');
        $status = $request->status ?? 'pending';
        $search = $request->search ?? '';

        $query = Transaction::query()
            ->transfers()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'tobranch:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where('invoice_no', 'like', "%{$search}%"))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->company_id))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->branch_id));

        $transfers = $query->orderByDesc('id')->paginate((int) ($request->show_record ?? 10));

        $transfers->getCollection()->transform(fn (Transaction $transfer) => $transfer->presentForIndex());

        return response()->json(['data' => $transfers]);
    }

    public function approve(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/stocktransfer/approval');

        Transaction::approveTransfer($id);

        return response()->json(['message' => 'Successfully Approved']);
    }
}
