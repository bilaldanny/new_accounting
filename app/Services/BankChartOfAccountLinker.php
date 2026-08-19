<?php

namespace App\Services;

use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountMapping;
use App\Models\FinancialYear;
use Illuminate\Validation\ValidationException;

class BankChartOfAccountLinker
{
    public function linkForCreate(object $request): void
    {
        $request->opening_balance = $request->opening_balance ?? 0;

        $financialYear = $this->resolveActiveFinancialYear((int) $request->company_id);

        $this->linkBankAccount($request, $financialYear);
    }

    public function linkExistingBank(Bank $bank, float $openingBalance = 0): Bank
    {
        if (filled($bank->gl_id)) {
            throw ValidationException::withMessages([
                'gl_id' => ['Bank is already linked to a chart of account.'],
            ]);
        }

        $request = (object) [
            'company_id' => $bank->company_id,
            'branch_id' => $bank->branch_id,
            'bank_name' => $bank->bank_name,
            'opening_balance' => $openingBalance,
        ];

        $this->linkBankAccount($request, $this->resolveActiveFinancialYear((int) $bank->company_id));

        if (! filled($request->gl_id ?? null)) {
            throw ValidationException::withMessages([
                'bank_mapping' => ['Bank account mapping is not configured or parent account is missing.'],
            ]);
        }

        $bank->link_account = $request->link_account ?? 1;
        $bank->gl_id = $request->gl_id;
        $bank->save();

        return $bank->fresh();
    }

    private function resolveActiveFinancialYear(int $companyId): ?FinancialYear
    {
        return FinancialYear::query()
            ->where('company_id', $companyId)
            ->where('status', true)
            ->orderByDesc('id')
            ->first();
    }

    private function linkBankAccount(object $request, ?FinancialYear $financialYear): void
    {
        $mapping = ChartOfAccountMapping::query()
            ->where('key', 'bank')
            ->where('company_id', $request->company_id)
            ->where('branch_id', $request->branch_id)
            ->first();

        if ($mapping === null || $mapping->value === null || $mapping->value === '') {
            return;
        }

        $code = generateChartOfAccountCode('bank', $mapping, $request);
        $request->link_account = 1;
        $request->gl_id = $code;

        $parentAccount = ChartOfAccount::query()->find($mapping->value);

        if ($parentAccount === null) {
            return;
        }

        $subAccount = ChartOfAccount::createSubAccount(
            $request,
            $code,
            (string) $request->bank_name,
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
