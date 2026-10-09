<?php

namespace App\Http\Controllers;

use App\Models\CreditLimitRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Approval Center (Phase 1, easy half) — Credit Limit: raising a request. Reviewing/approving it is
 * CreditLimitApprovalController, mirroring how VoucherApprovalController is separate from the
 * controller that creates a journal/payment/expense voucher.
 */
class CreditLimitRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/creditlimit/add');

        $request->validate([
            'company_id' => 'bail|nullable|integer',
            'branch_id' => 'bail|nullable|integer',
            'contact_id' => 'bail|required|integer|exists:contacts,id',
            'requested_limit' => 'bail|required|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            CreditLimitRequest::createRequest($request);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }
}
