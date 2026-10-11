<?php

use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\StockReservation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InventoryClose;
use App\Services\StockMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Inventory & Warehouse closeout (wave 2): negative stock rules, reservations, pick / pack / put-away lists, the damage /
 * expired / wastage screen, the inventory closing lock, ABC analysis and stock ageing. Every feature is off or inert until a
 * company uses it, and the tests that say "as before" pin that.
 */
function icoStaff(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'ico_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
}

/**
 * @return array<string, mixed>
 */
function icoOtherCompany(): array
{
    $companyId = DB::table('companies')->insertGetId(['code' => 'ICO2', 'name' => 'Other Co', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branchId = DB::table('branches')->insertGetId(['code' => 'ICOB2', 'company_id' => $companyId, 'name' => 'Other Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $scope = ['company_id' => $companyId, 'branch_id' => $branchId];

    return $scope + prdProduct($scope, 'Other Co Product');
}

function icoSetting(int $companyId, array $values): void
{
    $setting = CompanySetting::query()->firstOrCreate(['company_id' => $companyId], CompanySetting::defaultSettingsAttributes('Test'));
    $setting->forceFill($values)->save();
}

function icoStock(array $scope): float
{
    return StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']);
}

function icoReceive(array $scope, float $quantity, string $date = '2026-09-01'): void
{
    createPurchaseRecord($scope, ['status' => 'received', 'invoice_no' => 'ICO-PO-'.uniqid(), 'transaction_date' => $date, 'contact_id' => DB::table('contacts')->where('company_id', $scope['company_id'])->where('user_type', 'supplier')->value('id')]);
    DB::table('purchase_lines')->orderByDesc('id')->limit(1)->update(['quantity' => $quantity, 'quantity_received' => $quantity]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Negative stock rules

test('with no rule the POS refuses a sale below zero stock and the sale form saves it with a warning, as before', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', validSellPayload($scope, ['is_pos' => true]))->assertUnprocessable();

    $saved = $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
    expect($saved->json('stock_warnings'))->not->toBeEmpty();
});

test('a company that allows negative stock lets the POS sell, and one that blocks refuses the sale form', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    icoSetting($scope['company_id'], ['negative_stock_mode' => 'allow']);
    $this->postJson('/api/sells', validSellPayload($scope, ['is_pos' => true]))->assertSuccessful();

    icoSetting($scope['company_id'], ['negative_stock_mode' => 'block']);
    $this->postJson('/api/sells', validSellPayload($scope))->assertUnprocessable()->assertJsonValidationErrors(['selllines']);
});

test('a product rule wins over the company rule', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    icoSetting($scope['company_id'], ['negative_stock_mode' => 'allow']);
    DB::table('products')->where('id', $scope['product_id'])->update(['negative_stock' => 'block']);

    $this->postJson('/api/sells', validSellPayload($scope, ['is_pos' => true]))->assertUnprocessable();

    DB::table('products')->where('id', $scope['product_id'])->update(['negative_stock' => 'allow']);
    icoSetting($scope['company_id'], ['negative_stock_mode' => 'block']);
    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
});

test('the negative stock and reservation settings are saved through the company settings', function () {
    $scope = seedSellScope();
    CompanySetting::createCompanySettings($scope['company_id'], 'Settings Co');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson("/api/company-settings/{$scope['company_id']}", ['business_name' => 'Settings Co', 'negative_stock_mode' => 'block', 'reservation_mode' => 'enforce'])->assertOk();

    $row = DB::table('company_settings')->where('company_id', $scope['company_id'])->first();
    expect($row->negative_stock_mode)->toBe('block')->and($row->reservation_mode)->toBe('enforce');

    $this->putJson("/api/company-settings/{$scope['company_id']}", ['business_name' => 'Settings Co', 'negative_stock_mode' => 'sometimes'])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// Reservations

test('a reservation is a record that changes no stock and blocks nothing until the company enforces it', function () {
    $scope = seedSellScope();
    icoReceive($scope, 10);
    Sanctum::actingAs(icoStaff($scope, ['/stockreservation', '/stockreservation/add', '/stockreservation/:id/edit', '/sell']));

    $before = icoStock($scope);
    $this->postJson('/api/stock-reservations', ['product_id' => $scope['product_id'], 'quantity' => 8, 'reference' => 'ORD-1'])->assertCreated();

    expect(icoStock($scope))->toBe($before);

    $availability = $this->getJson('/api/stock-reservations/availability?product_id='.$scope['product_id'])->assertOk();
    expect($availability->json('on_hand'))->toEqual(10)->and($availability->json('reserved'))->toEqual(8)->and($availability->json('available'))->toEqual(2)
        ->and($availability->json('enforced'))->toBeFalse();

    Sanctum::actingAs(User::query()->findOrFail(1));
    $response = $this->postJson('/api/sells', validSellPayload($scope, ['is_pos' => true, 'selllines' => [array_merge(validSellPayload($scope)['selllines'][0], ['quantity' => 5])], 'final_amount' => 640]))->assertSuccessful();
    expect($response->json('stock_warnings') ?? [])->toBe([]);
});

test('with enforcement a sale cannot take what others hold, but the customer own hold is theirs', function () {
    $scope = seedSellScope();
    icoReceive($scope, 10);
    icoSetting($scope['company_id'], ['reservation_mode' => 'enforce']);
    $other = createExportCustomer($scope);

    StockReservation::query()->create(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'product_id' => $scope['product_id'], 'contact_id' => $other->id, 'quantity' => 8, 'status' => 'active']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $line = array_merge(validSellPayload($scope)['selllines'][0], ['quantity' => 5]);

    // 10 on hand, 8 held for someone else: 2 available, the POS cannot sell 5
    $this->postJson('/api/sells', validSellPayload($scope, ['is_pos' => true, 'selllines' => [$line], 'final_amount' => 640]))->assertUnprocessable();

    // the holder buys it
    $this->postJson('/api/sells', validSellPayload($scope, ['is_pos' => true, 'contact_id' => $other->id, 'selllines' => [$line], 'final_amount' => 640]))->assertSuccessful();
});

test('a released or expired reservation holds nothing and reservations are company scoped', function () {
    $scope = seedSellScope();
    $other = icoOtherCompany();
    icoReceive($scope, 10);
    icoSetting($scope['company_id'], ['reservation_mode' => 'enforce']);

    StockReservation::query()->create(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'product_id' => $scope['product_id'], 'quantity' => 9, 'status' => 'active', 'expires_on' => '2020-01-01']);
    $foreign = StockReservation::query()->create(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'product_id' => $other['product_id'], 'quantity' => 1, 'status' => 'active']);

    Sanctum::actingAs(icoStaff($scope, ['/stockreservation', '/stockreservation/add', '/stockreservation/:id/edit']));

    expect($this->getJson('/api/stock-reservations/availability?product_id='.$scope['product_id'])->json('reserved'))->toEqual(0);
    $this->getJson('/api/stock-reservations?status=all')->assertOk()->assertJsonCount(1, 'data.data');
    $this->postJson("/api/stock-reservations/{$foreign->id}/close", ['outcome' => 'released'])->assertNotFound();
    $this->postJson('/api/stock-reservations', ['product_id' => $other['product_id'], 'quantity' => 1])->assertUnprocessable();

    $mine = StockReservation::query()->where('company_id', $scope['company_id'])->firstOrFail();
    $this->postJson("/api/stock-reservations/{$mine->id}/close", ['outcome' => 'released'])->assertOk();
    $this->postJson("/api/stock-reservations/{$mine->id}/close", ['outcome' => 'released'])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// Pick / pack / put-away

test('a pick list is made from a posted sale, worked off and completed without moving stock', function () {
    $scope = seedSellScope();
    icoReceive($scope, 10);
    $sale = createSellRecord($scope, ['invoice_no' => 'ICO-INV-1']);
    $draft = createSellRecord($scope, ['invoice_no' => 'ICO-DRAFT', 'status' => 'draft']);
    Sanctum::actingAs(icoStaff($scope, ['/warehousetask', '/warehousetask/add', '/warehousetask/:id/edit']));
    $stock = icoStock($scope);

    $this->postJson('/api/warehouse-tasks', ['type' => 'pick', 'transaction_id' => $draft->id])->assertUnprocessable();
    $this->postJson('/api/warehouse-tasks', ['type' => 'putaway', 'transaction_id' => $sale->id])->assertUnprocessable();

    $task = $this->postJson('/api/warehouse-tasks', ['type' => 'pick', 'transaction_id' => $sale->id])->assertCreated();
    $id = $task->json('data.id');
    $line = $task->json('data.lines.0');
    expect($task->json('data.task_no'))->toStartWith('PI-')->and($line['quantity'])->toEqual(1);

    $this->postJson("/api/warehouse-tasks/{$id}/complete")->assertUnprocessable();
    $this->postJson("/api/warehouse-tasks/{$id}/progress", ['lines' => [['id' => $line['id'], 'quantity_done' => 5]]])->assertUnprocessable();

    $this->postJson("/api/warehouse-tasks/{$id}/progress", ['lines' => [['id' => $line['id'], 'quantity_done' => 1]]])->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/warehouse-tasks/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'done');
    $this->postJson("/api/warehouse-tasks/{$id}/cancel")->assertUnprocessable();

    expect(icoStock($scope))->toBe($stock);
});

test('a put-away list is made from a purchase and records the location of each line', function () {
    $scope = seedSellScope();
    icoReceive($scope, 4);
    $purchase = Transaction::query()->where('type', 'purchaseorder')->firstOrFail();
    Sanctum::actingAs(icoStaff($scope, ['/warehousetask', '/warehousetask/add', '/warehousetask/:id/edit']));

    $task = $this->postJson('/api/warehouse-tasks', ['type' => 'putaway', 'transaction_id' => $purchase->id])->assertCreated();
    $line = $task->json('data.lines.0');

    $this->postJson('/api/warehouse-tasks/'.$task->json('data.id').'/progress', ['lines' => [['id' => $line['id'], 'quantity_done' => 4, 'location_id' => 7]]])->assertOk();
    expect(DB::table('warehouse_task_lines')->where('id', $line['id'])->value('location_id'))->toBe(7);
});

test('warehouse lists are company scoped and need their permission', function () {
    $scope = seedSellScope();
    $other = icoOtherCompany();
    $sale = createSellRecord($scope, ['invoice_no' => 'ICO-INV-2']);

    Sanctum::actingAs(icoStaff($scope, ['/warehousetask']));
    $this->postJson('/api/warehouse-tasks', ['type' => 'pick', 'transaction_id' => $sale->id])->assertForbidden();

    Sanctum::actingAs(icoStaff($other, ['/warehousetask', '/warehousetask/add']));
    $this->postJson('/api/warehouse-tasks', ['type' => 'pick', 'transaction_id' => $sale->id])->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// Damage, expired and wastage

test('a write-off is a completed abnormal adjustment with a reason and a value, and cannot go below stock', function () {
    $scope = seedSellScope();
    icoReceive($scope, 10);
    DB::table('purchase_lines')->orderByDesc('id')->limit(1)->update(['purchase_rate' => 7]);
    Sanctum::actingAs(icoStaff($scope, ['/stockwriteoff', '/stockwriteoff/add']));

    $row = ['product_id' => $scope['product_id'], 'quantity' => 4];
    $this->postJson('/api/stock-writeoffs', ['reason' => 'damage', 'date' => '2026-09-15', 'rows' => [['product_id' => $scope['product_id'], 'quantity' => 40]]])->assertUnprocessable();
    $this->postJson('/api/stock-writeoffs', ['reason' => 'sold', 'date' => '2026-09-15', 'rows' => [$row]])->assertUnprocessable();

    $saved = $this->postJson('/api/stock-writeoffs', ['reason' => 'expired', 'date' => '2026-09-15', 'rows' => [$row], 'note' => 'Past date'])->assertCreated();

    $adjustment = Transaction::query()->findOrFail($saved->json('data.adjustment_id'));
    expect($adjustment->adjustment_type)->toBe('abnormal')->and($adjustment->status)->toBe('completed')
        ->and(icoStock($scope))->toBe(6.0)
        ->and($saved->json('data.total_value'))->toEqual(28);

    $summary = $this->getJson('/api/stock-writeoffs')->assertOk();
    expect($summary->json('by_reason.expired.quantity'))->toEqual(4)->and($summary->json('by_reason.damage.entries'))->toBe(0);
});

test('write-offs of another company are not listed and its products cannot be written off', function () {
    $scope = seedSellScope();
    $other = icoOtherCompany();
    Sanctum::actingAs(icoStaff($scope, ['/stockwriteoff', '/stockwriteoff/add']));

    $this->postJson('/api/stock-writeoffs', ['reason' => 'damage', 'date' => '2026-09-15', 'rows' => [['product_id' => $other['product_id'], 'quantity' => 1]]])->assertUnprocessable();
    DB::table('stock_writeoffs')->insert(['company_id' => $other['company_id'], 'adjustment_id' => 1, 'reason' => 'damage', 'total_quantity' => 3, 'created_at' => now(), 'updated_at' => now()]);

    expect($this->getJson('/api/stock-writeoffs')->json('data.data'))->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------------
// Inventory close

test('with no close recorded every stock document saves exactly as before', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => '2020-01-05']))->assertSuccessful();
    expect(DB::table('inventory_closes')->count())->toBe(0);
});

test('after a close no stock document dated inside it can be created, changed or deleted, and later ones are free', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(icoStaff($scope, ['/inventoryclose', '/inventoryclose/add', '/inventoryclose/reopen', '/sell', '/sell/add', '/sell/:id/edit', '/sell/delete']));

    $inside = createSellRecord($scope, ['invoice_no' => 'ICO-IN', 'transaction_date' => '2026-08-10']);

    $this->postJson('/api/inventory-close', ['locked_through' => '2026-08-31', 'reason' => 'August counted'])->assertCreated();
    $this->getJson('/api/inventory-close')->assertOk()->assertJsonPath('locked_through', '2026-08-31');

    $refused = $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => '2026-08-20']))->assertUnprocessable();
    expect($refused->json('period_locked'))->toBeTrue()->and($refused->json('locked_through'))->toBe('2026-08-31');

    $this->putJson("/api/sells/{$inside->id}", validSellPayload($scope, ['transaction_date' => '2026-08-10', 'additional_note' => 'edited']))->assertUnprocessable();
    $this->postJson('/api/sells/bulk_delete', [$inside->id])->assertUnprocessable();
    expect(Transaction::query()->whereKey($inside->id)->exists())->toBeTrue();

    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => '2026-09-02']))->assertSuccessful();

    // a draft dated inside the close is not a stock movement
    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => '2026-08-20', 'status' => 'draft']))->assertSuccessful();
});

test('an administrator with the override permission sends one save through with a reason that is audited', function () {
    $scope = seedSellScope();
    DB::table('inventory_closes')->insert(['company_id' => $scope['company_id'], 'locked_through' => '2026-08-31', 'action' => 'close', 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs(icoStaff($scope, ['/sell', '/sell/add']));
    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => '2026-08-20']), ['X-Period-Lock-Override' => 'Missed before count'])->assertUnprocessable();

    Sanctum::actingAs(icoStaff($scope, ['/sell', '/sell/add', '/period-lock/override']));
    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => '2026-08-20']), ['X-Period-Lock-Override' => 'Missed before count'])->assertSuccessful();

    $log = AuditLog::query()->where('event', 'inventory_close_override')->firstOrFail();
    expect($log->new_values['reason'])->toBe('Missed before count')->and($log->new_values['closed_through'])->toBe('2026-08-31');
    expect(app(InventoryClose::class)->lockedThrough($scope['company_id']))->toBe('2026-08-31');
});

test('a close moves only forward, a reopen needs a reason, and another company is not locked', function () {
    $scope = seedSellScope();
    $other = icoOtherCompany();
    Sanctum::actingAs(icoStaff($scope, ['/inventoryclose', '/inventoryclose/add', '/inventoryclose/reopen']));

    $this->postJson('/api/inventory-close', ['locked_through' => '2026-08-31'])->assertCreated();
    $this->postJson('/api/inventory-close', ['locked_through' => '2026-08-15'])->assertUnprocessable();
    $this->postJson('/api/inventory-close/reopen', ['locked_through' => '2026-08-15'])->assertUnprocessable();
    $this->postJson('/api/inventory-close/reopen', ['locked_through' => '2026-08-15', 'reason' => 'Recount of 16-31 Aug'])->assertCreated();
    $this->getJson('/api/inventory-close')->assertJsonPath('locked_through', '2026-08-15');
    $this->postJson('/api/inventory-close/reopen', ['reason' => 'Open it all'])->assertCreated();
    $this->getJson('/api/inventory-close')->assertJsonPath('locked_through', null);

    $this->postJson('/api/inventory-close', ['locked_through' => '2026-08-31'])->assertCreated();
    expect(app(InventoryClose::class)->lockedThrough($other['company_id']))->toBeNull();

    Sanctum::actingAs(icoStaff($scope, ['/inventoryclose']));
    $this->postJson('/api/inventory-close', ['locked_through' => '2026-09-30'])->assertForbidden();
});

// ---------------------------------------------------------------------------------------------------------------------
// ABC analysis and stock ageing

test('ABC analysis ranks products by net sales value and classes them A, B and C', function () {
    $scope = seedSellScope();
    $big = prdProduct($scope, 'Big Seller');
    $mid = prdProduct($scope, 'Mid Seller');
    $small = prdProduct($scope, 'Small Seller');
    $today = now()->toDateString();

    foreach ([[$big, 1000], [$mid, 150], [$small, 50]] as [$product, $amount]) {
        $id = DB::table('transactions')->insertGetId([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'], 'invoice_no' => 'ABC-'.$product['product_id'],
            'type' => 'sell', 'status' => 'final', 'payment_status' => 'due', 'transaction_date' => $today, 'final_amount' => $amount, 'total_item' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        prdSellLine($id, $product, 1, $amount);
    }

    Sanctum::actingAs(icoStaff($scope, ['/report/abc-analysis']));
    $response = $this->getJson('/api/reports/abc-analysis')->assertOk();
    $rows = collect($response->json('data.data'))->keyBy('product_name');

    expect($rows['Big Seller']['class'])->toBe('A')->and($rows['Big Seller']['rank'])->toBe(1)
        ->and($rows['Mid Seller']['class'])->toBe('B')
        ->and($rows['Small Seller']['class'])->toBe('C');
});

test('stock ageing lays the stock over the newest receipts first', function () {
    $scope = seedSellScope();
    icoReceive($scope, 10, now()->subDays(200)->toDateString());
    icoReceive($scope, 4, now()->subDays(10)->toDateString());
    createSellRecord($scope, ['invoice_no' => 'AGE-SALE', 'transaction_date' => now()->subDays(5)->toDateString()]);
    DB::table('sell_lines')->orderByDesc('id')->limit(1)->update(['quantity' => 2]);

    Sanctum::actingAs(icoStaff($scope, ['/report/stock-ageing']));
    $rows = collect($this->getJson('/api/reports/stock-ageing')->assertOk()->json('data.data'));
    $row = $rows->firstWhere('product_name', 'Premium Basmati Rice');

    // 14 received, 2 sold: 12 on hand = the 4 recent plus 8 of the old receipt
    expect($row['stock'])->toEqual(12)->and($row['age_0_30'])->toEqual(4)->and($row['age_181_plus'])->toEqual(8);
});

test('the inventory menu migration adds each page once and grants it to companyadmin', function () {
    $admin = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true])->id;

    foreach (['/warehouse' => 'Warehouse', '/report/stock' => 'Stock Report'] as $path => $name) {
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

    $migration = require database_path('migrations/2026_11_05_110000_add_inventory_closeout_menus.php');
    $migration->up();
    $migration->up();

    $paths = ['/stockreservation', '/warehousetask', '/stockwriteoff', '/inventoryclose', '/inventoryclose/add', '/inventoryclose/reopen', '/report/abc-analysis', '/report/stock-ageing'];
    $granted = DB::table('permissions')->join('menus', 'menus.id', '=', 'permissions.menu_id')
        ->where('permissions.role_id', $admin)->whereIn('menus.route_path', $paths)->pluck('menus.route_path')->sort()->values()->all();

    expect($granted)->toBe(collect($paths)->sort()->values()->all());
});
