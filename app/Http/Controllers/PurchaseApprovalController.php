<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
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

        return response()->json($purchase->presentForApproval());
    }

    public function approve(int $id): JsonResponse
    {
        Transaction::approvePurchase($id);

        return response()->json(['message' => 'Successfully Approved']);
    }
}
