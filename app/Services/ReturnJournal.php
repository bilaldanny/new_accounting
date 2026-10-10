<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\TAccount;
use App\Models\Transaction;
use Illuminate\Validation\ValidationException;

/**
 * The voucher of a sell or purchase return that gives tax back, on the accounts the original document posted to.
 *
 * - Sell return: Dr Sales (value of the goods), Dr Output Tax (the tax), Cr Customer (the whole return).
 * - Purchase return: Dr Supplier (the whole return), Cr Purchase (value of the goods), Cr Input Tax (the tax).
 *
 * Every return of a document that is itself in the books is posted, with or without tax (without tax there is no tax leg). Before
 * this, a return reached the ledger nowhere: sales and the customer's account were not reduced by it. Old returns are not
 * backfilled. When the branch has no tax account mapped the tax stays inside the sales or purchase leg, as it did on the original
 * document. The voucher is keyed by the return (like a sale's), so saving the return again rewrites it, and deleting the return
 * removes it.
 */
class ReturnJournal
{
    public function __construct(private readonly LedgerJournal $ledger) {}

    public function sync(Transaction $return): ?TAccount
    {
        $isSell = $return->type === Transaction::TYPE_SELL_RETURN;
        $tax = round((float) $return->tax_amount, 2);

        // A return is in the books only where the document it returns is: a customer or supplier whose invoices were never
        // journalled is read from the documents themselves (returns included), and one voucher would switch that account over to the
        // journal and hide the rest.
        if ((float) $return->final_amount <= 0.0 || ! TAccount::query()->where('transaction_id', $return->parent_id)->exists()) {
            $this->deleteFor($return);

            return null;
        }

        $return->loadMissing('contact');
        $party = $return->contact;

        if ($party === null) {
            throw ValidationException::withMessages(['contact_id' => ['The return needs a customer or supplier before its journal can be posted.']]);
        }

        $gross = round((float) $return->final_amount, 2);
        $goodsAccount = $this->goodsAccount($return, $isSell, (string) $party->type);
        $partyAccount = $this->partyAccount($return, $isSell, $party->customer_gl_id, $party->supplier_gl_id, $party->gl_id);
        $taxAccount = $tax > 0.0 ? $this->ledger->accountFromMapping($return, $isSell ? 'outputtax' : 'inputtax') : null;
        $useTaxLeg = $taxAccount !== null;
        $goods = $useTaxLeg ? round($gross - $tax, 2) : $gross;

        $lines = $isSell
            ? [
                ['account' => $goodsAccount, 'debit' => $goods, 'credit' => 0.0, 'contact_id' => $party->id],
                ['account' => $partyAccount, 'debit' => 0.0, 'credit' => $gross, 'contact_id' => $party->id],
            ]
            : [
                ['account' => $partyAccount, 'debit' => $gross, 'credit' => 0.0, 'contact_id' => $party->id],
                ['account' => $goodsAccount, 'debit' => 0.0, 'credit' => $goods, 'contact_id' => $party->id],
            ];

        if ($taxAccount !== null) {
            $lines[] = $isSell
                ? ['account' => $taxAccount, 'debit' => $tax, 'credit' => 0.0, 'contact_id' => $party->id]
                : ['account' => $taxAccount, 'debit' => 0.0, 'credit' => $tax, 'contact_id' => $party->id];
        }

        return $this->ledger->post(
            $return,
            $goodsAccount,
            ($isSell ? 'Sell return against ' : 'Purchase return against ').$return->invoice_no,
            $isSell ? 'SR' : 'PR',
            $lines,
            $gross,
            $useTaxLeg ? $tax : 0.0,
        );
    }

    public function deleteFor(Transaction $return): void
    {
        $this->ledger->deleteFor($return);
    }

    private function goodsAccount(Transaction $return, bool $isSell, string $partyType): ChartOfAccount
    {
        $keys = match (true) {
            $isSell && $partyType === 'export' => ['exportsale', 'localsales', 'sale'],
            $isSell => ['localsales', 'sale'],
            $partyType === 'export' => ['importpurchase', 'localpurchase', 'purchase'],
            default => ['localpurchase', 'purchase'],
        };

        $account = $this->ledger->firstMappedAccount($return, $keys);

        if ($account === null) {
            throw ValidationException::withMessages([
                'transaction_id' => [$isSell
                    ? 'Map a Local Sales or Export Sales account before saving a sell return with tax.'
                    : 'Map a Purchase - Local or Purchase - Export account before saving a purchase return with tax.'],
            ]);
        }

        return $account;
    }

    private function partyAccount(Transaction $return, bool $isSell, mixed $customerCode, mixed $supplierCode, mixed $commonCode): ChartOfAccount
    {
        $code = (string) ($isSell ? ($customerCode ?: $commonCode) : ($supplierCode ?: $commonCode));
        $account = $this->ledger->accountByCode($return, $code);

        if ($account === null) {
            throw ValidationException::withMessages([
                'contact_id' => [$isSell
                    ? 'Link the customer to a chart of account before saving a sell return with tax.'
                    : 'Link the supplier to a chart of account before saving a purchase return with tax.'],
            ]);
        }

        return $account;
    }
}
