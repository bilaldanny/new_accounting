<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\TAccount;
use App\Models\Transaction;
use Illuminate\Validation\ValidationException;

class SellJournal
{
    public function __construct(private LedgerJournal $ledger) {}

    public function sync(Transaction $sell): ?TAccount
    {
        $sell->loadMissing('contact');

        if (in_array($sell->status, ['draft', 'quotation'], true)) {
            $this->deleteFor($sell);

            return null;
        }

        $customer = $sell->contact;

        if ($customer === null) {
            throw ValidationException::withMessages([
                'contact_id' => ['Sale customer is required before posting the journal.'],
            ]);
        }

        $salesAccount = $this->resolveSalesAccount($sell, $customer);
        $customerAccount = $this->resolveCustomerAccount($sell, $customer);
        $taxAccount = $this->resolveTaxAccount($sell);

        $gross = round((float) $sell->final_amount, 2);
        $tax = $this->ledger->taxAmount($sell);
        $useTaxLeg = $tax > 0.0 && $taxAccount !== null;
        $salesCredit = $useTaxLeg ? round($gross - $tax, 2) : $gross;

        $lines = [
            [
                'account' => $customerAccount,
                'debit' => $gross,
                'credit' => 0.0,
                'contact_id' => $customer->id,
            ],
            [
                'account' => $salesAccount,
                'debit' => 0.0,
                'credit' => $salesCredit,
                'contact_id' => $customer->id,
            ],
        ];

        if ($useTaxLeg && $taxAccount !== null) {
            $lines[] = [
                'account' => $taxAccount,
                'debit' => 0.0,
                'credit' => $tax,
                'contact_id' => $customer->id,
            ];
        }

        return $this->ledger->post(
            $sell,
            $salesAccount,
            'Sell against '.$sell->invoice_no,
            'SE',
            $lines,
            $gross,
            $useTaxLeg ? $tax : 0.0,
        );
    }

    public function deleteFor(Transaction $sell): void
    {
        $this->ledger->deleteFor($sell);
    }

    /**
     * @param  iterable<int|string>  $sellIds
     */
    public function deleteForIds(iterable $sellIds): void
    {
        $this->ledger->deleteForIds($sellIds);
    }

    private function resolveSalesAccount(Transaction $sell, Contact $customer): ChartOfAccount
    {
        $keys = $customer->type === 'export'
            ? ['exportsale', 'localsales', 'sale']
            : ['localsales', 'sale'];

        $account = $this->ledger->firstMappedAccount($sell, $keys);

        if ($account === null) {
            throw ValidationException::withMessages([
                'sales_account' => ['Map a Local Sales or Export Sales account before saving a sale.'],
            ]);
        }

        return $account;
    }

    private function resolveCustomerAccount(Transaction $sell, Contact $customer): ChartOfAccount
    {
        $account = $this->ledger->accountByCode(
            $sell,
            (string) ($customer->customer_gl_id ?: $customer->gl_id),
        );

        if ($account === null) {
            throw ValidationException::withMessages([
                'contact_id' => filled($customer->customer_gl_id ?: $customer->gl_id)
                    ? ['The customer chart of account could not be found.']
                    : ['Link the customer to a chart of account before saving a sale.'],
            ]);
        }

        return $account;
    }

    private function resolveTaxAccount(Transaction $sell): ?ChartOfAccount
    {
        if ($this->ledger->taxAmount($sell) <= 0.0) {
            return null;
        }

        return $this->ledger->accountFromMapping($sell, 'outputtax');
    }
}
