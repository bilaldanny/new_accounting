<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\TAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Makes a balanced journal voucher on behalf of a feature module (petty cash, loans, tax payments, bank charges ...) through the
 * same path as a hand-entered journal, so the company's journal approval setting, the period lock and the voucher numbering all
 * apply: with auto-approval off the voucher is pending and does not count in the ledger until someone approves it.
 */
class AccountsVoucher
{
    /**
     * @param  list<array{account_id: int, debit?: float, credit?: float, contact_id?: int|null, description?: string, cost_center_id?: int|null, project_id?: int|null}>  $lines
     * @param  string  $voucherType  JV for a journal, EXP for an expense voucher (the company's expense approval rule then applies)
     *
     * @throws ValidationException
     */
    public function post(int $companyId, ?int $branchId, string $date, array $lines, string $comments, string $reference = '', string $voucherType = 'JV'): TAccount
    {
        $details = [];

        foreach ($lines as $line) {
            $account = ChartOfAccount::query()->where('company_id', $companyId)->find($line['account_id']);

            if ($account === null) {
                throw ValidationException::withMessages(['account' => ['An account is not part of this company\'s chart of accounts.']]);
            }

            $details[] = [
                'account_id' => $account->id,
                'code' => (string) $account->code,
                'debit' => round((float) ($line['debit'] ?? 0), 2),
                'credit' => round((float) ($line['credit'] ?? 0), 2),
                'contact_id' => $line['contact_id'] ?? null,
                'description' => $line['description'] ?? $comments,
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'project_id' => $line['project_id'] ?? null,
            ];
        }

        return TAccount::createJournalEntry(new Request([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'voucher_type' => $voucherType,
            'voucher_date' => $date,
            'ref_no' => $reference,
            'comments' => $comments,
            'taccountdetails' => $details,
        ]));
    }

    /**
     * What state a voucher this service made is in, for display next to the module's own record.
     */
    public function status(?int $voucherId): ?string
    {
        return $voucherId === null ? null : TAccount::query()->whereKey($voucherId)->value('status');
    }
}
