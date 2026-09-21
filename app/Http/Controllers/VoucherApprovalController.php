<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\TAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approval list, approve and reject for the manual vouchers kept in t_accounts: journal entries,
 * payments, expenses, deposits and fund transfers. The routes (routes/api.php) pass the voucher
 * family as a route default (after {id}, so it is the last argument); each family has its own approve
 * and reject menu permission.
 */
class VoucherApprovalController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * Menu permission keys per family (hidden rows in the menus table, granted in Role Permission).
     *
     * @var array<string, array{approve: string, reject: string}>
     */
    public const PERMISSIONS = [
        'journal' => ['approve' => '/journalentry/:id/approve', 'reject' => '/journalentry/:id/reject'],
        'payment' => ['approve' => '/acpayment/:id/approve', 'reject' => '/acpayment/:id/reject'],
        'expense' => ['approve' => '/expense/:id/approve', 'reject' => '/expense/:id/reject'],
        'deposit' => ['approve' => '/deposit/:id/approve', 'reject' => '/deposit/:id/reject'],
        'fundtransfer' => ['approve' => '/fundtransfer/:id/approve', 'reject' => '/fundtransfer/:id/reject'],
    ];

    public function index(Request $request, string $family): JsonResponse
    {
        $this->assertKnownFamily($family);

        $query = TAccount::query()
            ->manualFamily($family)
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name'])
            ->matchingListFilters($request, is_string($request->input('status')) ? $request->input('status') : TAccount::STATUS_PENDING);

        $vouchers = $this->paginateSorted($query, $request);

        $vouchers->getCollection()->transform(fn (TAccount $voucher) => $voucher->presentForIndex());

        return response()->json(['data' => $vouchers, 'trash_count' => 0]);
    }

    public function show(int $id, string $family): JsonResponse
    {
        $this->assertKnownFamily($family);

        $voucher = TAccount::findVisibleManualVoucher($id, $family);

        if ($voucher === null) {
            abort(404);
        }

        return response()->json($voucher->presentForForm());
    }

    public function approve(int $id, string $family): JsonResponse
    {
        $this->assertKnownFamily($family);
        $this->authorizeMenuPermission(self::PERMISSIONS[$family]['approve']);

        TAccount::approveVoucher($id, $family);

        return response()->json(['message' => 'Successfully Approved']);
    }

    public function reject(Request $request, int $id, string $family): JsonResponse
    {
        $this->assertKnownFamily($family);
        $this->authorizeMenuPermission(self::PERMISSIONS[$family]['reject']);

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        TAccount::rejectVoucher($id, $family, $validated['reason'] ?? null);

        return response()->json(['message' => 'Successfully Rejected']);
    }

    private function assertKnownFamily(string $family): void
    {
        abort_unless(array_key_exists($family, self::PERMISSIONS), 404);
    }
}
