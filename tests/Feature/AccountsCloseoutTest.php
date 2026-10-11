<?php

use App\Models\BankInstrument;
use App\Models\CashCollection;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TAccount;
use App\Models\User;
use App\Services\AllocationEngine;
use App\Services\AmountInWords;
use App\Services\LoanSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Accounts & Finance closeout, part A (wave 5): petty cash, statement import (OFX / MT940), bank charge from a statement line,
 * cheque printing, guarantees and LCs, loans, tax payments, early-payment discount terms, advance allocation, cash flow forecast.
 * The year-end close is tested with the rollover in YearRolloverTest.
 */
function acoStaff(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'aco_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
}

/**
 * A company with a customer and the accounts the wave-5 features post to. Journal vouchers are auto-approved unless told otherwise.
 *
 * @return array<string, mixed>
 */
function acoScope(bool $autoApprove = true): array
{
    $scope = seedSellScope();

    DB::table('company_settings')->updateOrInsert(
        ['company_id' => $scope['company_id']],
        ['company_id' => $scope['company_id'], 'journal_entry' => $autoApprove ? 1 : 0, 'created_at' => now(), 'updated_at' => now()],
    );

    $accounts = [
        'petty' => ['201-00111', 'Petty Cash', 'dr', false],
        'bank' => ['201-00110', 'Bank Account', 'dr', false],
        'expense' => ['401-00010', 'General Expense', 'dr', true],
        'loan' => ['301-00001', 'Bank Loan', 'cr', false],
        'interest' => ['401-00011', 'Interest Expense', 'dr', true],
        'tax' => ['301-00030', 'Sales Tax Payable', 'cr', false],
        'discount' => ['401-00012', 'Discount Allowed', 'dr', true],
    ];

    foreach ($accounts as $key => [$code, $name, $nature, $pl]) {
        $scope[$key.'_id'] = insertPurchaseChartAccount($scope, $code, $name, $nature, $pl);
        $scope[$key.'_code'] = $code;
    }

    return $scope;
}

/**
 * @return array<string, mixed>
 */
function acoOtherCompany(): array
{
    $companyId = DB::table('companies')->insertGetId(['code' => 'ACO2', 'name' => 'Other Co', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branchId = DB::table('branches')->insertGetId(['code' => 'ACOB2', 'company_id' => $companyId, 'name' => 'Other Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    return ['company_id' => $companyId, 'branch_id' => $branchId];
}

/**
 * @return array{debit: float, credit: float}
 */
function acoVoucherTotals(int $voucherId): array
{
    $lines = DB::table('t_account_details')->where('t_account_id', $voucherId)->get();

    return ['debit' => round((float) $lines->sum('debit'), 2), 'credit' => round((float) $lines->sum('credit'), 2)];
}

// ---------------------------------------------------------------------------------------------------------------------
// Petty cash

test('a petty cash fund spends from its float, cannot overspend, and is replenished back to the imprest amount', function () {
    $scope = acoScope();
    Sanctum::actingAs(acoStaff($scope, ['/pettycash', '/pettycash/add', '/pettycash/expense', '/pettycash/replenish']));

    $fund = $this->postJson('/api/petty-cash/funds', ['name' => 'Front desk', 'imprest_amount' => 1000, 'cash_account_id' => $scope['petty_id']])->assertCreated()->json('data.id');
    $spend = fn (float $amount) => $this->postJson("/api/petty-cash/funds/{$fund}/expense", [
        'entry_date' => '2026-10-02', 'amount' => $amount, 'expense_account_id' => $scope['expense_id'], 'payee' => 'Courier', 'description' => 'Parcel', 'receipt_no' => 'R-1',
    ]);

    // nothing in the float yet
    $spend(100)->assertUnprocessable();

    $first = $this->postJson("/api/petty-cash/funds/{$fund}/replenish", ['entry_date' => '2026-10-01', 'source_account_id' => $scope['bank_id']])->assertCreated();
    expect((float) $first->json('data.amount'))->toBe(1000.0);

    $expense = $spend(300)->assertCreated();
    $spend(800)->assertUnprocessable();

    $row = $this->getJson('/api/petty-cash/funds')->assertOk()->json('data.0');
    expect($row['balance'])->toEqual(700)->and($row['to_replenish'])->toEqual(300);

    // the voucher: Dr expense 300 / Cr petty cash 300
    $voucher = DB::table('petty_cash_entries')->where('id', $expense->json('data.id'))->value('t_account_id');
    $lines = DB::table('t_account_details')->where('t_account_id', $voucher)->get();
    expect(acoVoucherTotals((int) $voucher))->toBe(['debit' => 300.0, 'credit' => 300.0])
        ->and((float) $lines->firstWhere('coa_id', $scope['expense_id'])->debit)->toBe(300.0)
        ->and((float) $lines->firstWhere('coa_id', $scope['petty_id'])->credit)->toBe(300.0);

    // topping up cannot take the fund past its imprest amount
    $this->postJson("/api/petty-cash/funds/{$fund}/replenish", ['entry_date' => '2026-10-03', 'source_account_id' => $scope['bank_id'], 'amount' => 500])->assertUnprocessable();
    $top = $this->postJson("/api/petty-cash/funds/{$fund}/replenish", ['entry_date' => '2026-10-03', 'source_account_id' => $scope['bank_id']])->assertCreated();
    expect((float) $top->json('data.amount'))->toBe(300.0);
    $this->postJson("/api/petty-cash/funds/{$fund}/replenish", ['entry_date' => '2026-10-03', 'source_account_id' => $scope['bank_id']])->assertUnprocessable();
});

test('petty cash vouchers follow the journal approval rule', function () {
    $scope = acoScope(autoApprove: false);
    Sanctum::actingAs(acoStaff($scope, ['/pettycash', '/pettycash/add', '/pettycash/expense', '/pettycash/replenish']));

    $fund = $this->postJson('/api/petty-cash/funds', ['name' => 'Shop', 'imprest_amount' => 500, 'cash_account_id' => $scope['petty_id']])->assertCreated()->json('data.id');
    $this->postJson("/api/petty-cash/funds/{$fund}/replenish", ['entry_date' => '2026-10-01', 'source_account_id' => $scope['bank_id']])->assertCreated();

    $entries = $this->getJson("/api/petty-cash/funds/{$fund}/entries")->assertOk()->json('data');
    expect($entries[0]['voucher_status'])->toBe('pending');
});

test('petty cash funds are company scoped and need their permissions', function () {
    $scope = acoScope();
    $other = acoOtherCompany();
    $fund = DB::table('petty_cash_funds')->insertGetId(['company_id' => $other['company_id'], 'name' => 'Foreign', 'imprest_amount' => 100, 'cash_account_id' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs(acoStaff($scope, ['/pettycash']));
    $this->getJson('/api/petty-cash/funds')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/petty-cash/funds/{$fund}/entries")->assertNotFound();
    $this->postJson('/api/petty-cash/funds', ['name' => 'X', 'imprest_amount' => 10, 'cash_account_id' => $scope['petty_id']])->assertForbidden();

    Sanctum::actingAs(acoStaff($scope, ['/pettycash/expense', '/pettycash/replenish']));
    $this->postJson("/api/petty-cash/funds/{$fund}/expense", ['entry_date' => '2026-10-02', 'amount' => 5, 'expense_account_id' => $scope['expense_id']])->assertNotFound();
    $this->postJson("/api/petty-cash/funds/{$fund}/replenish", ['entry_date' => '2026-10-02', 'source_account_id' => $scope['bank_id']])->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// OFX / MT940 import and bank charge

test('an OFX statement is read into lines, dates and balances', function () {
    $scope = acoScope();
    Sanctum::actingAs(acoStaff($scope, ['/bankreconciliation/add']));

    $ofx = "OFXHEADER:100\nDATA:OFXSGML\n\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><CURDEF>PKR<BANKACCTFROM><BANKID>1<ACCTID>12345678<ACCTTYPE>CHECKING</BANKACCTFROM>\n"
        ."<BANKTRANLIST><DTSTART>20260901<DTEND>20260930\n"
        ."<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20260903120000<TRNAMT>1000.00<FITID>A1<NAME>Deposit</STMTTRN>\n"
        ."<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260906<TRNAMT>-400.50<FITID>A2<CHECKNUM>CHQ1<MEMO>Cheque 1</STMTTRN>\n"
        .'</BANKTRANLIST><LEDGERBAL><BALAMT>5599.50<DTASOF>20260930</LEDGERBAL></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';

    $data = $this->postJson('/api/bank-reconciliations/parse', ['format' => 'ofx', 'content' => $ofx])->assertOk()->json('data');

    expect($data['rows'])->toHaveCount(2)
        ->and($data['rows'][0])->toMatchArray(['txn_date' => '2026-09-03', 'amount' => 1000])
        ->and($data['rows'][1])->toMatchArray(['txn_date' => '2026-09-06', 'amount' => -400.5, 'reference' => 'CHQ1'])
        ->and($data['closing_balance'])->toEqual(5599.5)
        ->and($data['opening_balance'])->toEqual(5000)
        ->and($data['from'])->toBe('2026-09-01')
        ->and($data['to'])->toBe('2026-09-30')
        ->and($data['account'])->toBe('12345678');
});

test('an MT940 statement is read into lines, dates and balances, and bad input is refused', function () {
    $scope = acoScope();
    Sanctum::actingAs(acoStaff($scope, ['/bankreconciliation/add']));

    $mt940 = ":20:STMT001\n:25:12345678\n:28C:1/1\n:60F:C260901PKR5000,00\n"
        .":61:2609030903C1000,00NTRFNONREF//BANKREF1\n:86:Deposit from customer\n"
        .":61:2609060906D400,50NCHKCHQ1//BANKREF2\n:86:Cheque 1\n"
        .":62F:C260930PKR5599,50\n";

    $data = $this->postJson('/api/bank-reconciliations/parse', ['format' => 'mt940', 'content' => $mt940])->assertOk()->json('data');

    expect($data['rows'])->toHaveCount(2)
        ->and($data['rows'][0])->toMatchArray(['txn_date' => '2026-09-03', 'amount' => 1000, 'description' => 'Deposit from customer'])
        ->and($data['rows'][1])->toMatchArray(['txn_date' => '2026-09-06', 'amount' => -400.5, 'reference' => 'CHQ1'])
        ->and($data['opening_balance'])->toEqual(5000)
        ->and($data['closing_balance'])->toEqual(5599.5)
        ->and($data['from'])->toBe('2026-09-01');

    $this->postJson('/api/bank-reconciliations/parse', ['format' => 'mt940', 'content' => 'this is not a statement'])->assertUnprocessable();
    $this->postJson('/api/bank-reconciliations/parse', ['format' => 'ofx', 'content' => '<OFX></OFX>'])->assertUnprocessable();
});

test('a statement line of money out is booked as a bank charge and matched to its voucher', function () {
    $scope = acoScope();
    Sanctum::actingAs(acoStaff($scope, ['/bankreconciliation', '/bankreconciliation/charge']));
    $financialYear = prpFinancialYear($scope['company_id'], '2026-01-01', '2026-12-31');

    $statement = DB::table('bank_statements')->insertGetId([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'account_code' => $scope['bank_code'], 'statement_from' => '2026-09-01',
        'statement_to' => '2026-09-30', 'opening_balance' => 0, 'closing_balance' => -25, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $out = DB::table('bank_statement_lines')->insertGetId(['bank_statement_id' => $statement, 'txn_date' => '2026-09-15', 'description' => 'Monthly service charge', 'amount' => -25, 'created_at' => now(), 'updated_at' => now()]);
    $in = DB::table('bank_statement_lines')->insertGetId(['bank_statement_id' => $statement, 'txn_date' => '2026-09-16', 'description' => 'Deposit', 'amount' => 90, 'created_at' => now(), 'updated_at' => now()]);

    $this->postJson("/api/bank-reconciliations/{$statement}/lines/{$in}/charge", ['expense_account_id' => $scope['expense_id']])->assertUnprocessable();
    $this->postJson("/api/bank-reconciliations/{$statement}/lines/{$out}/charge", ['expense_account_id' => $scope['bank_id']])->assertUnprocessable();

    $this->postJson("/api/bank-reconciliations/{$statement}/lines/{$out}/charge", ['expense_account_id' => $scope['expense_id']])->assertOk();

    $line = DB::table('bank_statement_lines')->find($out);
    expect($line->charge_t_account_id)->not->toBeNull()->and($line->matched_detail_id)->not->toBeNull();

    $details = DB::table('t_account_details')->where('t_account_id', $line->charge_t_account_id)->get();
    expect((float) $details->firstWhere('coa_id', $scope['expense_id'])->debit)->toBe(25.0)
        ->and((float) $details->firstWhere('coa_id', $scope['bank_id'])->credit)->toBe(25.0)
        ->and((int) $line->matched_detail_id)->toBe((int) $details->firstWhere('coa_id', $scope['bank_id'])->id);

    // once booked it cannot be booked again
    $this->postJson("/api/bank-reconciliations/{$statement}/lines/{$out}/charge", ['expense_account_id' => $scope['expense_id']])->assertUnprocessable();
    expect($financialYear)->toBeInt();
});

// ---------------------------------------------------------------------------------------------------------------------
// Cheque printing

test('amounts are written out in words for a cheque', function () {
    expect(AmountInWords::convert(123456.78))->toBe('One Lakh Twenty Three Thousand Four Hundred Fifty Six and 78/100 Only')
        ->and(AmountInWords::convert(1000000, 'international'))->toBe('One Million and 00/100 Only')
        ->and(AmountInWords::convert(0.5))->toBe('Zero and 50/100 Only')
        ->and(AmountInWords::convert(15))->toBe('Fifteen and 00/100 Only');
});

test('printing a voucher cheque marks it printed and needs a reprint to print again', function () {
    $scope = acoScope();
    Sanctum::actingAs(acoStaff($scope, ['/chequeprint', '/chequeprint/layout', '/chequeprint/print']));

    $layout = $this->postJson('/api/cheque-layouts', [
        'name' => 'Standard', 'width_mm' => 203, 'height_mm' => 95, 'payee_x' => 25, 'payee_y' => 30, 'words_x' => 25, 'words_y' => 42,
        'amount_x' => 150, 'amount_y' => 52, 'date_x' => 150, 'date_y' => 14, 'font_size' => 12, 'account_payee' => true, 'is_default' => true,
    ])->assertCreated()->json('data.id');

    $voucher = ldgVoucher($scope, 'PV-1', '2026-10-05', [[$scope['bank_code'], 0, 2500.5, $scope['customer_id'], $scope['bank_id']], [$scope['expense_code'], 2500.5, 0, $scope['customer_id'], $scope['expense_id']]], ['total_amount' => 2500.5, 'cheque_no' => '000771']);

    $sheet = $this->postJson('/api/cheque-print', ['t_account_id' => $voucher])->assertOk()->json('data');
    expect($sheet['payee'])->toBe('Acme Retail')
        ->and($sheet['amount'])->toEqual(2500.5)
        ->and($sheet['amount_words'])->toBe('Two Thousand Five Hundred and 50/100 Only')
        ->and($sheet['date_text'])->toBe('05-10-2026')
        ->and($sheet['layout']['id'])->toBe($layout);

    expect((bool) DB::table('t_accounts')->where('id', $voucher)->value('is_print'))->toBeTrue();

    $this->postJson('/api/cheque-print', ['t_account_id' => $voucher])->assertUnprocessable();
    $this->postJson('/api/cheque-print', ['t_account_id' => $voucher, 'reprint' => true])->assertOk();

    // a typed cheque needs no voucher
    $this->postJson('/api/cheque-print', ['payee' => 'Cash', 'amount' => 10, 'date' => '2026-10-06'])->assertOk()->assertJsonPath('data.payee', 'Cash');
});

test('cheque layouts and vouchers of another company are not reachable', function () {
    $scope = acoScope();
    $other = acoOtherCompany();
    $foreignLayout = DB::table('cheque_layouts')->insertGetId(['company_id' => $other['company_id'], 'name' => 'Foreign', 'created_at' => now(), 'updated_at' => now()]);
    $foreignVoucher = ldgVoucher($other, 'PV-F', '2026-10-05', [['999-00001', 10, 0], ['999-00002', 0, 10]], ['total_amount' => 10]);

    Sanctum::actingAs(acoStaff($scope, ['/chequeprint', '/chequeprint/layout', '/chequeprint/print']));
    $this->getJson('/api/cheque-layouts')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson("/api/cheque-layouts/{$foreignLayout}")->assertNotFound();
    $this->postJson('/api/cheque-print', ['t_account_id' => $foreignVoucher])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// Guarantees and letters of credit

test('a guarantee past its expiry reads as expired and the expiring filter finds the ones about to', function () {
    $scope = acoScope();
    Sanctum::actingAs(acoStaff($scope, ['/bankinstrument', '/bankinstrument/add', '/bankinstrument/:id/edit', '/bankinstrument/delete']));

    $make = fn (string $ref, string $expires, string $kind = 'guarantee') => $this->postJson('/api/bank-instruments', [
        'kind' => $kind, 'reference_no' => $ref, 'bank_name' => 'HBL', 'amount' => 50000, 'expires_on' => $expires,
    ])->assertCreated()->json('data.id');

    $past = $make('BG-1', now()->subDays(3)->toDateString());
    $soon = $make('BG-2', now()->addDays(10)->toDateString());
    $make('LC-1', now()->addDays(200)->toDateString(), 'lc');

    $all = collect($this->getJson('/api/bank-instruments')->assertOk()->json('data.data'))->keyBy('reference_no');
    expect($all['BG-1']['state'])->toBe('expired')->and($all['BG-1']['status'])->toBe('open')->and($all['BG-2']['state'])->toBe('open');

    $expiring = collect($this->getJson('/api/bank-instruments?expiring_within=30')->assertOk()->json('data.data'))->pluck('reference_no')->all();
    expect($expiring)->toContain('BG-2')->not->toContain('LC-1');

    $this->putJson("/api/bank-instruments/{$past}", ['kind' => 'guarantee', 'reference_no' => 'BG-1', 'amount' => 50000, 'status' => 'released'])->assertOk();
    expect(BankInstrument::query()->findOrFail($past)->status)->toBe('released');
    $this->deleteJson("/api/bank-instruments/{$soon}")->assertOk();
});

test('bank instruments are company scoped', function () {
    $scope = acoScope();
    $other = acoOtherCompany();
    $foreign = BankInstrument::query()->create(['company_id' => $other['company_id'], 'kind' => 'lc', 'reference_no' => 'F-1', 'amount' => 1, 'status' => 'open']);

    Sanctum::actingAs(acoStaff($scope, ['/bankinstrument', '/bankinstrument/:id/edit', '/bankinstrument/delete']));
    $this->getJson('/api/bank-instruments')->assertOk()->assertJsonCount(0, 'data.data');
    $this->putJson("/api/bank-instruments/{$foreign->id}", ['kind' => 'lc', 'reference_no' => 'F-1', 'amount' => 2])->assertNotFound();
    $this->deleteJson("/api/bank-instruments/{$foreign->id}")->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// Loans

test('a loan schedule adds up to the principal and the last instalment absorbs the rounding', function () {
    $rows = LoanSchedule::build(100000, 12, 12, 'monthly', '2026-01-31');

    expect($rows)->toHaveCount(12)
        ->and(round(array_sum(array_column($rows, 'principal')), 2))->toBe(100000.0)
        ->and($rows[0]['due_on'])->toBe('2026-02-28')
        ->and($rows[11]['due_on'])->toBe('2027-01-31')
        ->and($rows[0]['interest'])->toBe(1000.0);

    $flat = LoanSchedule::build(1000, 0, 3, 'quarterly', '2026-01-01');
    expect(array_column($flat, 'principal'))->toBe([333.33, 333.33, 333.34])->and(array_sum(array_column($flat, 'interest')))->toEqual(0);
});

test('a loan is booked with a disbursement voucher, instalments post principal and interest, and it closes when paid up', function () {
    $scope = acoScope();
    Sanctum::actingAs(acoStaff($scope, ['/loan', '/loan/add', '/loan/pay']));

    $made = $this->postJson('/api/loans', [
        'name' => 'Term loan', 'lender' => 'HBL', 'principal' => 3000, 'annual_rate' => 12, 'installments' => 3, 'frequency' => 'monthly', 'start_date' => '2026-10-01',
        'loan_account_id' => $scope['loan_id'], 'interest_account_id' => $scope['interest_id'], 'bank_account_id' => $scope['bank_id'], 'post_disbursement' => true,
    ])->assertCreated();
    $loanId = $made->json('data.id');
    $loan = Loan::query()->findOrFail($loanId);

    $disbursement = DB::table('t_account_details')->where('t_account_id', $loan->disbursement_t_account_id)->get();
    expect((float) $disbursement->firstWhere('coa_id', $scope['bank_id'])->debit)->toBe(3000.0)
        ->and((float) $disbursement->firstWhere('coa_id', $scope['loan_id'])->credit)->toBe(3000.0);

    $listed = $this->getJson('/api/loans')->assertOk()->json('data.0');
    expect($listed['outstanding_principal'])->toEqual(3000)->and($listed['paid_instalments'])->toBe(0);

    $this->postJson("/api/loans/{$loanId}/pay", ['paid_on' => '2026-11-01'])->assertOk();
    $row = $loan->schedule()->first();
    $voucher = DB::table('t_account_details')->where('t_account_id', $row->t_account_id)->get();
    expect((float) $voucher->firstWhere('coa_id', $scope['loan_id'])->debit)->toBe((float) $row->principal)
        ->and((float) $voucher->firstWhere('coa_id', $scope['interest_id'])->debit)->toBe((float) $row->interest)
        ->and((float) $voucher->firstWhere('coa_id', $scope['bank_id'])->credit)->toBe((float) $row->total)
        ->and(acoVoucherTotals((int) $row->t_account_id)['debit'])->toBe(acoVoucherTotals((int) $row->t_account_id)['credit']);

    $this->postJson("/api/loans/{$loanId}/pay", ['paid_on' => '2026-12-01'])->assertOk();
    $this->postJson("/api/loans/{$loanId}/pay", ['paid_on' => '2027-01-01'])->assertOk();

    expect($loan->fresh()->status)->toBe('closed');
    $this->postJson("/api/loans/{$loanId}/pay", ['paid_on' => '2027-02-01'])->assertUnprocessable();
});

test('loans are company scoped and need their accounts to be the company\'s own', function () {
    $scope = acoScope();
    $other = acoOtherCompany();
    $foreignAccount = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'code' => '301-00009', 'name' => 'Foreign', 'acc_type' => 't', 'acc_nature' => 'cr',
        'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreign = Loan::query()->create([
        'company_id' => $other['company_id'], 'name' => 'Foreign', 'principal' => 1, 'annual_rate' => 0, 'installments' => 1, 'frequency' => 'monthly',
        'start_date' => '2026-10-01', 'loan_account_id' => $foreignAccount, 'interest_account_id' => $foreignAccount, 'bank_account_id' => $foreignAccount, 'status' => 'active',
    ]);

    Sanctum::actingAs(acoStaff($scope, ['/loan', '/loan/add', '/loan/pay']));
    $this->getJson('/api/loans')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/loans/{$foreign->id}")->assertNotFound();
    $this->postJson("/api/loans/{$foreign->id}/pay", ['paid_on' => '2026-11-01'])->assertNotFound();
    $this->postJson('/api/loans', [
        'name' => 'Mine', 'principal' => 100, 'annual_rate' => 5, 'installments' => 2, 'frequency' => 'monthly', 'start_date' => '2026-10-01',
        'loan_account_id' => $foreignAccount, 'interest_account_id' => $scope['interest_id'], 'bank_account_id' => $scope['bank_id'],
    ])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// Tax payments

test('a tax payment posts a voucher, a challan is recorded once, and deleting it removes the voucher', function () {
    $scope = acoScope();
    Sanctum::actingAs(acoStaff($scope, ['/taxpayment', '/taxpayment/add', '/taxpayment/delete']));

    $body = ['tax_type' => 'Sales tax', 'period' => '2026-09', 'challan_no' => 'CH-100', 'bank_name' => 'NBP', 'amount' => 12500.5, 'paid_on' => '2026-10-10',
        'tax_account_id' => $scope['tax_id'], 'bank_account_id' => $scope['bank_id']];

    $made = $this->postJson('/api/tax-payments', $body)->assertCreated();
    $voucherId = $made->json('data.t_account_id');
    $lines = DB::table('t_account_details')->where('t_account_id', $voucherId)->get();

    expect((float) $lines->firstWhere('coa_id', $scope['tax_id'])->debit)->toBe(12500.5)
        ->and((float) $lines->firstWhere('coa_id', $scope['bank_id'])->credit)->toBe(12500.5);

    $this->postJson('/api/tax-payments', $body)->assertUnprocessable();
    $this->postJson('/api/tax-payments', ['challan_no' => 'CH-101', 'amount' => 500] + $body)->assertCreated();

    $summary = $this->getJson('/api/tax-payments/summary')->assertOk()->json('data');
    expect($summary[0]['tax_type'])->toBe('Sales tax')->and($summary[0]['payments'])->toBe(2)->and($summary[0]['total'])->toEqual(13000.5);

    $this->deleteJson('/api/tax-payments/'.$made->json('data.id'))->assertOk();
    expect(TAccount::query()->whereKey($voucherId)->exists())->toBeFalse();
});

test('tax payments are company scoped', function () {
    $scope = acoScope();
    $other = acoOtherCompany();
    $id = DB::table('tax_payments')->insertGetId([
        'company_id' => $other['company_id'], 'tax_type' => 'Income tax', 'challan_no' => 'X-1', 'amount' => 5, 'paid_on' => '2026-10-01',
        'tax_account_id' => 1, 'bank_account_id' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs(acoStaff($scope, ['/taxpayment', '/taxpayment/delete']));
    $this->getJson('/api/tax-payments')->assertOk()->assertJsonCount(0, 'data.data');
    $this->deleteJson("/api/tax-payments/{$id}")->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// Payment terms and early-payment discount

test('an early-payment discount is only taken inside its window, posts a voucher and settles that part of the invoice', function () {
    $scope = acoScope();
    $sale = createSellRecord($scope, ['final_amount' => 1000, 'invoice_no' => 'INV-DISC', 'transaction_date' => '2026-10-01']);
    Sanctum::actingAs(acoStaff($scope, ['/paymentterm', '/paymentterm/add', '/paymentterm/assign', '/paymentterm/discount']));

    $before = DB::table('payments')->count();
    $vouchers = DB::table('t_accounts')->count();

    // nothing assigned: nothing is eligible and nothing moves
    $this->getJson('/api/early-payment-discounts?on=2026-10-05')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/early-payment-discounts', ['transaction_id' => $sale->id, 'discount_account_id' => $scope['discount_id'], 'applied_on' => '2026-10-05'])->assertUnprocessable();
    expect(DB::table('payments')->count())->toBe($before)->and(DB::table('t_accounts')->count())->toBe($vouchers);

    $term = $this->postJson('/api/payment-terms', ['name' => '2/10 net 30', 'net_days' => 30, 'discount_percent' => 2, 'discount_days' => 10])->assertCreated()->json('data.id');
    $this->postJson('/api/payment-terms/assign', ['contact_id' => $scope['contact_id'], 'payment_term_id' => $term])->assertOk();

    $eligible = $this->getJson('/api/early-payment-discounts?on=2026-10-05')->assertOk()->json('data');
    expect($eligible)->toHaveCount(1)->and($eligible[0]['discount'])->toEqual(20)->and($eligible[0]['discount_until'])->toBe('2026-10-11');
    $this->getJson('/api/early-payment-discounts?on=2026-10-20')->assertOk()->assertJsonCount(0, 'data');

    // outside the window
    $this->postJson('/api/early-payment-discounts', ['transaction_id' => $sale->id, 'discount_account_id' => $scope['discount_id'], 'applied_on' => '2026-10-20'])->assertUnprocessable();

    $this->postJson('/api/early-payment-discounts', ['transaction_id' => $sale->id, 'discount_account_id' => $scope['discount_id'], 'applied_on' => '2026-10-05'])->assertCreated();

    $payment = Payment::query()->where('transaction_id', $sale->id)->firstOrFail();
    $lines = DB::table('t_account_details')->where('t_account_id', $payment->t_account_id)->get();
    expect((float) $payment->amount)->toBe(20.0)
        ->and((float) $lines->firstWhere('coa_id', $scope['discount_id'])->debit)->toBe(20.0)
        ->and((float) $lines->firstWhere('coa_id', $scope['customer_coa_id'])->credit)->toBe(20.0)
        ->and($sale->fresh()->payment_status)->toBe('partial')
        ->and(Payment::remainingAmountForTransaction($sale->fresh()))->toBe(980.0);

    // once only
    $this->postJson('/api/early-payment-discounts', ['transaction_id' => $sale->id, 'discount_account_id' => $scope['discount_id'], 'applied_on' => '2026-10-06'])->assertUnprocessable();
});

test('payment terms and their assignment are company scoped', function () {
    $scope = acoScope();
    $other = acoOtherCompany();
    $foreignTerm = DB::table('payment_terms')->insertGetId(['company_id' => $other['company_id'], 'name' => 'Foreign', 'net_days' => 5, 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs(acoStaff($scope, ['/paymentterm', '/paymentterm/:id/edit', '/paymentterm/delete', '/paymentterm/assign']));
    $this->getJson('/api/payment-terms')->assertOk()->assertJsonCount(0, 'data');
    $this->putJson("/api/payment-terms/{$foreignTerm}", ['name' => 'x', 'net_days' => 1])->assertNotFound();
    $this->postJson('/api/payment-terms/assign', ['contact_id' => $scope['contact_id'], 'payment_term_id' => $foreignTerm])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// Advance allocation

test('advances are matched to open invoices in the order the strategy names and applied without moving cash', function () {
    $scope = acoScope();
    $old = createSellRecord($scope, ['final_amount' => 300, 'invoice_no' => 'INV-OLD', 'transaction_date' => '2026-08-01']);
    $big = createSellRecord($scope, ['final_amount' => 900, 'invoice_no' => 'INV-BIG', 'transaction_date' => '2026-08-15']);
    $small = createSellRecord($scope, ['final_amount' => 100, 'invoice_no' => 'INV-SMALL', 'transaction_date' => '2026-09-01']);

    $collection = CashCollection::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'], 'reference' => 'CC-1',
        'collected_on' => '2026-09-10', 'amount' => 500, 'advance_amount' => 500, 'status' => CashCollection::STATUS_COMPLETED,
    ]);

    Sanctum::actingAs(acoStaff($scope, ['/allocation', '/allocation/apply']));

    $fifo = $this->getJson("/api/allocation/suggest?contact_id={$scope['contact_id']}&role=customer&strategy=fifo")->assertOk()->json('data');
    expect($fifo['advances'])->toHaveCount(1)
        ->and(collect($fifo['suggestion'])->map(fn (array $pair): array => [$pair['invoice_no'], $pair['amount']])->all())->toEqual([['INV-OLD', 300], ['INV-BIG', 200]])
        ->and($fifo['unallocated'])->toEqual(0);

    $smallest = $this->getJson("/api/allocation/suggest?contact_id={$scope['contact_id']}&role=customer&strategy=smallest")->assertOk()->json('data');
    expect(collect($smallest['suggestion'])->map(fn (array $pair): array => [$pair['invoice_no'], $pair['amount']])->all())->toEqual([['INV-SMALL', 100], ['INV-OLD', 300], ['INV-BIG', 100]]);

    $vouchers = DB::table('t_accounts')->count();
    $this->postJson('/api/allocation/apply', [
        'contact_id' => $scope['contact_id'], 'role' => 'customer', 'paid_on' => '2026-10-01',
        'allocations' => collect($fifo['suggestion'])->map(fn (array $pair): array => ['source_id' => $pair['source_id'], 'transaction_id' => $pair['transaction_id'], 'amount' => $pair['amount']])->all(),
    ])->assertOk();

    expect($old->fresh()->payment_status)->toBe('paid')
        ->and($big->fresh()->payment_status)->toBe('partial')
        ->and($small->fresh()->payment_status)->toBe('due')
        ->and($collection->fresh()->advanceRemaining())->toBe(0.0)
        ->and(DB::table('t_accounts')->count())->toBe($vouchers);

    // the advance is used up: nothing more to suggest
    $this->getJson("/api/allocation/suggest?contact_id={$scope['contact_id']}&role=customer")->assertOk()->assertJsonCount(0, 'data.advances');
    expect(AllocationEngine::STRATEGIES)->toContain('fifo');
});

test('an allocation cannot take more than an advance holds or use another company\'s advance', function () {
    $scope = acoScope();
    $other = acoOtherCompany();
    $sale = createSellRecord($scope, ['final_amount' => 900, 'invoice_no' => 'INV-X']);
    $collection = CashCollection::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'], 'reference' => 'CC-2',
        'collected_on' => '2026-09-10', 'amount' => 100, 'advance_amount' => 100, 'status' => CashCollection::STATUS_COMPLETED,
    ]);

    Sanctum::actingAs(acoStaff($scope, ['/allocation', '/allocation/apply']));
    $this->postJson('/api/allocation/apply', [
        'contact_id' => $scope['contact_id'], 'role' => 'customer', 'paid_on' => '2026-10-01',
        'allocations' => [['source_id' => $collection->id, 'transaction_id' => $sale->id, 'amount' => 150]],
    ])->assertUnprocessable();
    expect($sale->fresh()->payment_status)->toBe('due');

    Sanctum::actingAs(acoStaff($other, ['/allocation', '/allocation/apply']));
    $this->getJson("/api/allocation/suggest?contact_id={$scope['contact_id']}&role=customer")->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// Cash flow forecast

test('the forecast puts open invoices, bills and loan instalments into the week they fall due and totals the cash after each', function () {
    $scope = acoScope();
    $today = now()->startOfDay();

    createSellRecord($scope, ['final_amount' => 1000, 'invoice_no' => 'INV-F1', 'transaction_date' => $today->copy()->subDays(5)->toDateString(), 'pay_term' => 10, 'pay_type' => 'day']);
    createSellRecord($scope, ['final_amount' => 400, 'invoice_no' => 'INV-OVER', 'transaction_date' => $today->copy()->subDays(40)->toDateString(), 'pay_term' => 10, 'pay_type' => 'day']);
    createPurchaseRecord($scope, ['status' => 'received', 'invoice_no' => 'PO-F1', 'final_amount' => 600, 'transaction_date' => $today->copy()->toDateString(), 'pay_term' => 3, 'pay_type' => 'day']);

    $loan = DB::table('loans')->insertGetId([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => 'L', 'principal' => 700, 'annual_rate' => 0, 'installments' => 1,
        'frequency' => 'monthly', 'start_date' => $today->toDateString(), 'loan_account_id' => $scope['loan_id'], 'interest_account_id' => $scope['interest_id'],
        'bank_account_id' => $scope['bank_id'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('loan_schedule_rows')->insert(['loan_id' => $loan, 'number' => 1, 'due_on' => $today->copy()->addDays(8)->toDateString(), 'principal' => 700, 'interest' => 0, 'total' => 700, 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs(acoStaff($scope, ['/cashflowforecast']));
    $data = $this->getJson('/api/cash-flow-forecast?weeks=4')->assertOk()->json('data');
    $buckets = collect($data['buckets'])->keyBy('label');

    expect($buckets['Overdue']['receivables'])->toEqual(400)
        ->and($buckets['Week 1']['receivables'])->toEqual(1000)
        ->and($buckets['Week 1']['payables'])->toEqual(600)
        ->and($buckets['Week 2']['loan_instalments'])->toEqual(700)
        ->and($data['opening_balance'])->toEqual(0)
        ->and($buckets['Week 2']['closing'])->toEqual(100)
        ->and($data['lowest_closing'])->toEqual(100);
});

test('the forecast reads only the company\'s own documents', function () {
    $scope = acoScope();
    $other = acoOtherCompany();
    createSellRecord($scope, ['final_amount' => 1000, 'invoice_no' => 'INV-MINE', 'transaction_date' => now()->toDateString(), 'pay_term' => 1, 'pay_type' => 'day']);

    Sanctum::actingAs(acoStaff($other, ['/cashflowforecast']));
    $data = $this->getJson('/api/cash-flow-forecast')->assertOk()->json('data');
    expect(collect($data['buckets'])->sum('receivables'))->toEqual(0);
});

test('a discount taken on a supplier bill debits the supplier and credits the discount account', function () {
    $scope = acoScope();
    $supplierId = (int) DB::table('contacts')->where('company_id', $scope['company_id'])->where('user_type', 'supplier')->value('id');
    $bill = createPurchaseRecord(array_merge($scope, ['contact_id' => $supplierId]), ['final_amount' => 500, 'invoice_no' => 'PO-DISC', 'transaction_date' => '2026-10-01']);
    Sanctum::actingAs(acoStaff($scope, ['/paymentterm', '/paymentterm/add', '/paymentterm/assign', '/paymentterm/discount']));

    $term = $this->postJson('/api/payment-terms', ['name' => '2/10 net 30', 'net_days' => 30, 'discount_percent' => 2, 'discount_days' => 10])->assertCreated()->json('data.id');
    $this->postJson('/api/payment-terms/assign', ['contact_id' => $supplierId, 'payment_term_id' => $term])->assertOk();

    $this->postJson('/api/early-payment-discounts', ['transaction_id' => $bill->id, 'discount_account_id' => $scope['discount_id'], 'applied_on' => '2026-10-04'])->assertCreated();

    $payment = Payment::query()->where('transaction_id', $bill->id)->firstOrFail();
    $lines = DB::table('t_account_details')->where('t_account_id', $payment->t_account_id)->get();
    expect((float) $payment->amount)->toBe(10.0)
        ->and((float) $lines->firstWhere('coa_id', $scope['supplier_coa_id'])->debit)->toBe(10.0)
        ->and((float) $lines->firstWhere('coa_id', $scope['discount_id'])->credit)->toBe(10.0)
        ->and(Payment::remainingAmountForTransaction($bill->fresh()))->toBe(490.0);
});

test('a supplier advance is matched to bills by the same engine', function () {
    $scope = acoScope();
    $supplierId = (int) DB::table('contacts')->where('company_id', $scope['company_id'])->where('user_type', 'supplier')->value('id');
    $withSupplier = array_merge($scope, ['contact_id' => $supplierId]);
    $a = createPurchaseRecord($withSupplier, ['final_amount' => 100, 'invoice_no' => 'PO-A', 'transaction_date' => '2026-08-01']);
    $b = createPurchaseRecord($withSupplier, ['final_amount' => 200, 'invoice_no' => 'PO-B', 'transaction_date' => '2026-08-10']);

    Sanctum::actingAs(acoStaff($scope, ['/supplierpayment', '/supplierpayment/add', '/allocation', '/allocation/apply']));
    $this->postJson('/api/supplier-payments', [
        'contact_id' => $supplierId, 'paid_on' => '2026-09-10', 'payment_account' => $scope['petty_id'], 'method' => 'cash', 'amount' => 250, 'keep_advance' => true,
    ])->assertCreated();

    $suggest = $this->getJson("/api/allocation/suggest?contact_id={$supplierId}&role=supplier&strategy=fifo")->assertOk()->json('data');
    expect(collect($suggest['suggestion'])->map(fn (array $pair): array => [$pair['invoice_no'], $pair['amount']])->all())->toEqual([['PO-A', 100], ['PO-B', 150]]);

    $this->postJson('/api/allocation/apply', [
        'contact_id' => $supplierId, 'role' => 'supplier', 'paid_on' => '2026-10-01',
        'allocations' => collect($suggest['suggestion'])->map(fn (array $pair): array => ['source_id' => $pair['source_id'], 'transaction_id' => $pair['transaction_id'], 'amount' => $pair['amount']])->all(),
    ])->assertOk();

    expect($a->fresh()->payment_status)->toBe('paid')->and($b->fresh()->payment_status)->toBe('partial');
});

test('nothing here posts by itself: reading the pages and the forecast leaves the ledger, payments and balances as they were', function () {
    $scope = acoScope();
    createSellRecord($scope, ['final_amount' => 250, 'invoice_no' => 'INV-QUIET']);
    Sanctum::actingAs(acoStaff($scope, [
        '/pettycash', '/chequeprint', '/bankinstrument', '/loan', '/taxpayment', '/paymentterm', '/allocation', '/cashflowforecast', '/yearclose',
    ]));

    $before = [DB::table('t_accounts')->count(), DB::table('t_account_details')->count(), DB::table('payments')->count(), (float) DB::table('transactions')->sum('paid_amount')];

    foreach (['petty-cash/funds', 'cheque-layouts', 'bank-instruments', 'loans', 'tax-payments', 'tax-payments/summary', 'payment-terms', 'early-payment-discounts', 'cash-flow-forecast', 'year-close'] as $path) {
        $this->getJson('/api/'.$path)->assertOk();
    }

    $this->getJson("/api/allocation/suggest?contact_id={$scope['contact_id']}&role=customer")->assertOk();

    expect([DB::table('t_accounts')->count(), DB::table('t_account_details')->count(), DB::table('payments')->count(), (float) DB::table('transactions')->sum('paid_amount')])->toBe($before);
});
