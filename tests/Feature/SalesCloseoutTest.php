<?php

use App\Models\ChequeRecord;
use App\Models\Payment;
use App\Models\RecurringInvoice;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\TAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringInvoices;
use App\Services\StockMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Sales & POS closeout (wave 4): quotation expiry and conversion, sales orders, store credit and refunds, sale import, cashiers,
 * cash rounding, dispatch, commission and targets, routes, recurring invoices, installments, cheques, receipts.
 */
function scoStaff(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'sco_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
}

/**
 * @return array<string, mixed>
 */
function scoScope(): array
{
    $scope = seedSellScope();
    $cashId = insertPurchaseChartAccount($scope, '111-00001', 'Cash in Hand', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cashId);
    $scope['payment_account_id'] = $cashId;
    DB::table('company_settings')->updateOrInsert(['company_id' => $scope['company_id']], ['company_id' => $scope['company_id'], 'created_at' => now(), 'updated_at' => now()]);

    return $scope;
}

/**
 * @return array<string, mixed>
 */
function scoOtherCompany(): array
{
    $companyId = DB::table('companies')->insertGetId(['code' => 'SCO2', 'name' => 'Other Co', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branchId = DB::table('branches')->insertGetId(['code' => 'SCOB2', 'company_id' => $companyId, 'name' => 'Other Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    return ['company_id' => $companyId, 'branch_id' => $branchId];
}

function scoLine(array $scope, float $quantity = 2, float $price = 120): array
{
    return ['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'quantity' => $quantity, 'unit_price' => $price];
}

function scoLedgerCount(): int
{
    return TAccount::query()->count();
}

// ---------------------------------------------------------------------------------------------------------------------
// Quotations

test('an expired quotation cannot be converted until it is given a new date, then converts once to a final invoice', function () {
    $scope = scoScope();
    $quote = createSellRecord($scope, ['status' => 'quotation', 'invoice_no' => 'QT-1']);
    Sanctum::actingAs(scoStaff($scope, ['/quotation', '/quotation/:id/edit', '/quotation/convert']));

    $this->putJson("/api/quotations/{$quote->id}", ['valid_until' => now()->subDay()->toDateString(), 'is_proforma' => true])->assertOk();
    $listed = $this->getJson('/api/quotations')->assertOk()->json('data.data.0');
    expect($listed['state'])->toBe('expired')->and($listed['is_proforma'])->toBeTrue();

    $this->postJson("/api/quotations/{$quote->id}/convert", ['to' => 'invoice'])->assertUnprocessable();

    $this->putJson("/api/quotations/{$quote->id}", ['valid_until' => now()->addWeek()->toDateString()])->assertOk();
    $done = $this->postJson("/api/quotations/{$quote->id}/convert", ['to' => 'invoice'])->assertCreated();

    $invoice = Transaction::query()->findOrFail($done->json('data.id'));
    expect($invoice->status)->toBe('final')->and($invoice->id)->not->toBe($quote->id)
        ->and($quote->fresh()->status)->toBe('quotation');

    $this->postJson("/api/quotations/{$quote->id}/convert", ['to' => 'invoice'])->assertUnprocessable();
});

test('a quotation of another company is not found', function () {
    $scope = scoScope();
    $quote = createSellRecord($scope, ['status' => 'quotation', 'invoice_no' => 'QT-2']);
    $other = scoOtherCompany();
    Sanctum::actingAs(scoStaff($other, ['/quotation', '/quotation/:id/edit', '/quotation/convert']));

    $this->putJson("/api/quotations/{$quote->id}", ['is_proforma' => true])->assertNotFound();
    $this->postJson("/api/quotations/{$quote->id}/convert", ['to' => 'order'])->assertNotFound();
    $this->getJson('/api/quotations')->assertOk()->assertJsonCount(0, 'data.data');
});

// ---------------------------------------------------------------------------------------------------------------------
// Sales orders

test('a sales order posts nothing, is approved by someone else, and invoices in two steps', function () {
    $scope = scoScope();
    $ledger = scoLedgerCount();
    $stock = StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']);

    Sanctum::actingAs(scoStaff($scope, ['/salesorder', '/salesorder/add', '/salesorder/:id/edit', '/salesorder/invoice']));
    $created = $this->postJson('/api/sales-orders', [
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'],
        'order_date' => '2026-10-01', 'lines' => [scoLine($scope, 5)],
    ])->assertCreated();
    $orderId = $created->json('data.id');

    expect($created->json('data.status'))->toBe('pending_approval')
        ->and(scoLedgerCount())->toBe($ledger)
        ->and(StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']))->toBe($stock);

    // not confirmed yet: no invoice
    $this->postJson("/api/sales-orders/{$orderId}/invoice")->assertUnprocessable();
    $this->postJson("/api/sales-orders/{$orderId}/approve")->assertForbidden();

    Sanctum::actingAs(scoStaff($scope, ['/salesorder', '/salesorder/approve']));
    $this->postJson("/api/sales-orders/{$orderId}/approve")->assertOk();

    $line = SalesOrder::query()->findOrFail($orderId)->lines()->firstOrFail();
    Sanctum::actingAs(scoStaff($scope, ['/salesorder', '/salesorder/invoice']));
    $this->postJson("/api/sales-orders/{$orderId}/invoice", ['status' => 'draft', 'lines' => [['line_id' => $line->id, 'quantity' => 9]]])->assertUnprocessable();
    $first = $this->postJson("/api/sales-orders/{$orderId}/invoice", ['status' => 'draft', 'lines' => [['line_id' => $line->id, 'quantity' => 2]]])->assertCreated();
    expect(SalesOrder::query()->findOrFail($orderId)->status)->toBe('partially_invoiced')->and($first->json('invoice.status'))->toBe('draft');

    $this->postJson("/api/sales-orders/{$orderId}/invoice", ['status' => 'draft'])->assertCreated();
    expect(SalesOrder::query()->findOrFail($orderId)->status)->toBe('invoiced');
    $this->postJson("/api/sales-orders/{$orderId}/invoice")->assertUnprocessable();
});

test('sales orders are company scoped', function () {
    $scope = scoScope();
    $other = scoOtherCompany();
    $order = SalesOrder::query()->create(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'contact_id' => $scope['contact_id'], 'order_no' => 'SO-X', 'order_date' => '2026-10-01', 'status' => 'confirmed', 'total' => 0]);
    Sanctum::actingAs(scoStaff($scope, ['/salesorder', '/salesorder/approve', '/salesorder/invoice']));

    $this->getJson('/api/sales-orders')->assertOk()->assertJsonCount(0, 'data.data');
    $this->getJson("/api/sales-orders/{$order->id}")->assertNotFound();
    $this->postJson("/api/sales-orders/{$order->id}/invoice")->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// Store credit and refunds

test('store credit is issued from a return, refunded in cash with a balanced voucher, and never over the balance', function () {
    $scope = scoScope();
    $return = createSellRecord($scope, ['type' => Transaction::TYPE_SELL_RETURN, 'invoice_no' => 'SR-1', 'final_amount' => 100, 'status' => 'final']);
    Sanctum::actingAs(scoStaff($scope, ['/storecredit', '/storecredit/add', '/storecredit/refund', '/storecredit/apply']));

    $this->postJson('/api/store-credit/issue', ['sell_return_id' => $return->id, 'amount' => 150])->assertUnprocessable();
    $this->postJson('/api/store-credit/issue', ['sell_return_id' => $return->id, 'amount' => 100])->assertCreated()->assertJsonPath('balance', 100);

    $this->postJson('/api/store-credit/refund', [
        'contact_id' => $scope['contact_id'], 'amount' => 150, 'paid_on' => '2026-10-02', 'payment_account' => $scope['payment_account_id'], 'method' => 'cash',
    ])->assertUnprocessable();

    $before = scoLedgerCount();
    $this->postJson('/api/store-credit/refund', [
        'contact_id' => $scope['contact_id'], 'amount' => 40, 'paid_on' => '2026-10-02', 'payment_account' => $scope['payment_account_id'], 'method' => 'cash',
    ])->assertCreated()->assertJsonPath('balance', 60);

    $payment = Payment::query()->where('contact_id', $scope['contact_id'])->where('is_return', true)->firstOrFail();
    $lines = DB::table('t_account_details')->where('t_account_id', $payment->t_account_id)->get();
    expect(scoLedgerCount())->toBeGreaterThan($before)
        ->and((float) $payment->amount)->toBe(40.0)
        ->and((float) $lines->sum('debit'))->toBe(40.0)
        ->and((float) $lines->sum('credit'))->toBe(40.0)
        ->and((float) $lines->firstWhere('coa_id', $scope['customer_coa_id'])->debit)->toBe(40.0)
        ->and((float) $lines->firstWhere('coa_id', $scope['payment_account_id'])->credit)->toBe(40.0);
});

test('store credit of another company is invisible', function () {
    $scope = scoScope();
    $other = scoOtherCompany();
    Sanctum::actingAs(scoStaff($other, ['/storecredit', '/storecredit/refund']));

    $this->getJson('/api/store-credit?contact_id='.$scope['contact_id'])->assertUnprocessable();
    $this->postJson('/api/store-credit/refund', ['contact_id' => $scope['contact_id'], 'amount' => 1, 'paid_on' => '2026-10-02', 'payment_account' => 1, 'method' => 'cash'])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// Sale import

test('a sale import checks first, is all or nothing, and skips an invoice reference imported before', function () {
    $scope = scoScope();
    $sku = DB::table('products')->where('id', $scope['product_id'])->value('sku').'-1';
    Sanctum::actingAs(scoStaff($scope, ['/sell/import']));
    $good = ['invoice_ref' => 'IMP-1', 'date' => '2026-10-03', 'customer_code' => 'CU-00001', 'sku' => $sku, 'quantity' => 2, 'unit_price' => 100];
    $count = Transaction::query()->count();

    $dry = $this->postJson('/api/sells/import', ['rows' => [$good], 'dry_run' => true, 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']])->assertOk();
    expect($dry->json('summary.created'))->toBe(1)->and(Transaction::query()->count())->toBe($count);

    $bad = $this->postJson('/api/sells/import', ['rows' => [$good, ['invoice_ref' => 'IMP-2', 'date' => '2026-10-03', 'customer_code' => 'NOPE', 'sku' => $sku, 'quantity' => 1]], 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
    $bad->assertUnprocessable();
    expect(Transaction::query()->count())->toBe($count);

    $this->postJson('/api/sells/import', ['rows' => [$good], 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']])->assertOk();
    expect(Transaction::query()->count())->toBe($count + 1);

    $again = $this->postJson('/api/sells/import', ['rows' => [$good], 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']])->assertOk();
    expect($again->json('summary.duplicates'))->toBe(1)->and(Transaction::query()->count())->toBe($count + 1);
});

// ---------------------------------------------------------------------------------------------------------------------
// Cashiers

test('a cashier pin verifies, locks out after repeated failures, and a cashier of another company is not found', function () {
    $scope = scoScope();
    Sanctum::actingAs(scoStaff($scope, ['/cashier', '/cashier/add', '/sell/pos']));

    $this->postJson('/api/cashiers', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => 'Ali', 'code' => 'C1', 'pin' => '1234'])->assertCreated();
    $this->postJson('/api/cashiers', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => 'Dup', 'code' => 'C1', 'pin' => '1234'])->assertUnprocessable();

    $this->postJson('/api/cashiers/verify-pin', ['code' => 'C1', 'pin' => '1234'])->assertOk();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/cashiers/verify-pin', ['code' => 'C1', 'pin' => '9999']);
    }

    $this->postJson('/api/cashiers/verify-pin', ['code' => 'C1', 'pin' => '1234'])->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, 'locked'));

    $other = scoOtherCompany();
    Sanctum::actingAs(scoStaff($other, ['/cashier', '/sell/pos']));
    $this->getJson('/api/cashiers')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/cashiers/verify-pin', ['code' => 'C1', 'pin' => '1234'])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// Cash rounding

test('with no cash rounding rule a POS total is untouched; with a rule it is rounded and the difference is recorded', function () {
    $scope = scoScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $stock = createPurchaseRecord($scope, ['status' => 'received', 'invoice_no' => 'SCO-STOCK', 'final_amount' => 5000]);
    DB::table('purchase_lines')->where('transaction_id', $stock->id)->update(['quantity' => 50, 'quantity_received' => 50]);

    $payload = validSellPayload($scope, ['is_pos' => true, 'shipping_charges' => 0, 'final_amount' => 247, 'selllines' => [[
        'product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'itemtype_id' => $scope['itemtype_id'], 'unit_id' => $scope['unit_id'],
        'quantity' => 1, 'packing_qty' => 1, 'unit_price' => 247, 'discount_percent' => 0, 'unit_price_after_discount' => 247, 'row_subtotal' => 247,
    ]]]);

    $plain = $this->postJson('/api/sells', $payload)->assertSuccessful();
    $plainSale = Transaction::query()->findOrFail($plain->json('data.id') ?? $plain->json('id'));
    expect((float) $plainSale->final_amount)->toBe(247.0)->and((float) $plainSale->rounding_adjustment)->toBe(0.0);

    DB::table('company_settings')->where('company_id', $scope['company_id'])->update(['cash_rounding' => 'nearest:5']);
    $rounded = $this->postJson('/api/sells', $payload)->assertSuccessful();
    $roundedSale = Transaction::query()->findOrFail($rounded->json('data.id') ?? $rounded->json('id'));
    expect((float) $roundedSale->final_amount)->toBe(245.0)->and((float) $roundedSale->rounding_adjustment)->toBe(-2.0);

    // a sale that is not from the POS keeps its total even with a rule
    $other = $this->postJson('/api/sells', array_merge($payload, ['is_pos' => false]))->assertSuccessful();
    $otherSale = Transaction::query()->findOrFail($other->json('data.id') ?? $other->json('id'));
    expect((float) $otherSale->final_amount)->toBe(247.0);
});

// ---------------------------------------------------------------------------------------------------------------------
// Cheques

test('bouncing a received cheque removes its payment and makes the invoice due again; the flow cannot skip steps', function () {
    $scope = scoScope();
    $sale = createSellRecord($scope, ['final_amount' => 100]);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sell-payments', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $sale->contact_id, 'transaction_id' => $sale->id,
        'amount' => 100, 'paid_on' => '2026-10-04', 'method' => 'cheque', 'cheque_number' => '000123', 'payment_account' => $scope['payment_account_id']])->assertSuccessful();
    $payment = Payment::query()->where('transaction_id', $sale->id)->firstOrFail();
    expect($sale->fresh()->payment_status)->toBe('paid');

    Sanctum::actingAs(scoStaff($scope, ['/chequemanagement', '/chequemanagement/add', '/chequemanagement/:id/edit']));
    $made = $this->postJson('/api/cheque-records', [
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'direction' => 'received', 'cheque_no' => '000123', 'amount' => 100,
        'contact_id' => $scope['contact_id'], 'payment_id' => $payment->id, 'due_date' => '2026-10-10',
    ])->assertCreated();
    $id = $made->json('data.id');

    $this->postJson("/api/cheque-records/{$id}/status", ['status' => 'cleared'])->assertUnprocessable();
    $this->postJson("/api/cheque-records/{$id}/status", ['status' => 'deposited'])->assertOk();
    $this->postJson("/api/cheque-records/{$id}/status", ['status' => 'bounced', 'reason' => 'Insufficient funds'])->assertOk();

    expect(ChequeRecord::query()->findOrFail($id)->status)->toBe('bounced')
        ->and(Payment::query()->where('transaction_id', $sale->id)->count())->toBe(0)
        ->and($sale->fresh()->payment_status)->toBe('due');
});

// ---------------------------------------------------------------------------------------------------------------------
// Recurring invoices

test('a due recurring invoice makes one invoice per run, moves forward, and ends at its run limit', function () {
    $scope = scoScope();
    $schedule = RecurringInvoice::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'], 'name' => 'Monthly',
        'frequency' => 'monthly', 'interval' => 1, 'next_run_on' => '2026-09-01', 'max_runs' => 2, 'post_as' => 'draft', 'status' => 'active', 'runs' => 0,
        'lines' => [scoLine($scope, 1, 50)],
    ]);
    $count = Transaction::query()->count();

    $first = app(RecurringInvoices::class)->generateDue($scope['company_id'], Carbon::create(2026, 9, 2));
    expect($first['generated'])->toBe(1)->and(Transaction::query()->count())->toBe($count + 1)
        ->and($schedule->fresh()->next_run_on->toDateString())->toBe('2026-10-01');

    // not due yet: nothing is made
    expect(app(RecurringInvoices::class)->generateDue($scope['company_id'], Carbon::create(2026, 9, 15))['generated'])->toBe(0);

    app(RecurringInvoices::class)->generateDue($scope['company_id'], Carbon::create(2026, 10, 1));
    expect($schedule->fresh()->status)->toBe('ended')->and(Transaction::query()->count())->toBe($count + 2);
});

test('recurring invoices of another company are not generated by this company and need their permission', function () {
    $scope = scoScope();
    $other = scoOtherCompany();
    RecurringInvoice::query()->create([
        'company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'contact_id' => $scope['contact_id'], 'name' => 'Foreign',
        'frequency' => 'monthly', 'interval' => 1, 'next_run_on' => '2026-09-01', 'post_as' => 'draft', 'status' => 'active', 'runs' => 0, 'lines' => [scoLine($scope)],
    ]);
    Sanctum::actingAs(scoStaff($scope, ['/recurringinvoice']));

    $this->getJson('/api/recurring-invoices')->assertOk()->assertJsonCount(0, 'data.data');
    $this->postJson('/api/recurring-invoices/generate')->assertForbidden();
});

// ---------------------------------------------------------------------------------------------------------------------
// Installments, layaway and dispatch

test('a layaway sale is held from dispatch until its plan is paid up', function () {
    $scope = scoScope();
    $sale = createSellRecord($scope, ['final_amount' => 300]);
    Sanctum::actingAs(scoStaff($scope, ['/installment', '/installment/add', '/installment/pay', '/rider', '/rider/add', '/dispatch', '/dispatch/assign']));

    $plan = $this->postJson('/api/installment-plans', [
        'transaction_id' => $sale->id, 'kind' => 'layaway', 'down_payment' => 0, 'installments' => 3, 'frequency' => 'monthly', 'first_due_on' => '2026-11-01',
    ])->assertCreated();
    $rider = $this->postJson('/api/riders', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => 'Rafay'])->assertCreated();

    $this->postJson('/api/dispatch/assign', ['transaction_id' => $sale->id, 'rider_id' => $rider->json('data.id')])->assertUnprocessable();

    $this->postJson('/api/installment-plans/'.$plan->json('data.id').'/pay', [
        'amount' => 300, 'paid_on' => '2026-10-05', 'method' => 'cash', 'payment_account' => $scope['payment_account_id'],
    ])->assertOk();

    $this->postJson('/api/dispatch/assign', ['transaction_id' => $sale->id, 'rider_id' => $rider->json('data.id')])->assertCreated();
});

test('dispatch cannot assign another company\'s sale or rider', function () {
    $scope = scoScope();
    $sale = createSellRecord($scope, ['final_amount' => 50]);
    $other = scoOtherCompany();
    Sanctum::actingAs(scoStaff($other, ['/rider', '/rider/add', '/dispatch', '/dispatch/assign']));
    $rider = $this->postJson('/api/riders', ['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'name' => 'Foreign Rider'])->assertCreated();

    $this->postJson('/api/dispatch/assign', ['transaction_id' => $sale->id, 'rider_id' => $rider->json('data.id')])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// Receipts, KPI, routes

test('a receipt link shows only its own sale, a whatsapp share with no provider is recorded as not configured, and an unknown token is 404', function () {
    $scope = scoScope();
    $sale = createSellRecord($scope, ['final_amount' => 120, 'invoice_no' => 'RCPT-1']);
    Sanctum::actingAs(scoStaff($scope, ['/sell/:id/view']));

    $link = $this->postJson("/api/sells/{$sale->id}/receipt-share", ['channel' => 'link'])->assertSuccessful();
    $this->get(parse_url($link->json('data.url'), PHP_URL_PATH))->assertOk()->assertSee('RCPT-1');
    $this->get('/r/'.str_repeat('x', 40))->assertNotFound();

    $wa = $this->postJson("/api/sells/{$sale->id}/receipt-share", ['channel' => 'whatsapp', 'recipient' => '03001234567'])->assertSuccessful();
    expect($wa->json('data.status'))->toBe('not_configured');

    $other = scoOtherCompany();
    Sanctum::actingAs(scoStaff($other, ['/sell/:id/view']));
    $this->postJson("/api/sells/{$sale->id}/receipt-share", ['channel' => 'link'])->assertNotFound();
});

test('the KPI statement reads commission tiers and sales targets of this company only', function () {
    $scope = scoScope();
    $other = scoOtherCompany();
    $kpiUser = scoStaff($scope, ['/saleskpi', '/saleskpi/edit']);
    Sanctum::actingAs($kpiUser);

    $this->postJson('/api/sales-kpi/tiers', ['company_id' => $scope['company_id'], 'threshold_amount' => 1000, 'rate_percent' => 2])->assertCreated();
    $this->postJson('/api/sales-kpi/targets', ['company_id' => $scope['company_id'], 'subject_type' => 'user', 'subject_id' => $kpiUser->id, 'period' => '2026-10', 'target_amount' => 5000])->assertCreated();
    $this->getJson('/api/sales-kpi/statement?period=2026-10&subject=user')->assertOk();

    Sanctum::actingAs(scoStaff($other, ['/saleskpi']));
    $this->getJson('/api/sales-kpi/tiers')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/sales-kpi/targets?period=2026-10')->assertOk()->assertJsonCount(0, 'data');
});

test('a sales route and its visit sheet are company scoped', function () {
    $scope = scoScope();
    $other = scoOtherCompany();
    Sanctum::actingAs(scoStaff($scope, ['/salesroute', '/salesroute/add', '/salesroute/visit']));

    $made = $this->postJson('/api/sales-routes', [
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => 'North', 'weekday' => 1,
        'stops' => [['contact_id' => $scope['contact_id']]],
    ])->assertCreated();

    Sanctum::actingAs(scoStaff($other, ['/salesroute', '/salesroute/visit']));
    $this->getJson('/api/sales-routes')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/sales-routes/'.$made->json('data.id').'/visit', ['contact_id' => $scope['contact_id'], 'status' => 'visited'])->assertNotFound();
});

test('a company user cannot write a record under another company by sending its id', function () {
    $scope = scoScope();
    $other = scoOtherCompany();
    Sanctum::actingAs(scoStaff($scope, ['/chequemanagement/add', '/ecommerce/add', '/recurringinvoice/add', '/salesroute/add', '/salesroute/:id/edit']));
    $foreign = ['company_id' => $other['company_id'], 'branch_id' => $other['branch_id']];

    $cheque = $this->postJson('/api/cheque-records', $foreign + ['direction' => 'received', 'cheque_no' => '77', 'amount' => 10])->assertCreated();
    expect((int) ChequeRecord::query()->findOrFail($cheque->json('data.id'))->company_id)->toBe($scope['company_id']);

    $connection = $this->postJson('/api/ecommerce-connections', $foreign + ['platform' => 'mock', 'name' => 'Mock shop'])->assertCreated();
    expect((int) DB::table('ecommerce_connections')->where('id', $connection->json('data.id'))->value('company_id'))->toBe($scope['company_id']);

    $schedule = $this->postJson('/api/recurring-invoices', $foreign + [
        'contact_id' => $scope['contact_id'], 'name' => 'Mine', 'frequency' => 'monthly', 'next_run_on' => '2026-12-01', 'lines' => [scoLine($scope, 1, 10)],
    ])->assertCreated();
    expect((int) RecurringInvoice::query()->findOrFail($schedule->json('data.id'))->company_id)->toBe($scope['company_id']);

    $route = $this->postJson('/api/sales-routes', $foreign + ['name' => 'Route one', 'stops' => []])->assertCreated();
    expect((int) DB::table('sales_routes')->where('id', $route->json('data.id'))->value('company_id'))->toBe($scope['company_id']);
    $this->putJson('/api/sales-routes/'.$route->json('data.id'), $foreign + ['name' => 'Route two', 'stops' => []])->assertOk();
    expect((int) DB::table('sales_routes')->where('id', $route->json('data.id'))->value('company_id'))->toBe($scope['company_id']);

    $foreignRider = DB::table('riders')->insertGetId(['company_id' => $other['company_id'], 'name' => 'Foreign', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $this->putJson('/api/sales-routes/'.$route->json('data.id'), ['name' => 'Route three', 'rider_id' => $foreignRider, 'stops' => []])->assertUnprocessable();
});
