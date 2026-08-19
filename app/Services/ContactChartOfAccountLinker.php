<?php

namespace App\Services;

use App\Models\AccountBalance;
use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountMapping;
use App\Models\Contact;
use App\Models\FinancialYear;
use Illuminate\Validation\ValidationException;

class ContactChartOfAccountLinker
{
    public function linkForCreate(object $request): void
    {
        $request->opening_balance = $request->opening_balance ?? 0;

        $financialYear = $this->resolveActiveFinancialYear((int) $request->company_id);

        $userType = $request->user_type ?? 'supplier';

        if (in_array($userType, ['supplier', 'both'], true)) {
            $this->linkSupplierAccount($request, $financialYear);
        }

        if (in_array($userType, ['customer', 'both'], true)) {
            $this->linkCustomerAccount($request, $financialYear);
        }
    }

    public function linkExistingSupplier(Contact $contact, float $openingBalance = 0): Contact
    {
        if (filled($contact->supplier_gl_id)) {
            throw ValidationException::withMessages([
                'supplier_gl_id' => ['Supplier is already linked to a chart of account.'],
            ]);
        }

        if (! in_array($contact->user_type, ['supplier', 'both'], true)) {
            throw ValidationException::withMessages([
                'user_type' => ['Contact is not a supplier.'],
            ]);
        }

        $request = (object) [
            'company_id' => $contact->company_id,
            'branch_id' => $contact->branch_id,
            'business_name' => $contact->business_name,
            'user_type' => $contact->user_type,
            'opening_balance' => $openingBalance,
        ];

        $this->linkSupplierAccount($request, $this->resolveActiveFinancialYear((int) $contact->company_id));

        if (! filled($request->supplier_gl_id ?? null)) {
            throw ValidationException::withMessages([
                'supplier_mapping' => ['Supplier account mapping is not configured or parent account is missing.'],
            ]);
        }

        $contact->link_account = $request->link_account ?? 1;
        $contact->supplier_gl_id = $request->supplier_gl_id;
        $contact->gl_id = $request->gl_id ?? $request->supplier_gl_id;
        $contact->save();

        return $contact->fresh();
    }

    public function linkExistingCustomer(Contact $contact, float $openingBalance = 0): Contact
    {
        if (filled($contact->customer_gl_id)) {
            throw ValidationException::withMessages([
                'customer_gl_id' => ['Customer is already linked to a chart of account.'],
            ]);
        }

        if (! in_array($contact->user_type, ['customer', 'both'], true)) {
            throw ValidationException::withMessages([
                'user_type' => ['Contact is not a customer.'],
            ]);
        }

        $request = (object) [
            'company_id' => $contact->company_id,
            'branch_id' => $contact->branch_id,
            'business_name' => $contact->business_name,
            'user_type' => $contact->user_type,
            'opening_balance' => $openingBalance,
        ];

        $this->linkCustomerAccount($request, $this->resolveActiveFinancialYear((int) $contact->company_id));

        if (! filled($request->customer_gl_id ?? null)) {
            throw ValidationException::withMessages([
                'customer_mapping' => ['Customer account mapping is not configured or parent account is missing.'],
            ]);
        }

        $contact->link_account = $request->link_account ?? 1;
        $contact->customer_gl_id = $request->customer_gl_id;
        $contact->gl_id = $request->gl_id ?? $request->customer_gl_id;
        $contact->save();

        return $contact->fresh();
    }

    private function resolveActiveFinancialYear(int $companyId): ?FinancialYear
    {
        return FinancialYear::query()
            ->where('company_id', $companyId)
            ->where('status', true)
            ->orderByDesc('id')
            ->first();
    }

    private function linkSupplierAccount(object $request, ?FinancialYear $financialYear): void
    {
        $mapping = $this->findMapping($request, 'supplier');

        if ($mapping === null) {
            return;
        }

        $code = generateChartOfAccountCode('supplier', $mapping, $request);
        $request->link_account = 1;
        $request->gl_id = $code;
        $request->supplier_gl_id = $code;

        $this->createSubLedgerAccount($request, $mapping, $code, $financialYear);
    }

    private function linkCustomerAccount(object $request, ?FinancialYear $financialYear): void
    {
        $mapping = $this->findMapping($request, 'customer');

        if ($mapping === null) {
            return;
        }

        $code = generateChartOfAccountCode('customer', $mapping, $request);
        $request->link_account = 1;
        $request->customer_gl_id = $code;

        $this->createSubLedgerAccount($request, $mapping, $code, $financialYear);
    }

    private function findMapping(object $request, string $key): ?ChartOfAccountMapping
    {
        $mapping = ChartOfAccountMapping::query()
            ->where('key', $key)
            ->where('company_id', $request->company_id)
            ->where('branch_id', $request->branch_id)
            ->first();

        if ($mapping === null || $mapping->value === null || $mapping->value === '') {
            return null;
        }

        return $mapping;
    }

    private function createSubLedgerAccount(
        object $request,
        ChartOfAccountMapping $mapping,
        string $code,
        ?FinancialYear $financialYear,
    ): void {
        $parentAccount = ChartOfAccount::query()->find($mapping->value);

        if ($parentAccount === null) {
            return;
        }

        $subAccount = ChartOfAccount::createSubAccount(
            $request,
            $code,
            (string) $request->business_name,
            (int) $mapping->value,
            $parentAccount,
        );

        if ($financialYear !== null) {
            AccountBalance::createForContact(
                $request,
                $financialYear->id,
                $subAccount->id,
                $parentAccount->acc_nature,
            );
        }
    }
}
