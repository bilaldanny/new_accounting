<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The general ledger (any account, posting or group) and the trial balance. Both read approved vouchers
 * only, classify by the first digit of the code and start from the stored opening balances of the
 * active financial year.
 */

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function glrQuery(array $extra = []): array
{
    return array_merge(['show_record' => 100], $extra);
}

// ------------------------------------------------------------------ general ledger

test('an account statement runs a balance from the stored opening balance over the vouchers', function () {
    $books = ldgBooks();
    trpActAsSuperadmin();

    $response = prpGet('account-ledger', glrQuery(['company_id' => $books['scope']['company_id'], 'account_code' => '212-00001', 'end_date' => '2026-09-30']));
    $rows = prpRows($response);

    expect($rows->pluck('voucher_no')->all())->toBe(['JV-00001', 'JV-00002', 'JV-00003'])
        ->and($rows->pluck('balance')->all())->toEqual([1500, 1300, 1600])
        ->and($rows->pluck('debit')->all())->toEqual([500, 0, 300])
        ->and($rows->pluck('credit')->all())->toEqual([0, 200, 0])
        ->and($response->json('summary'))->toMatchArray(['count' => 3, 'nature' => 'dr'])
        ->and($response->json('summary.opening'))->toEqual(1000)
        ->and($response->json('summary.debit'))->toEqual(800)
        ->and($response->json('summary.credit'))->toEqual(200)
        ->and($response->json('summary.closing'))->toEqual(1600)
        ->and($response->json('summary.account'))->toMatchArray(['code' => '212-00001', 'name' => 'Cash in Hand', 'class' => 'Assets', 'is_group' => false]);
});

test('a range that starts inside the year opens with the postings before it', function () {
    $books = ldgBooks();
    trpActAsSuperadmin();

    $response = prpGet('account-ledger', glrQuery(['company_id' => $books['scope']['company_id'], 'account_code' => '212-00001', 'start_date' => '2026-08-05', 'end_date' => '2026-09-30']));

    // 1000 stored + 500 posted on 1 August, before the range.
    expect($response->json('summary.opening'))->toEqual(1500)
        ->and(prpRows($response)->pluck('balance')->all())->toEqual([1300, 1600])
        ->and($response->json('summary.closing'))->toEqual(1600);
});

test('the range is inclusive at both ends', function () {
    $books = ldgBooks();
    trpActAsSuperadmin();

    $vouchers = fn (array $range): array => prpRows(prpGet('account-ledger', glrQuery(['company_id' => $books['scope']['company_id'], 'account_code' => '212-00001'] + $range)))->pluck('voucher_no')->all();

    expect($vouchers(['start_date' => '2026-08-10', 'end_date' => '2026-09-05']))->toBe(['JV-00002', 'JV-00003'])
        ->and($vouchers(['start_date' => '2026-08-11', 'end_date' => '2026-09-04']))->toBe([])
        ->and($vouchers(['start_date' => '2026-08-01', 'end_date' => '2026-08-01']))->toBe(['JV-00001']);
});

test('a revenue account is credit-normal by its digit whatever its stored nature says', function () {
    $books = ldgBooks();
    trpActAsSuperadmin();

    $response = prpGet('account-ledger', glrQuery(['company_id' => $books['scope']['company_id'], 'account_code' => '511-00001', 'end_date' => '2026-09-30']));

    expect($response->json('summary.nature'))->toBe('cr')
        ->and(prpRows($response)->pluck('balance')->all())->toEqual([500, 800])
        ->and($response->json('summary.closing'))->toEqual(800)
        ->and($response->json('summary.account.class'))->toBe('Revenue');
});

test('a group account is the sum of every posting account below it', function () {
    $books = ldgBooks();
    $scope = $books['scope'];
    trpActAsSuperadmin();

    $assets = ldgAccount($scope, '200-00000', 'Assets', 'c');
    $current = ldgAccount($scope, '210-00000', 'Current Assets', 'c', 'dr', $assets);
    DB::table('chart_of_accounts')->where('id', $books['cash'])->update(['parent_id' => $current]);
    $debtor = ldgAccount($scope, '213-00001', 'Debtor', 't', 'dr', $current);
    prpOpeningBalance($scope, $debtor, 250, 'dr');
    ldgVoucher($scope, 'JV-00004', '2026-09-20', [['213-00001', 90, 0], ['511-00001', 0, 90]]);

    $response = prpGet('account-ledger', glrQuery(['company_id' => $scope['company_id'], 'account_code' => '200-00000', 'end_date' => '2026-09-30']));
    $rows = prpRows($response);

    expect($response->json('summary.account'))->toMatchArray(['code' => '200-00000', 'is_group' => true])
        ->and($rows->pluck('account_code')->unique()->sort()->values()->all())->toBe(['212-00001', '213-00001'])
        ->and($rows->firstWhere('voucher_no', 'JV-00004')['account_name'])->toBe('Debtor')
        // cash 1000 + debtor 250 stored, then 500 - 200 + 300 + 90
        ->and($response->json('summary.opening'))->toEqual(1250)
        ->and($response->json('summary.closing'))->toEqual(1940)
        ->and($rows->last()['balance'])->toEqual(1940);
});

test('only approved vouchers are read', function () {
    $books = ldgBooks();
    $scope = $books['scope'];
    trpActAsSuperadmin();

    ldgVoucher($scope, 'JV-00005', '2026-09-06', [['212-00001', 999, 0], ['511-00001', 0, 999]], ['status' => 'pending']);
    ldgVoucher($scope, 'JV-00006', '2026-09-07', [['212-00001', 888, 0], ['511-00001', 0, 888]], ['status' => 'rejected']);

    $response = prpGet('account-ledger', glrQuery(['company_id' => $scope['company_id'], 'account_code' => '212-00001', 'end_date' => '2026-09-30']));

    expect(prpRows($response)->pluck('voucher_no')->all())->toBe(['JV-00001', 'JV-00002', 'JV-00003'])
        ->and($response->json('summary.closing'))->toEqual(1600);
});

test('the branch filter limits the vouchers and the stored opening balance', function () {
    $books = ldgBooks();
    $scope = $books['scope'];
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    trpActAsSuperadmin();

    $otherScope = array_merge($scope, ['branch_id' => $otherBranch]);
    $otherCash = ldgAccount($otherScope, '212-00001', 'Cash in Hand');
    prpOpeningBalance($otherScope, $otherCash, 40, 'dr');
    ldgVoucher($otherScope, 'JV-00007', '2026-09-08', [['212-00001', 60, 0], ['511-00001', 0, 60]]);

    $all = prpGet('account-ledger', glrQuery(['company_id' => $scope['company_id'], 'account_code' => '212-00001', 'end_date' => '2026-09-30']));
    $second = prpGet('account-ledger', glrQuery(['company_id' => $scope['company_id'], 'branch_id' => $otherBranch, 'account_code' => '212-00001', 'end_date' => '2026-09-30']));
    $first = prpGet('account-ledger', glrQuery(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'account_code' => '212-00001', 'end_date' => '2026-09-30']));

    expect($all->json('summary.opening'))->toEqual(1040)
        ->and($all->json('summary.closing'))->toEqual(1700)
        ->and($second->json('summary.opening'))->toEqual(40)
        ->and(prpRows($second)->pluck('voucher_no')->all())->toBe(['JV-00007'])
        ->and($second->json('summary.closing'))->toEqual(100)
        ->and($first->json('summary.opening'))->toEqual(1000)
        ->and($first->json('summary.closing'))->toEqual(1600);
});

test('an unknown account and a missing account give an empty statement and a search narrows it', function () {
    $books = ldgBooks();
    trpActAsSuperadmin();

    $company = ['company_id' => $books['scope']['company_id']];

    prpGet('account-ledger', glrQuery($company + ['account_code' => '999-99999']))
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('summary.account', null);
    prpGet('account-ledger', glrQuery($company))->assertSuccessful()->assertJsonPath('data.total', 0);

    $found = prpRows(prpGet('account-ledger', glrQuery($company + ['account_code' => '212-00001', 'search' => 'jv-00002'])));

    expect($found->pluck('voucher_no')->all())->toBe(['JV-00002']);
});

test('the ledger sorts by a known column and stays chronological by default', function () {
    $books = ldgBooks();
    trpActAsSuperadmin();

    $query = glrQuery(['company_id' => $books['scope']['company_id'], 'account_code' => '212-00001', 'end_date' => '2026-09-30']);
    $order = fn (array $extra): array => prpRows(prpGet('account-ledger', $query + $extra))->pluck('voucher_no')->all();

    expect($order([]))->toBe(['JV-00001', 'JV-00002', 'JV-00003'])
        ->and($order(['sort_by' => 'voucher_date', 'sort_type' => 'desc']))->toBe(['JV-00003', 'JV-00002', 'JV-00001'])
        ->and($order(['sort_by' => 'x; drop table users']))->toBe(['JV-00001', 'JV-00002', 'JV-00003']);
});

test('the ledger needs a company, is scoped to it and hostile input stays data', function () {
    $books = ldgBooks();
    $other = trpScope('2');
    prpFinancialYear($other['company_id']);
    ldgAccount($other, '212-00001', 'Other Cash');
    ldgVoucher($other, 'JV-00001', '2026-09-01', [['212-00001', 5000, 0], ['511-00001', 0, 5000]]);

    trpActAsSuperadmin();
    prpGet('account-ledger', glrQuery(['account_code' => '212-00001']))->assertUnprocessable();

    Sanctum::actingAs(jeaUserWith($books['scope'], ['/report/account-ledger']));

    $response = prpGet('account-ledger', glrQuery(['company_id' => $other['company_id'], 'account_code' => '212-00001', 'end_date' => '2026-09-30']));

    expect($response->json('summary.closing'))->toEqual(1600)
        ->and($response->json('summary.account.name'))->toBe('Cash in Hand');

    prpGet('account-ledger', glrQuery(['account_code' => "' OR 1=1 --"]))->assertSuccessful()->assertJsonPath('summary.account', null);
});

// ------------------------------------------------------------------ trial balance

test('the trial balance lists every posting account with opening, movement and closing that balance', function () {
    $books = ldgBooks();
    trpActAsSuperadmin();

    $response = prpGet('trial-balance', glrQuery(['company_id' => $books['scope']['company_id'], 'end_date' => '2026-09-30']));
    $row = fn (string $code): array => prpRows($response)->firstWhere('code', $code);

    expect(prpRows($response)->pluck('code')->all())->toBe(['100-00001', '212-00001', '441-00001', '511-00001'])
        ->and($row('212-00001'))->toMatchArray(['name' => 'Cash in Hand', 'class' => 'Assets'])
        ->and($row('212-00001')['opening_debit'])->toEqual(1000)
        ->and($row('212-00001')['debit'])->toEqual(800)
        ->and($row('212-00001')['credit'])->toEqual(200)
        ->and($row('212-00001')['closing_debit'])->toEqual(1600)
        ->and($row('100-00001')['opening_credit'])->toEqual(1000)
        ->and($row('100-00001')['closing_credit'])->toEqual(1000)
        ->and($row('100-00001')['class'])->toBe('Equity')
        ->and($row('511-00001')['closing_credit'])->toEqual(800)
        ->and($row('511-00001')['class'])->toBe('Revenue')
        ->and($row('441-00001')['closing_debit'])->toEqual(200)
        ->and($response->json('summary'))->toMatchArray(['count' => 4, 'is_balanced' => true])
        ->and($response->json('summary.closing_debit'))->toEqual(1800)
        ->and($response->json('summary.closing_credit'))->toEqual(1800)
        ->and($response->json('summary.closing_difference'))->toEqual(0)
        ->and($response->json('summary.opening_difference'))->toEqual(0)
        ->and($response->json('summary.period_difference'))->toEqual(0);
});

test('a range inside the year moves what was posted before it into the opening column', function () {
    $books = ldgBooks();
    trpActAsSuperadmin();

    $response = prpGet('trial-balance', glrQuery(['company_id' => $books['scope']['company_id'], 'start_date' => '2026-08-05', 'end_date' => '2026-09-30']));
    $cash = prpRows($response)->firstWhere('code', '212-00001');
    $sales = prpRows($response)->firstWhere('code', '511-00001');

    expect($cash['opening_debit'])->toEqual(1500)
        ->and($cash['debit'])->toEqual(300)
        ->and($cash['credit'])->toEqual(200)
        ->and($cash['closing_debit'])->toEqual(1600)
        ->and($sales['opening_credit'])->toEqual(500)
        ->and($sales['credit'])->toEqual(300)
        ->and($sales['closing_credit'])->toEqual(800)
        ->and($response->json('summary.is_balanced'))->toBeTrue();
});

test('an account with no opening balance and no posting in the range is not listed, and the range is inclusive', function () {
    $books = ldgBooks();
    ldgAccount($books['scope'], '441-00002', 'Never Used');
    trpActAsSuperadmin();

    $codes = fn (array $range): array => prpRows(prpGet('trial-balance', glrQuery(['company_id' => $books['scope']['company_id']] + $range)))->pluck('code')->all();

    expect($codes([]))->not->toContain('441-00002')
        ->and($codes(['start_date' => '2026-09-05', 'end_date' => '2026-09-05']))->not->toContain('441-00002')
        ->and($codes(['start_date' => '2026-09-05', 'end_date' => '2026-09-05']))->toContain('212-00001', '511-00001')
        ->and($codes(['start_date' => '2026-09-05', 'end_date' => '2026-09-05']))->toContain('441-00001')
        ->and($codes(['start_date' => '2026-08-10', 'end_date' => '2026-08-10']))->toContain('441-00001');
});

test('an overdrawn account shows on the credit side and an unbalanced book is reported', function () {
    $books = ldgBooks();
    $scope = $books['scope'];
    trpActAsSuperadmin();

    ldgVoucher($scope, 'JV-00009', '2026-09-10', [['212-00001', 0, 5000], ['441-00001', 4000, 0]]);

    $response = prpGet('trial-balance', glrQuery(['company_id' => $scope['company_id'], 'end_date' => '2026-09-30']));
    $cash = prpRows($response)->firstWhere('code', '212-00001');

    // 1600 - 5000 = -3400: credit balance. The voucher is short by 1000, so the books do not balance.
    expect($cash['closing_debit'])->toEqual(0)
        ->and($cash['closing_credit'])->toEqual(3400)
        ->and($response->json('summary.is_balanced'))->toBeFalse()
        ->and($response->json('summary.period_difference'))->toEqual(-1000)
        ->and($response->json('summary.closing_difference'))->toEqual(-1000);
});

test('the trial balance filters by account group and searches by code and name', function () {
    $books = ldgBooks();
    trpActAsSuperadmin();

    $codes = fn (array $extra): array => prpRows(prpGet('trial-balance', glrQuery(['company_id' => $books['scope']['company_id']] + $extra)))->pluck('code')->all();

    expect($codes(['account_group' => 2]))->toBe(['212-00001'])
        ->and($codes(['account_group' => 5]))->toBe(['511-00001'])
        ->and($codes(['account_group' => 1]))->toBe(['100-00001'])
        ->and($codes(['account_group' => 3]))->toBe([])
        ->and($codes(['search' => 'capital']))->toBe(['100-00001'])
        ->and($codes(['search' => '441']))->toBe(['441-00001']);

    prpGet('trial-balance', glrQuery(['company_id' => $books['scope']['company_id'], 'account_group' => 7]))->assertUnprocessable();
});

test('the trial balance branch filter and company scoping', function () {
    $books = ldgBooks();
    $scope = $books['scope'];
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    $otherScope = array_merge($scope, ['branch_id' => $otherBranch]);
    $otherCash = ldgAccount($otherScope, '212-00001', 'Cash in Hand');
    prpOpeningBalance($otherScope, $otherCash, 40, 'dr');
    ldgVoucher($otherScope, 'JV-00007', '2026-09-08', [['212-00001', 60, 0], ['511-00001', 0, 60]]);

    $foreign = trpScope('2');
    prpFinancialYear($foreign['company_id']);
    ldgAccount($foreign, '212-00001', 'Foreign Cash');
    ldgVoucher($foreign, 'JV-00001', '2026-09-01', [['212-00001', 7777, 0], ['511-00001', 0, 7777]]);

    trpActAsSuperadmin();

    $cash = fn (array $extra): array => prpRows(prpGet('trial-balance', glrQuery(['company_id' => $scope['company_id'], 'end_date' => '2026-09-30'] + $extra)))->firstWhere('code', '212-00001');

    expect($cash([])['closing_debit'])->toEqual(1700)
        ->and($cash(['branch_id' => $otherBranch])['closing_debit'])->toEqual(100)
        ->and($cash(['branch_id' => $scope['branch_id']])['closing_debit'])->toEqual(1600);

    prpGet('trial-balance', glrQuery(['end_date' => '2026-09-30']))->assertUnprocessable();

    Sanctum::actingAs(jeaUserWith($scope, ['/report/trial-balance']));
    expect(prpRows(prpGet('trial-balance', glrQuery(['company_id' => $foreign['company_id'], 'end_date' => '2026-09-30'])))->firstWhere('code', '212-00001')['closing_debit'])->toEqual(1600);
});

test('the summary totals the whole trial balance, not the page on screen', function () {
    $books = ldgBooks();
    trpActAsSuperadmin();

    $response = prpGet('trial-balance', glrQuery(['company_id' => $books['scope']['company_id'], 'end_date' => '2026-09-30', 'show_record' => 1]));

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.total'))->toBe(4)
        ->and($response->json('summary.debit'))->toEqual(1000)
        ->and($response->json('summary.credit'))->toEqual(1000);
});
