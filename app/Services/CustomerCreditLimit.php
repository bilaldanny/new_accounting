<?php

namespace App\Services;

use App\Exceptions\CreditLimitExceededException;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;

/**
 * Blocks a sale that would push a customer's ledger balance past `contacts.credit_limit`.
 *
 * Rules: a limit of 0 means no limit; draft/quotation sales (which the ledger does not post) and
 * fully paid sales never extend credit; only the increase over what the sale already contributed
 * is checked, so editing a sale down, or leaving it unchanged, is never blocked.
 *
 * The company's `credit_limit_mode` decides what a breach does: `hard` (the default, and what a company that never
 * set it gets) refuses the sale; `warn` refuses it with a warning the user can pass by sending a reason
 * (`credit_override_reason`), which is written to the audit log; `off` lets it through.
 */
class CustomerCreditLimit
{
    public function __construct(private readonly ContactLedger $ledger) {}

    /**
     * @param  Transaction  $sale  The sale as it is about to be saved.
     * @param  array{status: ?string, final_amount: float}|null  $previous  The saved state being replaced; null for a new sale.
     *
     * @throws CreditLimitExceededException
     */
    public function assertWithinLimit(Transaction $sale, ?array $previous = null): void
    {
        if ($sale->contact_id === null
            || in_array($sale->status, Transaction::UNPOSTED_SELL_STATUSES, true)
            || $sale->payment_status === 'paid') {
            return;
        }

        $alreadyCounted = $previous !== null && ! in_array($previous['status'], Transaction::UNPOSTED_SELL_STATUSES, true)
            ? $previous['final_amount']
            : 0.0;
        $increase = round((float) $sale->final_amount - $alreadyCounted, 2);

        if ($increase <= 0) {
            return;
        }

        $customer = Contact::findVisibleContact((int) $sale->contact_id);

        if ($customer === null) {
            return;
        }

        $creditLimit = (float) $customer->credit_limit;

        if ($creditLimit <= 0) {
            return;
        }

        $mode = CompanySetting::creditLimitMode((int) $customer->company_id);

        if ($mode === 'off') {
            return;
        }

        $currentBalance = $this->ledger->currentBalance($customer);

        if (round($currentBalance + $increase, 2) <= $creditLimit) {
            return;
        }

        $name = (string) ($customer->business_name ?: $customer->first_name);

        if ($mode === 'warn') {
            $reason = trim((string) request()->input('credit_override_reason', ''));

            if (mb_strlen($reason) >= 3) {
                $this->auditOverride($customer, $sale, $reason, $creditLimit, $currentBalance, $increase);

                return;
            }

            throw CreditLimitExceededException::forSale($name, $creditLimit, $currentBalance, $increase, warning: true);
        }

        throw CreditLimitExceededException::forSale($name, $creditLimit, $currentBalance, $increase);
    }

    private function auditOverride(Contact $customer, Transaction $sale, string $reason, float $limit, float $balance, float $increase): void
    {
        AuditLog::query()->create([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'user_id' => Auth::id(),
            'event' => 'credit_limit_override',
            'auditable_type' => Contact::class,
            'auditable_id' => $customer->id,
            'old_values' => null,
            'new_values' => [
                'reason' => $reason,
                'invoice_no' => $sale->invoice_no,
                'credit_limit' => $limit,
                'current_balance' => $balance,
                'sale_amount' => $increase,
                'projected_balance' => round($balance + $increase, 2),
            ],
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
        ]);
    }
}
