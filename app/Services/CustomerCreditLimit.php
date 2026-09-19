<?php

namespace App\Services;

use App\Exceptions\CreditLimitExceededException;
use App\Models\Contact;
use App\Models\Transaction;

/**
 * Blocks a sale that would push a customer's ledger balance past `contacts.credit_limit`.
 *
 * Rules: a limit of 0 means no limit; draft/quotation sales (which the ledger does not post) and
 * fully paid sales never extend credit; only the increase over what the sale already contributed
 * is checked, so editing a sale down, or leaving it unchanged, is never blocked.
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

        $currentBalance = $this->ledger->currentBalance($customer);

        if (round($currentBalance + $increase, 2) <= $creditLimit) {
            return;
        }

        throw CreditLimitExceededException::forSale(
            (string) ($customer->business_name ?: $customer->first_name),
            $creditLimit,
            $currentBalance,
            $increase,
        );
    }
}
