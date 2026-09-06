<?php

use App\Models\ChartOfAccount;
use App\Models\TAccount;
use App\Models\TAccountDetail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountCurrentBalance;
use App\Services\LedgerJournal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('local and export sales post a balanced journal to sales and customer accounts', function () {
    $scope = seedSellScope();
    $exportCustomer = createExportCustomer($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sells', validSellPayload($scope, [
        'final_amount' => 280,
    ]))->assertSuccessful();

    $this->postJson('/api/sells', validSellPayload($scope, [
        'contact_id' => $exportCustomer->id,
        'final_amount' => 500,
        'shipping_charges' => 0,
    ]))->assertSuccessful();

    $localSell = Transaction::query()->sells()->where('contact_id', $scope['customer_id'])->first();
    $exportSell = Transaction::query()->sells()->where('contact_id', $exportCustomer->id)->first();

    expect($localSell)->not->toBeNull()
        ->and($exportSell)->not->toBeNull();

    assertSellJournal($localSell, $scope['local_sales_code'], $scope['customer_code'], 280);
    assertSellJournal($exportSell, $scope['export_sales_code'], '101-00002', 500);

    $nets = app(AccountCurrentBalance::class)->activityNets(
        (int) $scope['company_id'],
        (int) $scope['branch_id'],
        [
            $scope['local_sales_code'],
            $scope['export_sales_code'],
            $scope['customer_code'],
            '101-00002',
        ],
        '2026-07-01',
        '2027-07-01',
    );

    expect($nets[$scope['local_sales_code']])->toBe(280.0)
        ->and($nets[$scope['export_sales_code']])->toBe(500.0)
        ->and($nets[$scope['customer_code']])->toBe(280.0)
        ->and($nets['101-00002'])->toBe(500.0);
});

test('a sale with tax posts a third balanced output-tax leg', function () {
    $scope = seedSellScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sells', validSellPayload($scope, [
        'final_amount' => 308,
        'tax_amount' => 28,
    ]))->assertSuccessful();

    $sell = Transaction::query()->sells()->where('contact_id', $scope['customer_id'])->firstOrFail();
    $journal = TAccount::query()->where('transaction_id', $sell->id)->firstOrFail();
    $lines = TAccountDetail::query()->where('t_account_id', $journal->id)->get();

    expect($lines)->toHaveCount(3)
        ->and((float) $lines->sum('debit'))->toBe((float) $lines->sum('credit'))
        ->and((float) $lines->firstWhere('account_code', $scope['customer_code'])?->debit)->toBe(308.0)
        ->and((float) $lines->firstWhere('account_code', $scope['local_sales_code'])?->credit)->toBe(280.0)
        ->and((float) $lines->firstWhere('account_code', $scope['output_tax_code'])?->credit)->toBe(28.0);
});

test('journals sync-documents backfills an existing sale without a journal', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, [
        'invoice_no' => 'INV-OLD-01',
        'final_amount' => 5000,
        'status' => 'final',
        'transaction_date' => '2026-08-15',
    ]);

    expect(TAccount::query()->where('transaction_id', $sell->id)->exists())->toBeFalse();

    $this->artisan('journals:sync-documents', ['--type' => 'sell'])
        ->assertSuccessful();

    assertSellJournal($sell->fresh(), $scope['local_sales_code'], $scope['customer_code'], 5000);
});

test('a zero-amount sale is rejected before posting a journal', function () {
    $scope = seedSellScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sells', validSellPayload($scope, [
        'final_amount' => 0,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['final_amount']);

    expect(Transaction::query()->sells()->where('contact_id', $scope['customer_id'])->exists())->toBeFalse()
        ->and(TAccount::query()->where('company_id', $scope['company_id'])->exists())->toBeFalse();
});

test('the ledger rejects a negative journal line before posting', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, ['final_amount' => 100]);
    $customerAccount = ChartOfAccount::query()->where('code', $scope['customer_code'])->firstOrFail();

    expect(function () use ($sell, $customerAccount, $scope) {
        DB::transaction(function () use ($sell, $customerAccount, $scope) {
            app(LedgerJournal::class)->post(
                $sell,
                $customerAccount,
                'Sell against '.$sell->invoice_no,
                'SE',
                [
                    [
                        'account' => $customerAccount,
                        'debit' => -80.0,
                        'credit' => 0.0,
                        'contact_id' => $scope['customer_id'],
                    ],
                ],
                -80.0,
                0.0,
            );
        });
    })->toThrow(ValidationException::class, 'Journal lines cannot have a negative debit or credit amount.');

    expect(TAccount::query()->where('transaction_id', $sell->id)->exists())->toBeFalse();
});

test('sequential sales receive distinct voucher numbers and the unique constraint rejects a duplicate', function () {
    $scope = seedSellScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sells', validSellPayload($scope, ['final_amount' => 280]))->assertSuccessful();
    $this->postJson('/api/sells', validSellPayload($scope, ['final_amount' => 320]))->assertSuccessful();

    $vouchers = TAccount::query()
        ->where('company_id', $scope['company_id'])
        ->where('branch_id', $scope['branch_id'])
        ->orderBy('id')
        ->pluck('voucher_no');

    expect($vouchers->all())->toBe(['SE-00001', 'SE-00002'])
        ->and($vouchers->unique()->count())->toBe($vouchers->count());

    expect(fn () => TAccount::factory()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_no' => 'SE-00001',
    ]))->toThrow(QueryException::class);
});

test('nextVoucherNo skips numbers already in use and scopes independently per branch', function () {
    $scope = seedSellScope();

    TAccount::factory()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_no' => 'SE-00001',
    ]);

    $ledger = app(LedgerJournal::class);

    expect($ledger->nextVoucherNo((int) $scope['company_id'], (int) $scope['branch_id'], 'SE'))
        ->toBe('SE-00002');

    $otherBranchId = DB::table('branches')->insertGetId([
        'code' => 'PURB002',
        'company_id' => $scope['company_id'],
        'name' => 'Second Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($ledger->nextVoucherNo((int) $scope['company_id'], $otherBranchId, 'SE'))
        ->toBe('SE-00001');
});

function assertSellJournal(Transaction $sell, string $salesCode, string $customerCode, float $amount): void
{
    $journal = TAccount::query()->where('transaction_id', $sell->id)->first();

    expect($journal)->not->toBeNull()
        ->and($journal->status)->toBe('approved')
        ->and($journal->ref_no)->toBe($sell->invoice_no);

    $lines = TAccountDetail::query()->where('t_account_id', $journal->id)->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('t_account_id')->unique()->all())->toBe([$journal->id])
        ->and((float) $lines->sum('debit'))->toBe((float) $lines->sum('credit'))
        ->and((float) $lines->sum('debit'))->toBe($amount);

    $salesLine = $lines->firstWhere('account_code', $salesCode);
    $customerLine = $lines->firstWhere('account_code', $customerCode);

    expect($salesLine)->not->toBeNull()
        ->and((float) $salesLine->credit)->toBe($amount)
        ->and((float) $salesLine->debit)->toBe(0.0)
        ->and($customerLine)->not->toBeNull()
        ->and((float) $customerLine->debit)->toBe($amount)
        ->and((float) $customerLine->credit)->toBe(0.0);
}
