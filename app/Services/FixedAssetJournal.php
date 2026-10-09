<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\TAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the ledger voucher behind a fixed-asset event as an ordinary journal voucher (JV-), through the same
 * TAccount::createJournalEntry the Journal Entry screen uses. So it numbers itself like any journal, is checked
 * for balance by the same code, and starts in the status the company's own journal auto-approve setting gives it
 * (pending vouchers appear in Journal Entry Approval and count towards no balance until approved). Revaluation,
 * impairment and disposal are different: they are posted at the moment someone approves them, so their voucher is
 * approved straight away (`$respectApprovalSetting = false`). Nothing here changes how any other voucher posts.
 */
class FixedAssetJournal
{
    /**
     * @param  list<array{account_id: int, debit: float, credit: float, description?: string, cost_center_id?: int|null}>  $lines
     */
    public function post(int $companyId, int $branchId, string $date, array $lines, string $comment, string $reference, bool $respectApprovalSetting = true): TAccount
    {
        $details = [];

        foreach ($lines as $line) {
            $account = ChartOfAccount::query()->findOrFail($line['account_id']);
            $details[] = [
                'account_id' => $account->id,
                'code' => (string) $account->code,
                'debit' => round((float) $line['debit'], 2),
                'credit' => round((float) $line['credit'], 2),
                'description' => $line['description'] ?? $comment,
                'cost_center_id' => $line['cost_center_id'] ?? null,
            ];
        }

        $request = Request::create('/', 'POST', [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'voucher_type' => 'JV',
            'voucher_date' => $date,
            'comments' => $comment,
            'ref_no' => $reference,
            'taccountdetails' => $details,
        ]);

        $voucher = TAccount::createJournalEntry($request);

        if (! $respectApprovalSetting && $voucher->status !== TAccount::STATUS_APPROVED) {
            $voucher->status = TAccount::STATUS_APPROVED;
            $voucher->approved_by = Auth::id();
            $voucher->approved_at = now();
            $voucher->save();
        }

        return $voucher;
    }
}
