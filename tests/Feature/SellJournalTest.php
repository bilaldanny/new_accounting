<?php

use App\Models\TAccount;
use App\Models\TAccountDetail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountCurrentBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
