<?php

use App\Support\MenuMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Menu rows for the Accounts & Finance closeout pages (wave 5), in the group of Bank Reconciliation, granted to the global
 * companyadmin role. `/bankreconciliation/charge` is the permission to book a bank charge from a statement line.
 */
return new class extends Migration
{
    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private function rows(): array
    {
        return [
            ['sibling:/bankreconciliation', 'Petty Cash', '/pettycash', 'pettycash', 'HandCoins', false],
            ['/pettycash', 'Add Petty Cash Fund', '/pettycash/add', 'pettycash.add', 'Grid', true],
            ['/pettycash', 'Edit Petty Cash Fund', '/pettycash/:id/edit', 'pettycash.edit', 'Grid', true],
            ['/pettycash', 'Delete Petty Cash Fund', '/pettycash/delete', 'pettycash.delete', 'Grid', true],
            ['/pettycash', 'Record Petty Cash Expense', '/pettycash/expense', 'pettycash.expense', 'Grid', true],
            ['/pettycash', 'Replenish Petty Cash Fund', '/pettycash/replenish', 'pettycash.replenish', 'Grid', true],
            ['sibling:/bankreconciliation', 'Cheque Printing', '/chequeprint', 'chequeprint', 'Printer', false],
            ['/chequeprint', 'Edit Cheque Layouts', '/chequeprint/layout', 'chequeprint.layout', 'Grid', true],
            ['/chequeprint', 'Print Cheque', '/chequeprint/print', 'chequeprint.print', 'Grid', true],
            ['sibling:/bankreconciliation', 'Guarantees & Letters of Credit', '/bankinstrument', 'bankinstrument', 'ShieldCheck', false],
            ['/bankinstrument', 'Add Bank Instrument', '/bankinstrument/add', 'bankinstrument.add', 'Grid', true],
            ['/bankinstrument', 'Edit Bank Instrument', '/bankinstrument/:id/edit', 'bankinstrument.edit', 'Grid', true],
            ['/bankinstrument', 'Delete Bank Instrument', '/bankinstrument/delete', 'bankinstrument.delete', 'Grid', true],
            ['sibling:/bankreconciliation', 'Loans & Financing', '/loan', 'loan', 'Banknote', false],
            ['/loan', 'Add Loan', '/loan/add', 'loan.add', 'Grid', true],
            ['/loan', 'Pay Loan Instalment', '/loan/pay', 'loan.pay', 'Grid', true],
            ['sibling:/bankreconciliation', 'Tax Payments', '/taxpayment', 'taxpayment', 'Receipt', false],
            ['/taxpayment', 'Add Tax Payment', '/taxpayment/add', 'taxpayment.add', 'Grid', true],
            ['/taxpayment', 'Delete Tax Payment', '/taxpayment/delete', 'taxpayment.delete', 'Grid', true],
            ['sibling:/bankreconciliation', 'Payment Terms', '/paymentterm', 'paymentterm', 'CalendarCheck', false],
            ['/paymentterm', 'Add Payment Term', '/paymentterm/add', 'paymentterm.add', 'Grid', true],
            ['/paymentterm', 'Edit Payment Term', '/paymentterm/:id/edit', 'paymentterm.edit', 'Grid', true],
            ['/paymentterm', 'Delete Payment Term', '/paymentterm/delete', 'paymentterm.delete', 'Grid', true],
            ['/paymentterm', 'Assign Payment Term', '/paymentterm/assign', 'paymentterm.assign', 'Grid', true],
            ['/paymentterm', 'Take Early Payment Discount', '/paymentterm/discount', 'paymentterm.discount', 'Grid', true],
            ['sibling:/bankreconciliation', 'Advance Allocation', '/allocation', 'allocation', 'Shuffle', false],
            ['/allocation', 'Apply Advance Allocation', '/allocation/apply', 'allocation.apply', 'Grid', true],
            ['sibling:/bankreconciliation', 'Cash Flow Forecast', '/cashflowforecast', 'cashflowforecast', 'TrendingUp', false],
            ['sibling:/bankreconciliation', 'Year-End Close', '/yearclose', 'yearclose', 'CalendarX', false],
            ['/yearclose', 'Close Financial Year', '/yearclose/run', 'yearclose.run', 'Grid', true],
            ['/bankreconciliation', 'Book Bank Charge From Statement Line', '/bankreconciliation/charge', 'bankreconciliation.charge', 'Grid', true],
        ];
    }

    public function up(): void
    {
        MenuMigration::install($this->rows());
    }

    public function down(): void
    {
        MenuMigration::remove(array_column($this->rows(), 2));
    }
};
