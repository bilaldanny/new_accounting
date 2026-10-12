<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\InterbranchTransaction;
use App\Models\TAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Inter-branch transactions inside one company and their elimination on consolidation. Branches of one company share its chart of
 * accounts, so a transfer between two of them is two vouchers, one in each branch:
 *
 *   giving branch:    Dr due from branches / Cr its own account (cash, bank, expense ...)
 *   receiving branch: Dr its own account / Cr due to branches
 *
 * Each follows the journal approval rule and the period lock. Consolidated, the company's branches add up and the two clearing
 * accounts cancel; whatever does not cancel is shown as unmatched (a voucher still pending, or a hand-posted entry). Separate
 * companies are separate tenants and are deliberately not connected here.
 */
class InterBranch
{
    public function __construct(private readonly AccountsVoucher $vouchers) {}

    public static function available(): bool
    {
        return Schema::hasTable('interbranch_transactions') && Schema::hasTable('interbranch_settings');
    }

    /**
     * @return array{receivable_account_id: int|null, payable_account_id: int|null}
     */
    public function settings(int $companyId): array
    {
        $row = DB::table('interbranch_settings')->where('company_id', $companyId)->first();

        return ['receivable_account_id' => $row?->receivable_account_id === null ? null : (int) $row->receivable_account_id, 'payable_account_id' => $row?->payable_account_id === null ? null : (int) $row->payable_account_id];
    }

    public function saveSettings(int $companyId, ?int $receivableId, ?int $payableId): void
    {
        foreach (['receivable_account_id' => $receivableId, 'payable_account_id' => $payableId] as $field => $id) {
            if ($id !== null && ! ChartOfAccount::query()->where('company_id', $companyId)->whereKey($id)->exists()) {
                throw ValidationException::withMessages([$field => ['Choose an account of this company.']]);
            }
        }

        if ($receivableId !== null && $receivableId === $payableId) {
            throw ValidationException::withMessages(['payable_account_id' => ['The due-from and due-to accounts must be two different accounts.']]);
        }

        DB::table('interbranch_settings')->updateOrInsert(
            ['company_id' => $companyId],
            ['receivable_account_id' => $receivableId, 'payable_account_id' => $payableId, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * @throws ValidationException
     */
    public function transfer(int $companyId, int $fromBranchId, int $toBranchId, float $amount, string $date, int $fromAccountId, int $toAccountId, ?string $reference, ?string $description): InterbranchTransaction
    {
        $settings = $this->settings($companyId);

        if ($settings['receivable_account_id'] === null || $settings['payable_account_id'] === null) {
            throw ValidationException::withMessages(['from_branch_id' => ['Set the due-from and due-to branch accounts first.']]);
        }

        if ($fromBranchId === $toBranchId) {
            throw ValidationException::withMessages(['to_branch_id' => ['Choose a different branch to transfer to.']]);
        }

        $branches = Branch::query()->where('company_id', $companyId)->whereIn('id', [$fromBranchId, $toBranchId])->count();

        if ($branches !== 2) {
            throw ValidationException::withMessages(['to_branch_id' => ['Both branches must belong to this company.']]);
        }

        foreach (['from_account_id' => $fromAccountId, 'to_account_id' => $toAccountId] as $field => $id) {
            if (! ChartOfAccount::query()->where('company_id', $companyId)->whereKey($id)->exists()) {
                throw ValidationException::withMessages([$field => ['Choose an account of this company.']]);
            }

            if (in_array($id, $settings, true)) {
                throw ValidationException::withMessages([$field => ['Choose the branch\'s own account, not a clearing account.']]);
            }
        }

        $amount = round($amount, 2);

        return DB::transaction(function () use ($companyId, $fromBranchId, $toBranchId, $amount, $date, $fromAccountId, $toAccountId, $reference, $description, $settings): InterbranchTransaction {
            $row = InterbranchTransaction::query()->create([
                'company_id' => $companyId, 'from_branch_id' => $fromBranchId, 'to_branch_id' => $toBranchId, 'amount' => $amount, 'transaction_date' => $date,
                'reference' => $reference, 'description' => $description, 'from_account_id' => $fromAccountId, 'to_account_id' => $toAccountId,
                'status' => 'posted', 'created_by' => Auth::id(),
            ]);
            $note = 'Inter-branch transfer IB-'.$row->id.($description ? ': '.$description : '');

            $from = $this->vouchers->post($companyId, $fromBranchId, $date, [
                ['account_id' => $settings['receivable_account_id'], 'debit' => $amount],
                ['account_id' => $fromAccountId, 'credit' => $amount],
            ], $note, 'IB-'.$row->id);

            $to = $this->vouchers->post($companyId, $toBranchId, $date, [
                ['account_id' => $toAccountId, 'debit' => $amount],
                ['account_id' => $settings['payable_account_id'], 'credit' => $amount],
            ], $note, 'IB-'.$row->id);

            $row->update(['from_voucher_id' => $from->id, 'to_voucher_id' => $to->id]);

            return $row;
        });
    }

    public function reverse(InterbranchTransaction $row): InterbranchTransaction
    {
        if ($row->status !== 'posted') {
            throw ValidationException::withMessages(['status' => ['This transfer has already been reversed.']]);
        }

        return DB::transaction(function () use ($row): InterbranchTransaction {
            foreach ([$row->from_voucher_id, $row->to_voucher_id] as $voucherId) {
                if ($voucherId !== null) {
                    TAccount::query()->whereKey($voucherId)->get()->each->delete();
                }
            }

            $row->update(['status' => 'reversed']);

            return $row;
        });
    }

    /**
     * The company's branches side by side on the two clearing accounts, and the elimination: what one branch is owed is what
     * another owes, so the consolidated figure of both is nil. `unmatched` is what does not cancel.
     *
     * @return array{as_of: string, branches: list<array<string, mixed>>, consolidated: array<string, float>}
     */
    public function consolidation(int $companyId, ?int $branchId, string $asOf): array
    {
        $settings = $this->settings($companyId);
        $accounts = array_values(array_filter($settings));
        $balances = $accounts === [] ? collect() : DB::table('t_account_details as d')
            ->join('t_accounts as a', 'a.id', '=', 'd.t_account_id')
            ->where('a.company_id', $companyId)->where('a.status', 'approved')
            ->whereIn('d.coa_id', $accounts)
            ->whereDate('a.voucher_date', '<=', $asOf)
            ->groupBy('a.branch_id', 'd.coa_id')
            ->selectRaw('a.branch_id, d.coa_id, sum(d.debit) - sum(d.credit) as net')
            ->get();

        $rows = Branch::query()->where('company_id', $companyId)->when($branchId !== null, fn ($q) => $q->whereKey($branchId))->orderBy('name')->get(['id', 'name'])
            ->map(function (Branch $branch) use ($balances, $settings): array {
                $due = round((float) $balances->where('branch_id', $branch->id)->where('coa_id', $settings['receivable_account_id'])->sum('net'), 2);
                $owed = round(-1 * (float) $balances->where('branch_id', $branch->id)->where('coa_id', $settings['payable_account_id'])->sum('net'), 2);

                return ['branch_id' => (int) $branch->id, 'branch_name' => $branch->name, 'due_from_branches' => $due, 'due_to_branches' => $owed, 'net' => round($due - $owed, 2)];
            })->values()->all();

        $dueFrom = round(array_sum(array_column($rows, 'due_from_branches')), 2);
        $dueTo = round(array_sum(array_column($rows, 'due_to_branches')), 2);
        $eliminated = round(min($dueFrom, $dueTo), 2);

        return [
            'as_of' => $asOf,
            'branches' => $rows,
            'consolidated' => [
                'due_from_branches' => $dueFrom, 'due_to_branches' => $dueTo, 'eliminated' => $eliminated,
                'unmatched' => round($dueFrom - $dueTo, 2), 'consolidated_receivable' => round($dueFrom - $eliminated, 2), 'consolidated_payable' => round($dueTo - $eliminated, 2),
            ],
        ];
    }
}
