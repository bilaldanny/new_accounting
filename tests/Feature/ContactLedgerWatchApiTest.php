<?php

use App\Models\ContactLedgerWatch;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('fetch ledger includes a null watched until checkpoint by default', function () {
    $linked = createLinkedLedgerContact('supplier');
    seedLedgerOpeningBalance($linked['scope'], $linked['coa_id'], 10000);

    $transaction = Transaction::factory()->create([
        'company_id' => $linked['scope']['company_id'],
        'branch_id' => $linked['scope']['branch_id'],
        'contact_id' => $linked['contact']->id,
        'type' => Transaction::TYPE_PURCHASE,
        'invoice_no' => 'PO-00001',
        'transaction_date' => '2026-08-23',
        'final_amount' => 23000,
        'status' => 'approved',
    ]);

    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/fetchledger?contact_id='.$linked['contact']->id.'&start_date=2026-07-01&end_date=2027-07-01')
        ->assertOk()
        ->assertJsonPath('watched_until', null)
        ->assertJsonPath('taccount.0.id', 'txn-'.$transaction->id);
});

test('user can mark the ledger as reviewed through a transaction', function () {
    $linked = createLinkedLedgerContact('supplier');
    seedLedgerOpeningBalance($linked['scope'], $linked['coa_id'], 10000);

    $transaction = Transaction::factory()->create([
        'company_id' => $linked['scope']['company_id'],
        'branch_id' => $linked['scope']['branch_id'],
        'contact_id' => $linked['contact']->id,
        'type' => Transaction::TYPE_PURCHASE,
        'invoice_no' => 'PO-00001',
        'transaction_date' => '2026-08-23',
        'final_amount' => 23000,
        'status' => 'approved',
    ]);

    $user = User::query()->findOrFail(1);
    Sanctum::actingAs($user);

    $this->postJson('/api/contact-ledger-watches', [
        'contact_id' => $linked['contact']->id,
        'row_id' => 'txn-'.$transaction->id,
        'voucher_date' => '2026-08-23',
        'voucher_no' => 'PO-00001',
    ])->assertOk()
        ->assertJsonPath('watched_until.row_id', 'txn-'.$transaction->id)
        ->assertJsonPath('watched_until.voucher_date', '2026-08-23')
        ->assertJsonPath('watched_until.voucher_no', 'PO-00001');

    $this->getJson('/api/fetchledger?contact_id='.$linked['contact']->id.'&start_date=2026-07-01&end_date=2027-07-01')
        ->assertOk()
        ->assertJsonPath('watched_until.row_id', 'txn-'.$transaction->id);

    expect(ContactLedgerWatch::query()->where('user_id', $user->id)->where('contact_id', $linked['contact']->id)->exists())->toBeTrue();
});

test('user can clear the ledger reviewed checkpoint', function () {
    $linked = createLinkedLedgerContact('supplier');
    $user = User::query()->findOrFail(1);

    ContactLedgerWatch::query()->create([
        'user_id' => $user->id,
        'contact_id' => $linked['contact']->id,
        'last_row_id' => 'txn-99',
        'last_voucher_date' => '2026-08-23',
        'last_voucher_no' => 'PO-00001',
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/contact-ledger-watches', [
        'contact_id' => $linked['contact']->id,
        'row_id' => null,
        'voucher_date' => null,
        'voucher_no' => null,
    ])->assertOk()
        ->assertJsonPath('watched_until', null);

    expect(ContactLedgerWatch::query()->where('user_id', $user->id)->where('contact_id', $linked['contact']->id)->exists())->toBeFalse();
});

test('ledger watch cannot be saved for a contact the user cannot see', function () {
    $linked = createLinkedLedgerContact('supplier');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/contact-ledger-watches', [
        'contact_id' => 999999,
        'row_id' => 'txn-1',
        'voucher_date' => '2026-08-23',
        'voucher_no' => 'PO-00001',
    ])->assertNotFound();

    expect($linked['contact']->id)->toBeGreaterThan(0);
});
