<?php

namespace App\Services;

use App\Models\AllowanceProvision;
use App\Models\BadDebtSetting;
use App\Models\BadDebtWriteoff;
use App\Models\ChartOfAccount;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\Reports\PartyAgingReport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Bad debt, by either method (or both):
 *
 *  - direct write-off: a request names an invoice and an amount; once somebody other than the requester approves it, it is posted
 *    as Dr bad debt expense (or the allowance) / Cr the customer through Payment::createDocumentDiscount, the same shape as an
 *    early-payment discount: the invoice's balance and status, the customer ledger and the aging all follow, with nothing
 *    hand-adjusted. A posted write-off can be reversed (the payment and its voucher are removed);
 *  - allowance: at a day the allowance is set to the rate of each aging bucket (settings) and the change from the balance the
 *    allowance account already holds is posted as one journal (Dr expense / Cr allowance, or the release the other way round).
 *    A day can be provisioned once.
 */
class BadDebt
{
    public function __construct(private readonly AccountsVoucher $vouchers, private readonly PartyAgingReport $aging) {}

    public static function available(): bool
    {
        return Schema::hasTable('bad_debt_writeoffs');
    }

    public function settings(int $companyId): BadDebtSetting
    {
        return BadDebtSetting::query()->firstOrNew(['company_id' => $companyId]);
    }

    /**
     * @param  array<string, float|int|string>  $rates
     */
    public function saveSettings(int $companyId, ?int $expenseAccountId, ?int $allowanceAccountId, array $rates): BadDebtSetting
    {
        foreach (['expense_account_id' => $expenseAccountId, 'allowance_account_id' => $allowanceAccountId] as $field => $accountId) {
            if ($accountId !== null && ! ChartOfAccount::query()->where('company_id', $companyId)->whereKey($accountId)->exists()) {
                throw ValidationException::withMessages([$field => ['Choose an account of this company.']]);
            }
        }

        $clean = [];

        foreach (BadDebtSetting::BUCKETS as $bucket) {
            $rate = (float) ($rates[$bucket] ?? 0);

            if ($rate < 0 || $rate > 100) {
                throw ValidationException::withMessages(["rates.{$bucket}" => ['A rate is a percentage from 0 to 100.']]);
            }

            $clean[$bucket] = round($rate, 2);
        }

        $setting = BadDebtSetting::query()->firstOrNew(['company_id' => $companyId]);
        $setting->fill(['expense_account_id' => $expenseAccountId, 'allowance_account_id' => $allowanceAccountId, 'rates' => $clean])->save();

        return $setting;
    }

    /**
     * @throws ValidationException
     */
    public function request(int $companyId, int $transactionId, float $amount, string $date, string $method, string $reason): BadDebtWriteoff
    {
        $invoice = Transaction::query()->sells()->where('company_id', $companyId)->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)->find($transactionId);

        if ($invoice === null) {
            throw ValidationException::withMessages(['transaction_id' => ['Choose a posted invoice of this company.']]);
        }

        $amount = round($amount, 2);
        $remaining = Payment::remainingAmountForTransaction($invoice);
        $pending = (float) BadDebtWriteoff::query()->where('transaction_id', $invoice->id)->where('status', 'pending')->sum('amount');

        if ($amount <= 0 || $amount > round($remaining - $pending, 2) + 0.000001) {
            throw ValidationException::withMessages(['amount' => ['The amount is more than the invoice still owes ('.round($remaining - $pending, 2).').']]);
        }

        $this->accountFor($companyId, $method);

        return BadDebtWriteoff::query()->create([
            'company_id' => $companyId, 'branch_id' => $invoice->branch_id, 'transaction_id' => $invoice->id, 'contact_id' => $invoice->contact_id,
            'amount' => $amount, 'written_off_on' => $date, 'method' => $method, 'reason' => $reason, 'status' => 'pending', 'requested_by' => Auth::id(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function approve(BadDebtWriteoff $writeoff): BadDebtWriteoff
    {
        if ($writeoff->status !== 'pending') {
            throw ValidationException::withMessages(['status' => ['Only a pending write-off can be approved.']]);
        }

        $user = Auth::user();

        if ($writeoff->requested_by !== null && (int) $writeoff->requested_by === (int) Auth::id() && ! $user?->hasRole('superadmin')) {
            throw ValidationException::withMessages(['status' => ['A write-off must be approved by someone other than the person who requested it.']]);
        }

        return DB::transaction(function () use ($writeoff): BadDebtWriteoff {
            $invoice = Transaction::query()->lockForUpdate()->findOrFail($writeoff->transaction_id);
            $account = $this->accountFor((int) $writeoff->company_id, $writeoff->method);

            $payment = Payment::createDocumentDiscount(
                $invoice, $account, (float) $writeoff->amount, $writeoff->written_off_on->toDateString(),
                'Bad debt write-off: '.$writeoff->reason, 'Bad debt write-off',
            );

            $writeoff->update(['status' => 'posted', 'payment_id' => $payment->id, 'decided_by' => Auth::id(), 'decided_at' => now()]);

            return $writeoff;
        });
    }

    public function reject(BadDebtWriteoff $writeoff): BadDebtWriteoff
    {
        if ($writeoff->status !== 'pending') {
            throw ValidationException::withMessages(['status' => ['Only a pending write-off can be rejected.']]);
        }

        $writeoff->update(['status' => 'rejected', 'decided_by' => Auth::id(), 'decided_at' => now()]);

        return $writeoff;
    }

    public function reverse(BadDebtWriteoff $writeoff): BadDebtWriteoff
    {
        if ($writeoff->status !== 'posted') {
            throw ValidationException::withMessages(['status' => ['Only a posted write-off can be reversed.']]);
        }

        return DB::transaction(function () use ($writeoff): BadDebtWriteoff {
            $payment = $writeoff->payment_id === null ? null : Payment::query()->find($writeoff->payment_id);

            if ($payment !== null) {
                Payment::removeCollectedPayment($payment);
            }

            $writeoff->update(['status' => 'reversed', 'decided_by' => Auth::id(), 'decided_at' => now()]);

            return $writeoff;
        });
    }

    /**
     * What the allowance should be on a day against what it holds now.
     *
     * @return array{as_of: string, buckets: array<string, array{amount: float, rate: float, required: float}>, required: float, existing: float, change: float, ready: bool}
     */
    public function provisionPreview(int $companyId, string $asOf): array
    {
        $setting = $this->settings($companyId);
        $rates = (array) ($setting->rates ?? []);
        $invoices = $this->aging->openInvoiceRows('customer', $companyId, null, [], $asOf);
        $buckets = [];
        $required = 0.0;

        foreach (BadDebtSetting::BUCKETS as $bucket) {
            $amount = round((float) $invoices->where('bucket', $bucket)->sum('open_amount'), 2);
            $rate = (float) ($rates[$bucket] ?? 0);
            $need = round($amount * $rate / 100, 2);
            $buckets[$bucket] = ['amount' => $amount, 'rate' => $rate, 'required' => $need];
            $required += $need;
        }

        $existing = $setting->allowance_account_id === null ? 0.0 : $this->allowanceBalance($companyId, (int) $setting->allowance_account_id, $asOf);
        $required = round($required, 2);

        return [
            'as_of' => $asOf, 'buckets' => $buckets, 'required' => $required, 'existing' => $existing, 'change' => round($required - $existing, 2),
            'ready' => $setting->expense_account_id !== null && $setting->allowance_account_id !== null,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function provision(int $companyId, ?int $branchId, string $asOf): AllowanceProvision
    {
        $setting = $this->settings($companyId);

        if ($setting->expense_account_id === null || $setting->allowance_account_id === null) {
            throw ValidationException::withMessages(['as_of' => ['Set the bad debt expense and allowance accounts first.']]);
        }

        if (AllowanceProvision::query()->where('company_id', $companyId)->whereDate('as_of', $asOf)->exists()) {
            throw ValidationException::withMessages(['as_of' => ['The allowance has already been set for this day.']]);
        }

        $preview = $this->provisionPreview($companyId, $asOf);
        $change = $preview['change'];

        return DB::transaction(function () use ($companyId, $branchId, $asOf, $setting, $preview, $change): AllowanceProvision {
            $voucher = null;

            if (abs($change) >= 0.005) {
                $amount = round(abs($change), 2);
                $voucher = $this->vouchers->post(
                    $companyId, $branchId, $asOf,
                    $change > 0
                        ? [['account_id' => (int) $setting->expense_account_id, 'debit' => $amount], ['account_id' => (int) $setting->allowance_account_id, 'credit' => $amount]]
                        : [['account_id' => (int) $setting->allowance_account_id, 'debit' => $amount], ['account_id' => (int) $setting->expense_account_id, 'credit' => $amount]],
                    'Allowance for doubtful debts at '.$asOf,
                    'ALW-'.$asOf,
                );
            }

            return AllowanceProvision::query()->create([
                'company_id' => $companyId, 'as_of' => $asOf, 'buckets' => $preview['buckets'], 'required' => $preview['required'],
                'existing' => $preview['existing'], 'change' => $change, 't_account_id' => $voucher?->id, 'created_by' => Auth::id(),
            ]);
        });
    }

    /**
     * What the allowance account holds (credit less debit) through a day, from approved vouchers.
     */
    private function allowanceBalance(int $companyId, int $accountId, string $asOf): float
    {
        $row = DB::table('t_account_details as d')
            ->join('t_accounts as t', 't.id', '=', 'd.t_account_id')
            ->where('t.company_id', $companyId)
            ->where('d.coa_id', $accountId)
            ->where('t.status', 'approved')
            ->whereDate('t.voucher_date', '<=', $asOf)
            ->selectRaw('coalesce(sum(d.credit), 0) - coalesce(sum(d.debit), 0) as balance')
            ->first();

        return round((float) $row->balance, 2);
    }

    /**
     * @throws ValidationException
     */
    private function accountFor(int $companyId, string $method): ChartOfAccount
    {
        $setting = $this->settings($companyId);
        $id = $method === 'allowance' ? $setting->allowance_account_id : $setting->expense_account_id;
        $account = $id === null ? null : ChartOfAccount::query()->where('company_id', $companyId)->find($id);

        if ($account === null) {
            throw ValidationException::withMessages(['method' => ['Set the '.($method === 'allowance' ? 'allowance for doubtful debts' : 'bad debt expense').' account in the bad debt settings first.']]);
        }

        return $account;
    }
}
