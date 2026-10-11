<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\PurchaseApprovals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/purchase/approval');
        $filters = $request->only([
            'sort_by',
            'sort_type',
            'show_record',
            'status',
            'search',
            'cur_page',
            'page',
            'payment_status',
            'company_id',
            'branch_id',
            'contact_id',
            'transaction_date',
        ]);

        $filters['status'] ??= 'pending';

        return response()->json([
            'data' => Transaction::paginateForIndex($filters),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $purchase = Transaction::findVisiblePurchaseForApproval($id);

        if ($purchase === null) {
            abort(404);
        }

        return response()->json($purchase->presentForApproval() + ['approval' => app(PurchaseApprovals::class)->progress($purchase)]);
    }

    public function approve(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/purchase/approval');

        $purchase = Transaction::approvePurchase($id);

        if ($purchase->status === 'pending') {
            $progress = app(PurchaseApprovals::class)->progress($purchase);

            return response()->json([
                'message' => 'Approved level '.$progress['done'].' of '.$progress['required'].': waiting for the next approver.',
                'approval' => $progress,
            ]);
        }

        return response()->json(['message' => 'Successfully Approved']);
    }
}
