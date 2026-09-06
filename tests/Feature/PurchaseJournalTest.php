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

test('local and export purchases post a balanced journal to purchase and supplier accounts', function () {
    $scope = seedPurchaseScope();
    $exportSupplier = createExportSupplier($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchases', validPurchasePayload($scope, [
        'final_amount' => 250,
    ]))->assertSuccessful();

    $this->postJson('/api/purchases', validPurchasePayload($scope, [
        'contact_id' => $exportSupplier->id,
        'final_amount' => 400,
        'shipping_charges' => 0,
    ]))->assertSuccessful();

    $localPurchase = Transaction::query()
        ->purchases()
        ->where('contact_id', $scope['contact_id'])
        ->first();
    $exportPurchase = Transaction::query()
        ->purchases()
        ->where('contact_id', $exportSupplier->id)
        ->first();

    expect($localPurchase)->not->toBeNull()
        ->and($exportPurchase)->not->toBeNull();

    assertPurchaseJournal(
        purchase: $localPurchase,
        purchaseCode: $scope['local_purchase_code'],
        supplierCode: $scope['supplier_code'],
        amount: 250,
    );

    assertPurchaseJournal(
        purchase: $exportPurchase,
        purchaseCode: $scope['export_purchase_code'],
        supplierCode: '311-00002',
        amount: 400,
    );

    $nets = app(AccountCurrentBalance::class)->activityNets(
        (int) $scope['company_id'],
        (int) $scope['branch_id'],
        [
            $scope['local_purchase_code'],
            $scope['export_purchase_code'],
            $scope['supplier_code'],
            '311-00002',
        ],
        '2026-07-01',
        '2027-07-01',
    );

    expect($nets[$scope['local_purchase_code']])->toBe(250.0)
        ->and($nets[$scope['export_purchase_code']])->toBe(400.0)
        ->and($nets[$scope['supplier_code']])->toBe(250.0)
        ->and($nets['311-00002'])->toBe(400.0);
});

test('updating a purchase rewrites both journal legs and deleting removes them', function () {
    $scope = seedPurchaseScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchases', validPurchasePayload($scope, [
        'final_amount' => 250,
    ]))->assertSuccessful();

    $purchase = Transaction::query()->purchases()->where('contact_id', $scope['contact_id'])->firstOrFail();

    $this->putJson('/api/purchases/'.$purchase->id, validPurchasePayload($scope, [
        'invoice_no' => $purchase->invoice_no,
        'final_amount' => 310,
        'shipping_charges' => 10,
    ]))->assertSuccessful();

    $purchase->refresh();

    expect(TAccount::query()->where('transaction_id', $purchase->id)->count())->toBe(1);

    assertPurchaseJournal(
        purchase: $purchase,
        purchaseCode: $scope['local_purchase_code'],
        supplierCode: $scope['supplier_code'],
        amount: 310,
    );

    $this->postJson('/api/purchases/bulk_delete', [$purchase->id])->assertSuccessful();

    expect(TAccount::query()->where('transaction_id', $purchase->id)->exists())->toBeFalse()
        ->and(TAccountDetail::query()->where('account_code', $scope['local_purchase_code'])->exists())->toBeFalse()
        ->and(TAccountDetail::query()->where('account_code', $scope['supplier_code'])->exists())->toBeFalse();
});

test('a purchase with tax posts a third balanced input-tax leg', function () {
    $scope = seedPurchaseScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchases', validPurchasePayload($scope, [
        'final_amount' => 275,
        'tax_amount' => 25,
    ]))->assertSuccessful();

    $purchase = Transaction::query()->purchases()->where('contact_id', $scope['contact_id'])->firstOrFail();
    $journal = TAccount::query()->where('transaction_id', $purchase->id)->firstOrFail();
    $lines = TAccountDetail::query()->where('t_account_id', $journal->id)->get();

    expect($lines)->toHaveCount(3)
        ->and((float) $lines->sum('debit'))->toBe((float) $lines->sum('credit'))
        ->and((float) $lines->firstWhere('account_code', $scope['local_purchase_code'])?->debit)->toBe(250.0)
        ->and((float) $lines->firstWhere('account_code', $scope['input_tax_code'])?->debit)->toBe(25.0)
        ->and((float) $lines->firstWhere('account_code', $scope['supplier_code'])?->credit)->toBe(275.0);
});

test('journals sync-documents backfills an existing purchase without a journal', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, [
        'invoice_no' => 'PO-OLD-01',
        'final_amount' => 23000,
        'status' => 'approved',
        'transaction_date' => '2026-08-23',
    ]);

    expect(TAccount::query()->where('transaction_id', $purchase->id)->exists())->toBeFalse();

    $this->artisan('journals:sync-documents', ['--type' => 'purchase'])
        ->assertSuccessful();

    assertPurchaseJournal(
        purchase: $purchase->fresh(),
        purchaseCode: $scope['local_purchase_code'],
        supplierCode: $scope['supplier_code'],
        amount: 23000,
    );
});

test('a zero-amount purchase is rejected before posting a journal', function () {
    $scope = seedPurchaseScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchases', validPurchasePayload($scope, [
        'final_amount' => 0,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['final_amount']);

    expect(Transaction::query()->purchases()->where('contact_id', $scope['contact_id'])->exists())->toBeFalse()
        ->and(TAccount::query()->where('company_id', $scope['company_id'])->exists())->toBeFalse();
});

test('the ledger rejects a negative journal line before posting', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100]);
    $purchaseAccount = ChartOfAccount::query()->where('code', $scope['local_purchase_code'])->firstOrFail();

    expect(function () use ($purchase, $purchaseAccount, $scope) {
        DB::transaction(function () use ($purchase, $purchaseAccount, $scope) {
            app(LedgerJournal::class)->post(
                $purchase,
                $purchaseAccount,
                'Purchase against '.$purchase->invoice_no,
                'PE',
                [
                    [
                        'account' => $purchaseAccount,
                        'debit' => -50.0,
                        'credit' => 0.0,
                        'contact_id' => $scope['contact_id'],
                    ],
                ],
                -50.0,
                0.0,
            );
        });
    })->toThrow(ValidationException::class, 'Journal lines cannot have a negative debit or credit amount.');

    expect(TAccount::query()->where('transaction_id', $purchase->id)->exists())->toBeFalse();
});

test('sequential purchases receive distinct voucher numbers and the unique constraint rejects a duplicate', function () {
    $scope = seedPurchaseScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchases', validPurchasePayload($scope, ['final_amount' => 100]))->assertSuccessful();
    $this->postJson('/api/purchases', validPurchasePayload($scope, ['final_amount' => 150]))->assertSuccessful();

    $vouchers = TAccount::query()
        ->where('company_id', $scope['company_id'])
        ->where('branch_id', $scope['branch_id'])
        ->orderBy('id')
        ->pluck('voucher_no');

    expect($vouchers->all())->toBe(['PE-00001', 'PE-00002'])
        ->and($vouchers->unique()->count())->toBe($vouchers->count());

    expect(fn () => TAccount::factory()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_no' => 'PE-00001',
    ]))->toThrow(QueryException::class);
});

test('nextVoucherNo skips numbers already in use and scopes independently per branch', function () {
    $scope = seedPurchaseScope();

    TAccount::factory()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_no' => 'PE-00001',
    ]);

    $ledger = app(LedgerJournal::class);

    expect($ledger->nextVoucherNo((int) $scope['company_id'], (int) $scope['branch_id'], 'PE'))
        ->toBe('PE-00002');

    $otherBranchId = DB::table('branches')->insertGetId([
        'code' => 'PURB002',
        'company_id' => $scope['company_id'],
        'name' => 'Second Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($ledger->nextVoucherNo((int) $scope['company_id'], $otherBranchId, 'PE'))
        ->toBe('PE-00001');
});

function assertPurchaseJournal(Transaction $purchase, string $purchaseCode, string $supplierCode, float $amount): void
{
    $journal = TAccount::query()->where('transaction_id', $purchase->id)->first();

    expect($journal)->not->toBeNull()
        ->and($journal->status)->toBe('approved')
        ->and($journal->ref_no)->toBe($purchase->invoice_no);

    $lines = TAccountDetail::query()
        ->where('t_account_id', $journal->id)
        ->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('t_account_id')->unique()->all())->toBe([$journal->id])
        ->and((float) $lines->sum('debit'))->toBe((float) $lines->sum('credit'))
        ->and((float) $lines->sum('debit'))->toBe($amount);

    $purchaseLine = $lines->firstWhere('account_code', $purchaseCode);
    $supplierLine = $lines->firstWhere('account_code', $supplierCode);

    expect($purchaseLine)->not->toBeNull()
        ->and((float) $purchaseLine->debit)->toBe($amount)
        ->and((float) $purchaseLine->credit)->toBe(0.0)
        ->and($supplierLine)->not->toBeNull()
        ->and((float) $supplierLine->credit)->toBe($amount)
        ->and((float) $supplierLine->debit)->toBe(0.0);
}
