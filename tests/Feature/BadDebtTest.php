<?php

use App\Models\Payment;
use App\Services\Reports\PartyAgingReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Task C, item 5: bad debt by direct write-off (requested, approved by someone else, posted, reversible) and by allowance
 * (period-end provision by aging bucket), matched to the customer ledger and the aging.
 */
const BD_REQUEST = ['/baddebt', '/baddebt/add', '/baddebt/reverse'];
const BD_APPROVE = ['/baddebt', '/baddebt/approve'];
const BD_ADMIN = ['/baddebt', '/baddebt/settings', '/baddebt/provision'];

function bdAgedTotal(array $scope): float
{
    $aging = app(PartyAgingReport::class);

    return (float) $aging->summary($aging->rows('customer', $scope['company_id'], null, []))['total'];
}

function bdSettings(array $scope, array $rates = []): void
{
    test()->putJson('/api/bad-debt/settings', [
        'expense_account_id' => $scope['baddebt_id'], 'allowance_account_id' => $scope['allowance_id'],
        'rates' => $rates + ['not_due' => 0, 'days_0_30' => 1, 'days_31_60' => 5, 'days_61_90' => 20, 'days_90_plus' => 50],
    ])->assertOk();
}

test('a direct write-off is requested, approved by another person, reduces the invoice and the aging, and can be reversed', function () {
    $scope = fcoScope();
    $invoice = createSellRecord($scope, ['final_amount' => 1000, 'invoice_no' => 'INV-BD', 'transaction_date' => '2026-06-01']);
    $requester = fcoStaff($scope, [...BD_REQUEST, '/baddebt/settings']);
    $approver = fcoStaff($scope, BD_APPROVE);
    expect(bdAgedTotal($scope))->toBe(1000.0);

    Sanctum::actingAs($requester);
    $this->postJson('/api/bad-debt/writeoffs', ['transaction_id' => $invoice->id, 'amount' => 400, 'written_off_on' => '2026-10-10', 'method' => 'direct', 'reason' => 'Customer closed'])->assertUnprocessable();
    bdSettings($scope);

    $id = $this->postJson('/api/bad-debt/writeoffs', ['transaction_id' => $invoice->id, 'amount' => 400, 'written_off_on' => '2026-10-10', 'method' => 'direct', 'reason' => 'Customer closed'])->assertCreated()->json('data.id');

    // nothing moves while it is pending, and the requester cannot approve their own
    expect(bdAgedTotal($scope))->toBe(1000.0)->and(Payment::query()->count())->toBe(0);
    $this->postJson("/api/bad-debt/writeoffs/{$id}/approve")->assertForbidden();

    Sanctum::actingAs($approver);
    $this->postJson("/api/bad-debt/writeoffs/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'posted');

    $payment = Payment::query()->where('transaction_id', $invoice->id)->firstOrFail();
    $lines = DB::table('t_account_details')->where('t_account_id', $payment->t_account_id)->get();
    expect((float) $lines->firstWhere('coa_id', $scope['baddebt_id'])->debit)->toBe(400.0)
        ->and((float) $lines->firstWhere('coa_id', $scope['customer_coa_id'])->credit)->toBe(400.0)
        ->and(bdAgedTotal($scope))->toBe(600.0)
        ->and($invoice->fresh()->payment_status)->toBe('partial')
        ->and(fcoTrialBalance($scope['company_id']))->toBe(['debit' => 400.0, 'credit' => 400.0]);

    // more than what is left is refused
    Sanctum::actingAs($requester);
    $this->postJson('/api/bad-debt/writeoffs', ['transaction_id' => $invoice->id, 'amount' => 700, 'written_off_on' => '2026-10-10', 'method' => 'direct', 'reason' => 'Too much'])->assertUnprocessable();

    $this->postJson("/api/bad-debt/writeoffs/{$id}/reverse")->assertOk()->assertJsonPath('data.status', 'reversed');
    expect(bdAgedTotal($scope))->toBe(1000.0)->and(Payment::query()->count())->toBe(0)->and(fcoBalance($scope['baddebt_id']))->toBe(0.0);
});

test('the allowance is set from the aging buckets once a day and releases when the debt falls', function () {
    $scope = fcoScope();
    createSellRecord($scope, ['final_amount' => 1000, 'invoice_no' => 'INV-OLD', 'transaction_date' => '2026-06-01']);
    createSellRecord($scope, ['final_amount' => 500, 'invoice_no' => 'INV-NEW', 'transaction_date' => '2026-10-01']);
    Sanctum::actingAs(fcoStaff($scope, [...BD_ADMIN, ...BD_REQUEST]));
    $vouchers = DB::table('t_accounts')->count();

    // not configured: it says so and posts nothing
    $this->postJson('/api/bad-debt/provision', ['as_of' => '2026-10-12'])->assertUnprocessable();
    bdSettings($scope);

    $preview = $this->getJson('/api/bad-debt/provision?as_of=2026-10-12')->assertOk()->json('data');
    // the old invoice is 90+ days past due (50%), the new one is 11 days past due (1%)
    expect($preview['required'])->toEqual(505)->and($preview['existing'])->toEqual(0)->and(DB::table('t_accounts')->count())->toBe($vouchers);

    $this->postJson('/api/bad-debt/provision', ['as_of' => '2026-10-12'])->assertCreated();
    expect(fcoBalance($scope['allowance_id']))->toBe(-505.0)->and(fcoBalance($scope['baddebt_id']))->toBe(505.0)
        ->and(fcoTrialBalance($scope['company_id']))->toBe(['debit' => 505.0, 'credit' => 505.0]);

    // once a day only
    $this->postJson('/api/bad-debt/provision', ['as_of' => '2026-10-12'])->assertUnprocessable();

    // the old invoice is paid: the allowance is released at the next provisioning
    $sale = DB::table('transactions')->where('invoice_no', 'INV-OLD')->first();
    DB::table('payments')->insert(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'transaction_id' => $sale->id, 'amount' => 1000, 'method' => 'cash', 'paid_on' => '2026-10-13', 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson('/api/bad-debt/provision', ['as_of' => '2026-10-14'])->assertCreated();
    expect(fcoBalance($scope['allowance_id']))->toBe(-5.0);
});

test('a write-off by the allowance method debits the allowance, and a locked period or another company invoice is refused', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $invoice = createSellRecord($scope, ['final_amount' => 300, 'invoice_no' => 'INV-ALW', 'transaction_date' => '2026-06-01']);
    $foreign = createSellRecord(seedSellScope2Safe($other), ['final_amount' => 50, 'invoice_no' => 'INV-FOREIGN', 'transaction_date' => '2026-06-01']);
    $approver = fcoStaff($scope, BD_APPROVE);
    Sanctum::actingAs(fcoStaff($scope, [...BD_REQUEST, ...BD_ADMIN]));
    bdSettings($scope);

    $this->postJson('/api/bad-debt/writeoffs', ['transaction_id' => $foreign->id, 'amount' => 10, 'written_off_on' => '2026-10-10', 'method' => 'direct', 'reason' => 'Not ours'])->assertUnprocessable();

    $locked = $this->postJson('/api/bad-debt/writeoffs', ['transaction_id' => $invoice->id, 'amount' => 100, 'written_off_on' => '2026-09-10', 'method' => 'allowance', 'reason' => 'Disputed'])->assertCreated()->json('data.id');
    fcoLockThrough($scope['company_id'], '2026-09-30');
    Sanctum::actingAs($approver);
    $this->postJson("/api/bad-debt/writeoffs/{$locked}/approve")->assertStatus(422);
    expect(Payment::query()->count())->toBe(0)
        ->and(DB::table('bad_debt_writeoffs')->where('id', $locked)->value('status'))->toBe('pending');

    Sanctum::actingAs(fcoStaff($scope, BD_REQUEST));
    $ok = $this->postJson('/api/bad-debt/writeoffs', ['transaction_id' => $invoice->id, 'amount' => 100, 'written_off_on' => '2026-10-10', 'method' => 'allowance', 'reason' => 'Disputed'])->assertCreated()->json('data.id');
    Sanctum::actingAs($approver);
    $this->postJson("/api/bad-debt/writeoffs/{$ok}/approve")->assertOk();

    expect(fcoBalance($scope['allowance_id']))->toBe(100.0)->and(fcoBalance($scope['baddebt_id']))->toBe(0.0);
});

/**
 * A scope for createSellRecord in another company: a customer and the product rows the helper needs.
 *
 * @param  array{company_id: int, branch_id: int}  $company
 * @return array<string, mixed>
 */
function seedSellScope2Safe(array $company): array
{
    $scope = trpScope('9');
    $product = prdProduct($scope, 'Foreign Product');

    return [
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['customer_id'],
        'product_id' => $product['product_id'], 'variation_id' => $product['variation_id'], 'unit_id' => $product['unit_id'], 'itemtype_id' => null,
    ];
}
