<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountMapping;
use App\Models\TAccount;
use App\Models\TAccountDetail;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LedgerJournal
{
    public function findFor(Transaction $transaction): ?TAccount
    {
        return TAccount::query()->where('transaction_id', $transaction->id)->first();
    }

    public function deleteFor(Transaction $transaction): void
    {
        TAccount::query()->where('transaction_id', $transaction->id)->delete();
    }

    /**
     * @param  iterable<int|string>  $transactionIds
     */
    public function deleteForIds(iterable $transactionIds): void
    {
        $ids = collect($transactionIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        TAccount::query()->whereIn('transaction_id', $ids)->delete();
    }

    /**
     * @param  list<array{account: ChartOfAccount, debit: float, credit: float, contact_id?: int|null}>  $lines
     */
    public function post(
        Transaction $transaction,
        ChartOfAccount $headerAccount,
        string $description,
        string $voucherPrefix,
        array $lines,
        float $gross,
        float $tax,
    ): TAccount {
        $journal = $this->findFor($transaction) ?? new TAccount;
        $isNew = ! $journal->exists;

        $journal->company_id = $transaction->company_id;
        $journal->branch_id = $transaction->branch_id;
        $journal->coa_id = $headerAccount->id;
        $journal->transaction_id = $transaction->id;
        $journal->created_by = $journal->created_by ?: Auth::id();
        $journal->approved_by = Auth::id();
        $journal->account_code = (string) $headerAccount->code;
        $journal->ref_no = (string) $transaction->invoice_no;
        $journal->cheque_no = '';
        $journal->voucher_date = $transaction->transaction_date;
        $journal->total_amount = $gross;
        $journal->total_tax = $tax;
        $journal->net_total = $gross;
        $journal->comments = (string) ($transaction->additional_note ?? '');
        $journal->status = 'approved';
        $journal->type = 'online';
        $journal->approved_at = $journal->approved_at ?? now();

        if ($isNew || blank($journal->voucher_no)) {
            $journal->voucher_no = $this->nextVoucherNo(
                (int) $transaction->company_id,
                (int) $transaction->branch_id,
                $voucherPrefix,
            );
        }

        $journal->save();
        $journal->details()->delete();

        foreach ($lines as $line) {
            $this->addLine(
                $journal,
                $line['account'],
                $description,
                $line['debit'],
                $line['credit'],
                $line['contact_id'] ?? $transaction->contact_id,
                $transaction->branch_id,
            );
        }

        $this->assertBalanced($journal);

        return $journal->fresh('details') ?? $journal;
    }

    public function accountFromMapping(Transaction $transaction, string $key): ?ChartOfAccount
    {
        $mapping = ChartOfAccountMapping::query()
            ->where('company_id', $transaction->company_id)
            ->where('branch_id', $transaction->branch_id)
            ->where('key', $key)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->first();

        if ($mapping === null) {
            return null;
        }

        return ChartOfAccount::query()->find((int) $mapping->value);
    }

    public function firstMappedAccount(Transaction $transaction, array $keys): ?ChartOfAccount
    {
        foreach ($keys as $key) {
            $account = $this->accountFromMapping($transaction, $key);

            if ($account !== null) {
                return $account;
            }
        }

        return null;
    }

    public function accountByCode(Transaction $transaction, string $code): ?ChartOfAccount
    {
        if ($code === '') {
            return null;
        }

        return ChartOfAccount::query()
            ->where('company_id', $transaction->company_id)
            ->where('branch_id', $transaction->branch_id)
            ->where('code', $code)
            ->first();
    }

    public function taxAmount(Transaction $transaction): float
    {
        $gross = round((float) $transaction->final_amount, 2);
        $tax = round((float) ($transaction->tax_amount ?? 0), 2);

        if ($tax <= 0.0) {
            return 0.0;
        }

        return min($tax, $gross);
    }

    private function addLine(
        TAccount $journal,
        ChartOfAccount $account,
        string $description,
        float $debit,
        float $credit,
        int|string|null $contactId,
        int|string|null $branchId,
    ): TAccountDetail {
        $debit = round($debit, 2);
        $credit = round($credit, 2);

        if ($debit < 0.0 || $credit < 0.0) {
            throw ValidationException::withMessages([
                'final_amount' => ['Journal lines cannot have a negative debit or credit amount.'],
            ]);
        }

        if ($debit === 0.0 && $credit === 0.0) {
            throw ValidationException::withMessages([
                'final_amount' => ['Each journal line must have a positive debit or credit amount.'],
            ]);
        }

        if ($debit > 0.0 && $credit > 0.0) {
            throw ValidationException::withMessages([
                'final_amount' => ['A journal line cannot have both a debit and a credit amount.'],
            ]);
        }

        return $journal->details()->create([
            'branch_id' => $branchId,
            'coa_id' => $account->id,
            'contact_id' => $contactId,
            'account_code' => (string) $account->code,
            'description' => $description,
            'acc_nature' => $account->acc_nature ?: ($debit > 0 ? 'dr' : 'cr'),
            'debit' => $debit,
            'credit' => $credit,
            'amount' => 0,
            'highlight' => false,
        ]);
    }

    private function assertBalanced(TAccount $journal): void
    {
        $totals = $journal->details()
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total')
            ->selectRaw('COALESCE(SUM(credit), 0) as credit_total')
            ->first();

        $debits = round((float) ($totals->debit_total ?? 0), 2);
        $credits = round((float) ($totals->credit_total ?? 0), 2);

        if (! self::amountsEqual($debits, $credits) || $debits <= 0.0) {
            throw ValidationException::withMessages([
                'final_amount' => ['Journal is not balanced. Total debit must equal total credit.'],
            ]);
        }
    }

    /**
     * Decimal-safe equality for 2dp monetary amounts, comparing whole cents
     * instead of raw floats (which can differ by rounding noise even when
     * two independently-summed totals are mathematically equal).
     */
    private static function amountsEqual(float $a, float $b): bool
    {
        return (int) round($a * 100) === (int) round($b * 100);
    }

    public function nextVoucherNo(int $companyId, int $branchId, string $prefix): string
    {
        return DB::transaction(function () use ($companyId, $branchId, $prefix): string {
            $pattern = $prefix.'-%';

            $last = TAccount::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('voucher_no', 'like', $pattern)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('voucher_no');

            $next = 1;

            if (is_string($last) && preg_match('/(\d+)$/', $last, $matches) === 1) {
                $next = (int) $matches[1] + 1;
            }

            do {
                $voucherNo = $prefix.'-'.Str::padLeft((string) $next, 5, '0');
                $next++;
            } while (
                TAccount::query()
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('voucher_no', $voucherNo)
                    ->lockForUpdate()
                    ->exists()
            );

            return $voucherNo;
        });
    }
}
