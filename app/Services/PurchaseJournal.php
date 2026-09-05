<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\TAccount;
use App\Models\Transaction;
use Illuminate\Validation\ValidationException;

class PurchaseJournal
{
    public function __construct(private LedgerJournal $ledger) {}

    public function sync(Transaction $purchase): ?TAccount
    {
        $purchase->loadMissing('contact');

        if ($purchase->status === 'draft') {
            $this->deleteFor($purchase);

            return null;
        }

        $supplier = $purchase->contact;

        if ($supplier === null) {
            throw ValidationException::withMessages([
                'contact_id' => ['Purchase supplier is required before posting the journal.'],
            ]);
        }

        $gross = round((float) $purchase->final_amount, 2);

        if ($gross <= 0.0) {
            throw ValidationException::withMessages([
                'final_amount' => ['Purchase amount must be greater than zero before posting the journal.'],
            ]);
        }

        $purchaseAccount = $this->resolvePurchaseAccount($purchase, $supplier);
        $supplierAccount = $this->resolveSupplierAccount($purchase, $supplier);
        $taxAccount = $this->resolveTaxAccount($purchase);

        $tax = $this->ledger->taxAmount($purchase);
        $useTaxLeg = $tax > 0.0 && $taxAccount !== null;
        $purchaseDebit = $useTaxLeg ? round($gross - $tax, 2) : $gross;

        $lines = [
            [
                'account' => $purchaseAccount,
                'debit' => $purchaseDebit,
                'credit' => 0.0,
                'contact_id' => $supplier->id,
            ],
        ];

        if ($useTaxLeg && $taxAccount !== null) {
            $lines[] = [
                'account' => $taxAccount,
                'debit' => $tax,
                'credit' => 0.0,
                'contact_id' => $supplier->id,
            ];
        }

        $lines[] = [
            'account' => $supplierAccount,
            'debit' => 0.0,
            'credit' => $gross,
            'contact_id' => $supplier->id,
        ];

        return $this->ledger->post(
            $purchase,
            $purchaseAccount,
            'Purchase against '.$purchase->invoice_no,
            'PE',
            $lines,
            $gross,
            $useTaxLeg ? $tax : 0.0,
        );
    }

    public function deleteFor(Transaction $purchase): void
    {
        $this->ledger->deleteFor($purchase);
    }

    /**
     * @param  iterable<int|string>  $purchaseIds
     */
    public function deleteForIds(iterable $purchaseIds): void
    {
        $this->ledger->deleteForIds($purchaseIds);
    }

    private function resolvePurchaseAccount(Transaction $purchase, Contact $supplier): ChartOfAccount
    {
        $keys = $supplier->type === 'export'
            ? ['importpurchase', 'localpurchase', 'purchase']
            : ['localpurchase', 'purchase'];

        $account = $this->ledger->firstMappedAccount($purchase, $keys);

        if ($account === null) {
            throw ValidationException::withMessages([
                'purchase_account' => ['Map a Purchase - Local or Purchase - Export account before saving a purchase.'],
            ]);
        }

        return $account;
    }

    private function resolveSupplierAccount(Transaction $purchase, Contact $supplier): ChartOfAccount
    {
        $account = $this->ledger->accountByCode(
            $purchase,
            (string) ($supplier->supplier_gl_id ?: $supplier->gl_id),
        );

        if ($account === null) {
            throw ValidationException::withMessages([
                'contact_id' => filled($supplier->supplier_gl_id ?: $supplier->gl_id)
                    ? ['The supplier chart of account could not be found.']
                    : ['Link the supplier to a chart of account before saving a purchase.'],
            ]);
        }

        return $account;
    }

    private function resolveTaxAccount(Transaction $purchase): ?ChartOfAccount
    {
        if ($this->ledger->taxAmount($purchase) <= 0.0) {
            return null;
        }

        return $this->ledger->accountFromMapping($purchase, 'inputtax');
    }
}
