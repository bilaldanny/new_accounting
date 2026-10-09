<?php

namespace App\Http\Controllers;

use App\Models\CreditLimitRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approval Center (Phase 1, easy half) — Credit Limit: approve/reject, mirroring the
 * VoucherApprovalController family (the only existing approval pattern with a reject action).
 */
class CreditLimitApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/creditlimit/approval');
        $status = $request->status ?? 'pending';
        $search = $request->search ?? '';

        $query = CreditLimitRequest::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'contact:id,first_name,last_name,business_name', 'requestedBy:id,first_name,last_name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->whereHas('contact', fn ($sub) => $sub->where('business_name', 'like', "%{$search}%")))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->company_id))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->branch_id));

        $requests = $query->orderByDesc('id')->paginate((int) ($request->show_record ?? 10));

        $requests->getCollection()->transform(fn (CreditLimitRequest $creditLimitRequest) => $creditLimitRequest->presentForIndex());

        return response()->json(['data' => $requests]);
    }

    public function approve(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/creditlimit/approval');

        CreditLimitRequest::approve($id);

        return response()->json(['message' => 'Successfully Approved']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/creditlimit/reject');

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        CreditLimitRequest::reject($id, $validated['reason'] ?? null);

        return response()->json(['message' => 'Successfully Rejected']);
    }
}
