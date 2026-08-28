<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellApprovalController extends Controller
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

        $filters['status'] ??= 'final';

        return response()->json([
            'data' => Transaction::paginateSellsForIndex($filters),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $sell = Transaction::findVisibleSellForApproval($id);

        if ($sell === null) {
            abort(404);
        }

        return response()->json($sell->presentForSellApproval());
    }

    public function approve(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/sell/approval');

        Transaction::approveSell($id);

        return response()->json(['message' => 'Successfully Approved']);
    }
}
