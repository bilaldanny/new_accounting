<?php

use App\Models\Contact;
use App\Models\Payment;
use App\Models\PurchaseApprovalRule;
use App\Models\Role;
use App\Models\TAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StockMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Procurement & Purchases closeout (wave 3): multi-level approval, reorder suggestions, import purchase details, quality
 * inspection, supplier payment allocation and advances, inbound shipments, RMA, supplier performance and price comparison.
 */
function pcoRole(array $scope, array $paths): Role
{
    $role = Role::query()->create(['name' => 'pco_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return $role;
}

function pcoUser(array $scope, Role $role): User
{
    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
}

function pcoStaff(array $scope, array $paths): User
{
    return pcoUser($scope, pcoRole($scope, $paths));
}

/**
 * @return array<string, mixed>
 */
function pcoOtherCompany(): array
{
    $companyId = DB::table('companies')->insertGetId(['code' => 'PCO2', 'name' => 'Other Co', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branchId = DB::table('branches')->insertGetId(['code' => 'PCOB2', 'company_id' => $companyId, 'name' => 'Other Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $scope = ['company_id' => $companyId, 'branch_id' => $branchId];

    return $scope + prdProduct($scope, 'Other Co Product');
}

function pcoStock(array $scope): float
{
    return StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']);
}

function pcoReceived(array $scope, float $quantity, array $attributes = []): Transaction
{
    $purchase = createPurchaseRecord($scope, array_merge(['status' => 'received', 'invoice_no' => 'PCO-'.uniqid(), 'final_amount' => $quantity * 100], $attributes));
    DB::table('purchase_lines')->where('transaction_id', $purchase->id)->update(['quantity' => $quantity, 'quantity_received' => $quantity]);

    return $purchase;
}

// ---------------------------------------------------------------------------------------------------------------------
// Multi-level approval

test('with no approval rule one approval approves a purchase, as before', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, ['status' => 'pending', 'final_amount' => 9000]);
    Sanctum::actingAs(pcoStaff($scope, ['/purchase/approval']));

    $this->postJson("/api/purchase-approvals/{$purchase->id}/approve")->assertOk()->assertJsonPath('message', 'Successfully Approved');

    expect($purchase->fresh()->status)->toBe('approved');
});

test('a purchase in a rule range stays pending until every level approves, in order, by different people and roles', function () {
    $scope = seedPurchaseScope();
    $buyer = pcoRole($scope, ['/purchase/approval']);
    $manager = pcoRole($scope, ['/purchase/approval']);
    $director = pcoRole($scope, ['/purchase/approval']);

    PurchaseApprovalRule::query()->create([
        'company_id' => $scope['company_id'], 'name' => 'Large', 'min_amount' => 5000, 'max_amount' => null, 'forbid_creator' => true,
        'levels' => [['label' => 'Manager', 'role_id' => $manager->id], ['label' => 'Director', 'role_id' => $director->id]],
    ]);

    $creator = pcoUser($scope, $buyer);
    $purchase = createPurchaseRecord($scope, ['status' => 'pending', 'final_amount' => 8000, 'created_by' => $creator->id]);
    $small = createPurchaseRecord($scope, ['status' => 'pending', 'final_amount' => 900, 'invoice_no' => 'PCO-SMALL']);

    // a small purchase is outside the rule: one step
    Sanctum::actingAs(pcoUser($scope, $buyer));
    $this->postJson("/api/purchase-approvals/{$small->id}/approve")->assertOk();
    expect($small->fresh()->status)->toBe('approved');

    // the creator cannot approve their own, and the wrong role cannot take level 1
    Sanctum::actingAs($creator);
    $this->postJson("/api/purchase-approvals/{$purchase->id}/approve")->assertUnprocessable();
    Sanctum::actingAs(pcoUser($scope, $director));
    $this->postJson("/api/purchase-approvals/{$purchase->id}/approve")->assertUnprocessable();

    $managerUser = pcoUser($scope, $manager);
    Sanctum::actingAs($managerUser);
    $first = $this->postJson("/api/purchase-approvals/{$purchase->id}/approve")->assertOk();
    expect($first->json('message'))->toContain('level 1 of 2')->and($purchase->fresh()->status)->toBe('pending');

    // the same person cannot take level 2
    $this->postJson("/api/purchase-approvals/{$purchase->id}/approve")->assertUnprocessable();

    Sanctum::actingAs(pcoUser($scope, $director));
    $this->postJson("/api/purchase-approvals/{$purchase->id}/approve")->assertOk()->assertJsonPath('message', 'Successfully Approved');

    $fresh = $purchase->fresh();
    expect($fresh->status)->toBe('approved')
        ->and(DB::table('purchase_approval_steps')->where('transaction_id', $purchase->id)->count())->toBe(2);

    $this->getJson("/api/purchase-approvals/{$purchase->id}")->assertOk()->assertJsonPath('approval.done', 2);
});

test('approval rules are company scoped and need their permission', function () {
    $scope = seedPurchaseScope();
    $other = pcoOtherCompany();
    PurchaseApprovalRule::query()->create(['company_id' => $other['company_id'], 'name' => 'Foreign', 'min_amount' => 0, 'levels' => [['label' => 'L1', 'role_id' => null]]]);

    Sanctum::actingAs(pcoStaff($scope, ['/purchaseapprovalrule']));
    $this->getJson('/api/purchase-approval-rules')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/purchase-approval-rules', ['name' => 'X', 'min_amount' => 0, 'levels' => [['label' => 'L1']]])->assertForbidden();

    Sanctum::actingAs(pcoStaff($scope, ['/purchaseapprovalrule', '/purchaseapprovalrule/add', '/purchaseapprovalrule/:id/edit', '/purchaseapprovalrule/delete']));
    $created = $this->postJson('/api/purchase-approval-rules', ['name' => 'Mine', 'min_amount' => 100, 'max_amount' => 50, 'levels' => [['label' => 'L1']]])->assertUnprocessable();
    $created = $this->postJson('/api/purchase-approval-rules', ['name' => 'Mine', 'min_amount' => 100, 'levels' => [['label' => 'L1'], ['label' => 'L2']]])->assertCreated();

    $foreign = PurchaseApprovalRule::query()->where('company_id', $other['company_id'])->firstOrFail();
    $this->putJson("/api/purchase-approval-rules/{$foreign->id}", ['name' => 'x', 'min_amount' => 0, 'levels' => [['label' => 'a']]])->assertNotFound();
    $this->deleteJson('/api/purchase-approval-rules/'.$created->json('data.id'))->assertOk();
});

// ---------------------------------------------------------------------------------------------------------------------
// Reorder suggestions

test('a product below its reorder point is suggested with the quantity that brings it to the maximum, and a draft order is made', function () {
    $scope = seedPurchaseScope();
    pcoReceived($scope, 3);
    DB::table('products')->where('id', $scope['product_id'])->update(['reorder_level' => 10, 'reorder_qty' => 5, 'max_stock' => 40, 'lead_time_days' => 0]);
    Sanctum::actingAs(pcoStaff($scope, ['/reordersuggestion', '/reordersuggestion/add']));
    $stock = pcoStock($scope);
    $ledger = TAccount::query()->count();

    $rows = $this->getJson('/api/reorder-suggestions')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['on_hand'])->toEqual(3)
        ->and($rows[0]['suggested_qty'])->toEqual(37)
        ->and($rows[0]['supplier_id'])->toBe($scope['contact_id']);

    $made = $this->postJson('/api/reorder-suggestions/purchase-orders', ['branch_id' => $scope['branch_id'], 'items' => [['product_id' => $scope['product_id']]]])->assertCreated();
    $draft = Transaction::query()->findOrFail($made->json('data.0.id'));

    expect($draft->status)->toBe('draft')->and($draft->purchaselines()->first()->quantity)->toEqual(37)
        ->and(pcoStock($scope))->toBe($stock)
        ->and(TAccount::query()->count())->toBe($ledger);
});

test('lead time and recent sales raise the reorder point, and a product above it is not suggested', function () {
    $scope = seedPurchaseScope();
    pcoReceived($scope, 12);
    DB::table('products')->where('id', $scope['product_id'])->update(['reorder_level' => 10, 'reorder_qty' => 5, 'lead_time_days' => 0]);
    Sanctum::actingAs(pcoStaff($scope, ['/reordersuggestion']));

    expect($this->getJson('/api/reorder-suggestions')->json('data'))->toBe([]);

    // 60 sold in 30 days = 2 a day, 5 days of lead time = 10 more: the point is 20 and 12 on hand is below it
    $sale = DB::table('transactions')->insertGetId([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'], 'invoice_no' => 'PCO-S', 'type' => 'sell',
        'status' => 'final', 'payment_status' => 'due', 'transaction_date' => now()->toDateString(), 'final_amount' => 1, 'total_item' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sell_lines')->insert(['transaction_id' => $sale, 'product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'quantity' => 60, 'unit_price' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('products')->where('id', $scope['product_id'])->update(['lead_time_days' => 5]);

    $row = $this->getJson('/api/reorder-suggestions')->json('data.0');
    expect($row['reorder_point'])->toEqual(20)->and($row['avg_daily_sales'])->toEqual(2);
});

test('an order cannot be made for a product that is not suggested or for a supplier of another company', function () {
    $scope = seedPurchaseScope();
    $other = pcoOtherCompany();
    pcoReceived($scope, 1);
    DB::table('products')->where('id', $scope['product_id'])->update(['reorder_level' => 10, 'reorder_qty' => 5]);
    $foreign = Contact::query()->create(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'business_name' => 'Foreign Supplier', 'first_name' => 'F', 'mobile' => '0300', 'address' => 'x', 'code' => 'SU-F', 'user_type' => 'supplier', 'type' => 'local', 'ntn_number' => '1', 'active' => true]);
    Sanctum::actingAs(pcoStaff($scope, ['/reordersuggestion', '/reordersuggestion/add']));

    $this->postJson('/api/reorder-suggestions/purchase-orders', ['branch_id' => $scope['branch_id'], 'items' => [['product_id' => $other['product_id']]]])->assertUnprocessable();
    $this->postJson('/api/reorder-suggestions/purchase-orders', ['branch_id' => $scope['branch_id'], 'items' => [['product_id' => $scope['product_id'], 'supplier_id' => $foreign->id]]])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// Import purchase details

test('import details are kept beside the purchase and show how far the foreign total is from the local one', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, ['final_amount' => 28000, 'invoice_no' => 'PCO-IMP']);
    Sanctum::actingAs(pcoStaff($scope, ['/importpurchase', '/importpurchase/:id/edit']));

    $this->putJson("/api/import-purchases/{$purchase->id}", [
        'lc_no' => 'LC-77', 'lc_bank' => 'HBL', 'lc_amount' => 100000, 'lc_opened_on' => '2026-07-01', 'lc_expires_on' => '2026-06-01',
    ])->assertUnprocessable();

    $saved = $this->putJson("/api/import-purchases/{$purchase->id}", [
        'lc_no' => 'LC-77', 'lc_bank' => 'HBL', 'lc_amount' => 100000, 'lc_opened_on' => '2026-07-01', 'lc_expires_on' => '2026-09-01',
        'customs_ref' => 'GD-9', 'customs_cleared_on' => '2026-08-20', 'currency_code' => 'USD', 'exchange_rate' => 280, 'foreign_total' => 100,
    ])->assertOk();

    expect($saved->json('data.local_equivalent'))->toEqual(28000)->and($saved->json('data.variance'))->toEqual(0);

    $this->putJson("/api/import-purchases/{$purchase->id}", ['foreign_total' => 100, 'exchange_rate' => 285])->assertOk();
    $this->getJson("/api/import-purchases/{$purchase->id}")->assertOk()->assertJsonPath('data.variance', -500);
    expect($purchase->fresh()->final_amount)->toEqual(28000)->and(DB::table('import_purchase_details')->count())->toBe(1);
});

test('import details of another company cannot be read or changed', function () {
    $scope = seedPurchaseScope();
    $other = seedPurchaseScope2();
    Sanctum::actingAs(pcoStaff($scope, ['/importpurchase', '/importpurchase/:id/edit']));

    $foreign = createPurchaseRecord($other, ['invoice_no' => 'PCO-FOREIGN']);
    $this->getJson("/api/import-purchases/{$foreign->id}")->assertNotFound();
    $this->putJson("/api/import-purchases/{$foreign->id}", ['lc_no' => 'x'])->assertNotFound();
});

function seedPurchaseScope2(): array
{
    $companyId = DB::table('companies')->insertGetId(['code' => 'PCO3', 'name' => 'Third Co', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branchId = DB::table('branches')->insertGetId(['code' => 'PCOB3', 'company_id' => $companyId, 'name' => 'Third Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $scope = ['company_id' => $companyId, 'branch_id' => $branchId];
    $product = prdProduct($scope, 'Third Product');
    $supplier = Contact::query()->create(['company_id' => $companyId, 'branch_id' => $branchId, 'business_name' => 'Third Supplier', 'first_name' => 'T', 'mobile' => '0300', 'address' => 'x', 'code' => 'SU-T', 'user_type' => 'supplier', 'type' => 'local', 'ntn_number' => '1', 'active' => true]);

    return $scope + $product + ['contact_id' => $supplier->id, 'itemtype_id' => null];
}

// ---------------------------------------------------------------------------------------------------------------------
// Quality inspection

test('stock counts only what an inspection accepted, and deleting the inspection puts the rejected quantity back', function () {
    $scope = seedPurchaseScope();
    $purchase = pcoReceived($scope, 10);
    $lineId = (int) DB::table('purchase_lines')->where('transaction_id', $purchase->id)->value('id');
    Sanctum::actingAs(pcoStaff($scope, ['/qualityinspection', '/qualityinspection/add', '/qualityinspection/delete']));

    expect(pcoStock($scope))->toBe(10.0);

    $this->postJson('/api/quality-inspections', ['transaction_id' => $purchase->id, 'inspected_on' => '2026-09-05', 'lines' => [['purchase_line_id' => $lineId, 'accepted_qty' => 9, 'rejected_qty' => 5]]])->assertUnprocessable();

    $saved = $this->postJson('/api/quality-inspections', [
        'transaction_id' => $purchase->id, 'inspected_on' => '2026-09-05', 'note' => 'Damaged cartons',
        'lines' => [['purchase_line_id' => $lineId, 'accepted_qty' => 8, 'rejected_qty' => 2, 'reason' => 'Crushed']],
    ])->assertCreated();

    expect($saved->json('data.result'))->toBe('partial')
        ->and((float) DB::table('purchase_lines')->where('id', $lineId)->value('quantity_rejected'))->toBe(2.0)
        ->and(pcoStock($scope))->toBe(8.0);

    $this->deleteJson('/api/quality-inspections/'.$saved->json('data.id'))->assertOk();
    expect((float) DB::table('purchase_lines')->where('id', $lineId)->value('quantity_rejected'))->toBe(0.0)
        ->and(pcoStock($scope))->toBe(10.0);
});

test('inspections are company scoped and a line that was not received cannot be inspected', function () {
    $scope = seedPurchaseScope();
    $other = seedPurchaseScope2();
    $mine = createPurchaseRecord($scope, ['invoice_no' => 'PCO-NR']);
    $foreign = createPurchaseRecord($other, ['invoice_no' => 'PCO-FR']);
    $line = (int) DB::table('purchase_lines')->where('transaction_id', $mine->id)->value('id');
    Sanctum::actingAs(pcoStaff($scope, ['/qualityinspection', '/qualityinspection/add']));

    $this->postJson('/api/quality-inspections', ['transaction_id' => $mine->id, 'inspected_on' => '2026-09-05', 'lines' => [['purchase_line_id' => $line, 'accepted_qty' => 1, 'rejected_qty' => 0]]])->assertUnprocessable();
    $this->postJson('/api/quality-inspections', ['transaction_id' => $foreign->id, 'inspected_on' => '2026-09-05', 'lines' => [['purchase_line_id' => 1, 'accepted_qty' => 1, 'rejected_qty' => 0]]])->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// Supplier payments and advances

function pcoCash(array $scope): int
{
    $cashId = insertPurchaseChartAccount($scope, '101-09001', 'PCO Cash', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cashId);

    return $cashId;
}

test('one payment is allocated across several bills through the normal payment path and the rest is kept as an advance', function () {
    $scope = seedPurchaseScope();
    $cash = pcoCash($scope);
    $a = createPurchaseRecord($scope, ['final_amount' => 100, 'invoice_no' => 'PCO-A']);
    $b = createPurchaseRecord($scope, ['final_amount' => 200, 'invoice_no' => 'PCO-B']);
    Sanctum::actingAs(pcoStaff($scope, ['/supplierpayment', '/supplierpayment/add', '/supplierpayment/reverse']));

    $this->postJson('/api/supplier-payments', [
        'contact_id' => $scope['contact_id'], 'paid_on' => '2026-09-10', 'payment_account' => $cash, 'method' => 'cash', 'amount' => 350,
        'allocations' => [['transaction_id' => $a->id, 'amount' => 100], ['transaction_id' => $b->id, 'amount' => 200]],
    ])->assertUnprocessable();

    $saved = $this->postJson('/api/supplier-payments', [
        'contact_id' => $scope['contact_id'], 'paid_on' => '2026-09-10', 'payment_account' => $cash, 'method' => 'cash', 'amount' => 350, 'keep_advance' => true,
        'allocations' => [['transaction_id' => $a->id, 'amount' => 100], ['transaction_id' => $b->id, 'amount' => 200]],
    ])->assertCreated();

    expect($a->fresh()->payment_status)->toBe('paid')->and($b->fresh()->payment_status)->toBe('paid')
        ->and($saved->json('data.advance_amount'))->toEqual(50)
        ->and(Payment::query()->whereNull('transaction_id')->where('amount', 50)->exists())->toBeTrue();

    // every payment, bill and advance has its own voucher: cash is credited 350 in all
    $credited = (float) DB::table('t_account_details')->where('coa_id', $cash)->sum('credit');
    expect($credited)->toBe(350.0);
});

test('an advance settles a later bill without moving cash, and reversing the batch puts everything back', function () {
    $scope = seedPurchaseScope();
    $cash = pcoCash($scope);
    $bill = createPurchaseRecord($scope, ['final_amount' => 80, 'invoice_no' => 'PCO-LATER']);
    Sanctum::actingAs(pcoStaff($scope, ['/supplierpayment', '/supplierpayment/add', '/supplierpayment/reverse']));

    $batch = $this->postJson('/api/supplier-payments', [
        'contact_id' => $scope['contact_id'], 'paid_on' => '2026-09-10', 'payment_account' => $cash, 'method' => 'cash', 'amount' => 100, 'keep_advance' => true,
    ])->assertCreated()->json('data');

    $vouchers = TAccount::query()->count();

    $this->postJson("/api/supplier-payments/{$batch['id']}/apply-advance", ['paid_on' => '2026-09-12', 'allocations' => [['transaction_id' => $bill->id, 'amount' => 150]]])->assertUnprocessable();
    $this->postJson("/api/supplier-payments/{$batch['id']}/apply-advance", ['paid_on' => '2026-09-12', 'allocations' => [['transaction_id' => $bill->id, 'amount' => 80]]])->assertOk();

    expect($bill->fresh()->payment_status)->toBe('paid')
        ->and(TAccount::query()->count())->toBe($vouchers)
        ->and((float) DB::table('t_account_details')->where('coa_id', $cash)->sum('credit'))->toBe(100.0);

    $this->postJson("/api/supplier-payments/{$batch['id']}/reverse")->assertOk();

    expect($bill->fresh()->payment_status)->toBe('due')
        ->and(Payment::query()->count())->toBe(0)
        ->and(SupplierPaymentBatchStatus($batch['id']))->toBe('reversed');
    $this->postJson("/api/supplier-payments/{$batch['id']}/reverse")->assertUnprocessable();
});

function SupplierPaymentBatchStatus(int $id): string
{
    return (string) DB::table('supplier_payment_batches')->where('id', $id)->value('status');
}

test('supplier payments cannot touch another company bills, supplier or account', function () {
    $scope = seedPurchaseScope();
    $other = seedPurchaseScope2();
    $cash = pcoCash($scope);
    $foreignBill = createPurchaseRecord($other, ['final_amount' => 100, 'invoice_no' => 'PCO-FB']);
    Sanctum::actingAs(pcoStaff($scope, ['/supplierpayment', '/supplierpayment/add']));

    $this->postJson('/api/supplier-payments', [
        'contact_id' => $other['contact_id'], 'paid_on' => '2026-09-10', 'payment_account' => $cash, 'method' => 'cash', 'amount' => 10, 'keep_advance' => true,
    ])->assertUnprocessable();

    $this->postJson('/api/supplier-payments', [
        'contact_id' => $scope['contact_id'], 'paid_on' => '2026-09-10', 'payment_account' => $cash, 'method' => 'cash', 'amount' => 10,
        'allocations' => [['transaction_id' => $foreignBill->id, 'amount' => 10]],
    ])->assertUnprocessable();
    expect(Payment::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------------
// Inbound shipments, RMA

test('a shipment keeps a status history and a late one is flagged', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, ['invoice_no' => 'PCO-SHIP']);
    Sanctum::actingAs(pcoStaff($scope, ['/inboundshipment', '/inboundshipment/add', '/inboundshipment/:id/edit']));

    $made = $this->postJson('/api/inbound-shipments', ['transaction_id' => $purchase->id, 'carrier' => 'DHL', 'tracking_no' => 'T-1', 'eta' => now()->subDays(3)->toDateString(), 'status' => 'shipped'])->assertCreated();
    $id = $made->json('data.id');

    $late = $this->getJson('/api/inbound-shipments?late=1')->assertOk();
    expect($late->json('data.data'))->toHaveCount(1)->and($late->json('data.data.0.is_late'))->toBeTrue();

    $this->putJson("/api/inbound-shipments/{$id}", ['status' => 'customs', 'location' => 'Karachi port'])->assertOk();
    $this->putJson("/api/inbound-shipments/{$id}", ['status' => 'arrived'])->assertOk();

    $shipment = $this->getJson("/api/inbound-shipments/{$id}")->assertOk();
    expect(collect($shipment->json('events'))->pluck('status')->all())->toBe(['shipped', 'customs', 'arrived'])
        ->and($shipment->json('arrived_on'))->not->toBeNull()
        ->and($this->getJson('/api/inbound-shipments?late=1')->json('data.data'))->toBe([]);

    $this->postJson('/api/inbound-shipments', ['transaction_id' => 999999])->assertNotFound();
});

test('an RMA moves along its flow only, needs the supplier number to be authorised and is company scoped', function () {
    $scope = seedPurchaseScope();
    $other = seedPurchaseScope2();
    Sanctum::actingAs(pcoStaff($scope, ['/rma', '/rma/add', '/rma/:id/edit', '/rma/delete']));

    $this->postJson('/api/rma', ['contact_id' => $other['contact_id'], 'lines' => [['product_id' => $scope['product_id'], 'quantity' => 1]]])->assertUnprocessable();

    $rma = $this->postJson('/api/rma', ['contact_id' => $scope['contact_id'], 'reason' => 'Defective', 'lines' => [['product_id' => $scope['product_id'], 'quantity' => 2, 'defect' => 'Cracked']]])->assertCreated();
    $id = $rma->json('data.id');

    $this->postJson("/api/rma/{$id}/status", ['status' => 'shipped'])->assertUnprocessable();
    $this->postJson("/api/rma/{$id}/status", ['status' => 'authorized'])->assertUnprocessable();
    $this->postJson("/api/rma/{$id}/status", ['status' => 'authorized', 'supplier_rma_no' => 'SUP-RMA-5'])->assertOk()->assertJsonPath('data.status', 'authorized');
    $this->postJson("/api/rma/{$id}/status", ['status' => 'inspected', 'inspection_note' => 'Confirmed'])->assertOk();
    $this->postJson("/api/rma/{$id}/status", ['status' => 'shipped'])->assertOk();
    $this->postJson("/api/rma/{$id}/status", ['status' => 'received'])->assertOk();
    $this->deleteJson("/api/rma/{$id}")->assertUnprocessable();

    $this->getJson('/api/rma')->assertOk()->assertJsonCount(1, 'data.data');
});

// ---------------------------------------------------------------------------------------------------------------------
// Supplier performance and price comparison

test('supplier performance shows on-time delivery and defect rate, and a dash when there is nothing to judge', function () {
    $scope = seedPurchaseScope();
    $today = now()->toDateString();
    $purchase = pcoReceived($scope, 10, ['transaction_date' => $today]);
    $second = pcoReceived($scope, 10, ['transaction_date' => $today, 'invoice_no' => 'PCO-2']);
    $lineId = (int) DB::table('purchase_lines')->where('transaction_id', $purchase->id)->value('id');

    Sanctum::actingAs(pcoStaff($scope, ['/report/supplier-performance', '/qualityinspection', '/qualityinspection/add', '/inboundshipment', '/inboundshipment/add', '/inboundshipment/:id/edit']));

    $before = collect($this->getJson('/api/reports/supplier-performance')->assertOk()->json('data.data'))->first();
    expect($before['on_time_percent'])->toBeNull()->and($before['defect_rate'])->toBeNull();

    $this->postJson('/api/quality-inspections', ['transaction_id' => $purchase->id, 'inspected_on' => $today, 'lines' => [['purchase_line_id' => $lineId, 'accepted_qty' => 9, 'rejected_qty' => 1]]])->assertCreated();

    foreach ([[$purchase, now()->addDays(2)->toDateString(), true], [$second, now()->subDay()->toDateString(), false]] as [$po, $eta, $onTime]) {
        $id = $this->postJson('/api/inbound-shipments', ['transaction_id' => $po->id, 'eta' => $eta])->assertCreated()->json('data.id');
        $this->putJson("/api/inbound-shipments/{$id}", ['status' => 'arrived', 'arrived_on' => $today])->assertOk();
    }

    $row = collect($this->getJson('/api/reports/supplier-performance')->json('data.data'))->first();
    expect($row['on_time_percent'])->toEqual(50)->and($row['defect_rate'])->toEqual(10);
});

test('price comparison lists each supplier rate per product and flags the cheapest', function () {
    $scope = seedPurchaseScope();
    $second = Contact::query()->create(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'business_name' => 'Cheaper Supplies', 'first_name' => 'C', 'mobile' => '0301', 'address' => 'x', 'code' => 'SU-C', 'user_type' => 'supplier', 'type' => 'local', 'ntn_number' => '1', 'active' => true]);
    createPurchaseRecord($scope, ['invoice_no' => 'PCO-P1', 'transaction_date' => now()->subDays(20)->toDateString()]);
    createPurchaseRecord($scope, ['invoice_no' => 'PCO-P2', 'transaction_date' => now()->subDays(5)->toDateString(), 'contact_id' => $second->id]);
    DB::table('purchase_lines')->orderByDesc('id')->limit(1)->update(['purchase_rate' => 90]);

    Sanctum::actingAs(pcoStaff($scope, ['/report/supplier-price-comparison']));
    $rows = collect($this->getJson('/api/reports/supplier-price-comparison')->assertOk()->json('data.data'))->keyBy('supplier_name');

    expect($rows['Cheaper Supplies']['best'])->toBe('Best price')->and($rows['Cheaper Supplies']['avg_rate'])->toEqual(90)
        ->and($rows['Acme Supplies']['above_best_percent'])->toEqual(11.11);
});

test('the procurement menu migration adds each page once and grants it to companyadmin', function () {
    $admin = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true])->id;

    foreach (['/purchase' => 'Purchase', '/report/stock' => 'Stock Report'] as $path => $name) {
        $group = DB::table('menus')->where('route_path', $path)->value('parent_id');

        if ($group === null) {
            $group = DB::table('menus')->insertGetId(['name' => 'G'.$name, 'route_name' => 'g'.md5($path), 'route_path' => '/g'.md5($path), 'icon' => 'Grid', 'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now()]);

            if (DB::table('menus')->where('route_path', $path)->exists()) {
                DB::table('menus')->where('route_path', $path)->update(['parent_id' => $group]);
            } else {
                DB::table('menus')->insert(['parent_id' => $group, 'name' => $name, 'route_name' => ltrim($path, '/'), 'route_path' => $path, 'icon' => 'Grid', 'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    $migration = require database_path('migrations/2026_11_06_110000_add_procurement_closeout_menus.php');
    $migration->up();
    $migration->up();

    $paths = ['/purchaseapprovalrule', '/reordersuggestion', '/importpurchase', '/qualityinspection', '/supplierpayment', '/inboundshipment', '/rma', '/report/supplier-price-comparison'];
    $granted = DB::table('permissions')->join('menus', 'menus.id', '=', 'permissions.menu_id')
        ->where('permissions.role_id', $admin)->whereIn('menus.route_path', $paths)->pluck('menus.route_path')->sort()->values()->all();

    expect($granted)->toBe(collect($paths)->sort()->values()->all());
});
