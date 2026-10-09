<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Tax;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Books the withholding tax of a sale or purchase as what it is: a settlement of part of the invoice that did not pass through
 * cash or bank.
 *
 * When a customer withholds tax from what they pay us, the tax is an asset we claim back (Dr Withholding Tax Receivable,
 * Cr Customer); when we withhold from a supplier it is a liability we owe the tax office (Dr Supplier, Cr Withholding Tax
 * Payable). It is recorded as a payment of the invoice flagged `is_withholding`, so the party ledger, the aging and the invoice's
 * paid / due status all see it with no change to them. It is redone from the document every time the document is saved.
 */
class WithholdingSettlement
{
    public const SELL_ACCOUNT_KEY = 'withholdingreceivable';

    public const PURCHASE_ACCOUNT_KEY = 'withholdingpayable';

    public function __construct(private LedgerJournal $ledger) {}

    /**
     * Refuses a document that carries withholding tax while the branch has no account mapped for it, before anything is saved
     * (a draft sale or a pending purchase books nothing yet, but would fail the moment it is posted).
     *
     * @throws ValidationException
     */
    public function assertAccountMapped(Transaction $document): void
    {
        $isSell = $document->type === Transaction::TYPE_SELL;

        if ($this->ledger->accountFromMapping($document, $isSell ? self::SELL_ACCOUNT_KEY : self::PURCHASE_ACCOUNT_KEY) === null) {
            throw ValidationException::withMessages(['withholding_tax_id' => [self::missingAccountMessage($isSell)]]);
        }
    }

    public static function missingAccountMessage(bool $isSell): string
    {
        $account = $isSell ? 'Withholding Tax Receivable' : 'Withholding Tax Payable';

        return 'Withholding tax accounts are not set up for this branch, so this '.($isSell ? 'sale' : 'purchase').' cannot be saved with withholding tax. '
            ."Go to Settings > Company Settings > Link Accounts, map \"{$account}\" to an account and save again, or clear the withholding tax field.";
    }

    /**
     * @throws ValidationException
     */
    public function sync(Transaction $document): void
    {
        $isSell = $document->type === Transaction::TYPE_SELL;

        Payment::query()
            ->where('transaction_id', $document->id)
            ->where('is_withholding', true)
            ->get()
            ->each(fn (Payment $payment) => $isSell ? Payment::deleteSellPayment((int) $payment->id) : Payment::deletePurchasePayment((int) $payment->id));

        $amount = round((float) $document->withholding_amount, 2);

        if ($amount <= 0.0 || $document->withholding_tax_id === null || ! $this->isPosted($document, $isSell)) {
            return;
        }

        $account = $this->ledger->accountFromMapping($document, $isSell ? self::SELL_ACCOUNT_KEY : self::PURCHASE_ACCOUNT_KEY);

        if ($account === null) {
            throw ValidationException::withMessages(['withholding_tax_id' => [self::missingAccountMessage($isSell)]]);
        }

        $taxName = Tax::query()->whereKey($document->withholding_tax_id)->value('name');
        $request = new Request([
            'transaction_id' => $document->id,
            'amount' => $amount,
            'method' => 'other',
            'payment_account' => $account->id,
            'paid_on' => $document->transaction_date?->format('Y-m-d') ?? now()->toDateString(),
            'note' => 'Withholding tax ('.$taxName.') on '.$document->invoice_no,
        ]);

        $payment = $isSell ? Payment::createSellPayment($request) : Payment::createPurchasePayment($request);
        $payment->is_withholding = true;
        $payment->save();
    }

    private function isPosted(Transaction $document, bool $isSell): bool
    {
        return $isSell
            ? ! in_array($document->status, Transaction::UNPOSTED_SELL_STATUSES, true)
            : $document->status === 'approved';
    }
}
