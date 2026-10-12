<?php

use App\Models\Project;
use App\Models\User;
use App\Services\Approvals\ApprovalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 8, the last advanced modules: project milestones and tasks, employee expense claims (approval, then payment through a voucher)
 * and the e-commerce storefront (public, tenant scoped, rate limited, orders kept apart from the books until staff convert them).
 */
function w8bStaff(array $scope, array $paths): User
{
    return fcoStaff($scope, $paths, false);
}

function w8bProject(array $scope, string $code = 'PRJ-1'): int
{
    return (int) Project::query()->create(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'code' => $code, 'name' => 'Warehouse fit-out', 'budget_revenue' => 100000, 'budget_cost' => 70000, 'status' => 'active'])->id;
}

// ---------------------------------------------------------------------------------------------------------------------
// project milestones and tasks

test('a project gets milestones and tasks with progress and billing figures, and its budget and margin come from the project costing', function () {
    $scope = fcoScope();
    $project = w8bProject($scope);
    $worker = w8bStaff($scope, ['/project/work']);
    Sanctum::actingAs(w8bStaff($scope, ['/project/work', '/project/work/manage']));

    $first = (int) $this->postJson("/api/project-work/{$project}/milestones", ['name' => 'Racking installed', 'due_date' => '2026-09-01', 'amount' => 40000])->assertCreated()->json('data.id');
    $this->postJson("/api/project-work/{$project}/milestones", ['name' => 'Handover', 'due_date' => '2026-12-01', 'amount' => 60000])->assertCreated();
    $task = (int) $this->postJson("/api/project-work/{$project}/tasks", ['title' => 'Mark the floor', 'milestone_id' => $first, 'assignee_id' => $worker->id, 'estimated_hours' => 8])->assertCreated()->json('data.id');
    $this->postJson("/api/project-work/{$project}/tasks", ['title' => 'Fix beams', 'milestone_id' => $first, 'estimated_hours' => 16])->assertCreated();

    $this->postJson("/api/project-work/{$project}/milestones/{$first}/status", ['status' => 'billed'])->assertUnprocessable();
    $this->deleteJson("/api/project-work/{$project}/milestones/{$first}")->assertUnprocessable();

    // the assigned user moves the task and logs hours with the work permission only
    Sanctum::actingAs($worker);
    $this->postJson("/api/project-work/{$project}/tasks/{$task}/status", ['status' => 'done', 'add_hours' => 7.5])->assertOk();
    $this->postJson("/api/project-work/{$project}/tasks", ['title' => 'Nope'])->assertForbidden();

    Sanctum::actingAs(w8bStaff($scope, ['/project/work', '/project/work/manage']));
    $this->postJson("/api/project-work/{$project}/milestones/{$first}/status", ['status' => 'done'])->assertOk();
    $data = $this->getJson("/api/project-work/{$project}")->assertOk()->json('data');

    expect($data['progress']['percent'])->toEqual(50)->and($data['progress']['spent_hours'])->toEqual(7.5)->and($data['progress']['estimated_hours'])->toEqual(24)
        ->and($data['billing']['planned'])->toEqual(100000)->and($data['billing']['done_not_billed'])->toEqual(40000)->and($data['billing']['budget_revenue'])->toEqual(100000)
        ->and($data['milestones'][0]['tasks_done'])->toBe(1)->and($data['milestones'][0]['overdue'])->toBeFalse()->and($data['financials']['code'] ?? null)->toBe('PRJ-1');

    $this->postJson("/api/project-work/{$project}/milestones/{$first}/status", ['status' => 'billed', 'billing_note' => 'INV-77'])->assertOk();
    expect($this->getJson("/api/project-work/{$project}")->json('data.billing.billed'))->toEqual(40000);
});

test('project work of another company is not reachable and tasks only take users and milestones of the project', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $theirs = w8bProject($other, 'PRJ-X');
    $mine = w8bProject($scope);
    $mineSecond = w8bProject($scope, 'PRJ-2');
    $foreignUser = w8bStaff($other, ['/project/work']);
    Sanctum::actingAs(w8bStaff($scope, ['/project/work', '/project/work/manage']));

    $this->getJson("/api/project-work/{$theirs}")->assertNotFound();
    $this->postJson("/api/project-work/{$theirs}/milestones", ['name' => 'x'])->assertNotFound();
    $this->postJson("/api/project-work/{$theirs}/tasks", ['title' => 'x'])->assertNotFound();

    $milestone = (int) $this->postJson("/api/project-work/{$mineSecond}/milestones", ['name' => 'Elsewhere'])->assertCreated()->json('data.id');
    $this->postJson("/api/project-work/{$mine}/tasks", ['title' => 'x', 'assignee_id' => $foreignUser->id])->assertUnprocessable();
    $this->postJson("/api/project-work/{$mine}/tasks", ['title' => 'x', 'milestone_id' => $milestone])->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// expense claims

function w8bClaim(array $scope, array $extra = []): array
{
    return $extra + ['title' => 'Client visit', 'claim_date' => '2026-10-05', 'lines' => [
        ['expense_date' => '2026-10-04', 'account_id' => $scope['rent_id'], 'description' => 'Taxi', 'amount' => 1200],
        ['expense_date' => '2026-10-04', 'account_id' => $scope['insurance_id'], 'description' => 'Lunch', 'amount' => 800],
        ['expense_date' => '2026-10-05', 'account_id' => $scope['rent_id'], 'description' => 'Parking', 'amount' => 300],
    ]];
}

test('a claim is filed, submitted, approved by someone else and paid through one balanced expense voucher', function () {
    Storage::fake('local');
    $scope = fcoScope();
    $claimant = w8bStaff($scope, ['/expenseclaim', '/expenseclaim/add']);
    $approver = w8bStaff($scope, ['/expenseclaim', '/expenseclaim/approve']);
    $payer = w8bStaff($scope, ['/expenseclaim', '/expenseclaim/pay']);
    $books = fn () => [DB::table('t_accounts')->where('company_id', $scope['company_id'])->count(), fcoTrialBalance($scope['company_id'])];
    $before = $books();

    Sanctum::actingAs($claimant);
    $id = (int) $this->postJson('/api/expense-claims', w8bClaim($scope))->assertCreated()->json('data.id');
    expect((float) DB::table('expense_claims')->where('id', $id)->value('total'))->toBe(2300.0)->and(DB::table('expense_claims')->where('id', $id)->value('claim_no'))->toBe('EC-00001');

    $this->post("/api/expense-claims/{$id}/receipts", ['file' => UploadedFile::fake()->create('taxi.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
    $this->post("/api/expense-claims/{$id}/receipts", ['file' => UploadedFile::fake()->create('bad.exe', 5, 'application/x-msdownload')], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->postJson("/api/expense-claims/{$id}/approve")->assertForbidden();
    $this->postJson("/api/expense-claims/{$id}/submit")->assertOk();
    $this->putJson("/api/expense-claims/{$id}", w8bClaim($scope))->assertUnprocessable();

    // the claimant cannot approve even with the permission; the approver can; a payer cannot approve
    Sanctum::actingAs($payer);
    $this->postJson("/api/expense-claims/{$id}/approve")->assertForbidden();
    $this->postJson("/api/expense-claims/{$id}/pay", ['payment_account_id' => $scope['cash_id']])->assertUnprocessable();

    Sanctum::actingAs($approver);
    $this->postJson("/api/expense-claims/{$id}/approve", ['note' => 'Fine'])->assertOk();
    expect($books())->toBe($before);

    Sanctum::actingAs($claimant);
    $this->postJson("/api/expense-claims/{$id}/pay", ['payment_account_id' => $scope['cash_id']])->assertForbidden();

    Sanctum::actingAs($payer);
    $voucher = (int) $this->postJson("/api/expense-claims/{$id}/pay", ['payment_account_id' => $scope['cash_id'], 'paid_on' => '2026-10-06'])->assertOk()->json('data.voucher_id');
    $this->postJson("/api/expense-claims/{$id}/pay", ['payment_account_id' => $scope['cash_id']])->assertUnprocessable();

    expect(DB::table('expense_claims')->where('id', $id)->value('status'))->toBe('paid')->and(fcoBalance($scope['rent_id']))->toBe(1500.0)->and(fcoBalance($scope['insurance_id']))->toBe(800.0)
        ->and(fcoBalance($scope['cash_id']))->toBe(-2300.0)->and(DB::table('t_account_details')->where('t_account_id', $voucher)->count())->toBe(3)
        ->and(fcoTrialBalance($scope['company_id'])['debit'])->toBe(fcoTrialBalance($scope['company_id'])['credit']);
});

test('a rejected claim cannot be paid, a locked month refuses the payment, and claims stay with their owner and company', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $claimant = w8bStaff($scope, ['/expenseclaim', '/expenseclaim/add']);
    $second = w8bStaff($scope, ['/expenseclaim', '/expenseclaim/add']);
    $both = w8bStaff($scope, ['/expenseclaim', '/expenseclaim/approve', '/expenseclaim/pay']);

    Sanctum::actingAs($claimant);
    $rejected = (int) $this->postJson('/api/expense-claims', w8bClaim($scope))->assertCreated()->json('data.id');
    $locked = (int) $this->postJson('/api/expense-claims', w8bClaim($scope, ['title' => 'Trip']))->assertCreated()->json('data.id');
    $this->postJson("/api/expense-claims/{$rejected}/submit")->assertOk();
    $this->postJson("/api/expense-claims/{$locked}/submit")->assertOk();

    // another user of the company does not see them, nor a user of another company
    Sanctum::actingAs($second);
    expect($this->getJson('/api/expense-claims')->json('data'))->toBe([]);
    $this->getJson("/api/expense-claims/{$rejected}")->assertNotFound();
    $this->postJson("/api/expense-claims/{$rejected}/cancel")->assertNotFound();
    Sanctum::actingAs(w8bStaff($other, ['/expenseclaim', '/expenseclaim/approve']));
    $this->postJson("/api/expense-claims/{$rejected}/approve")->assertNotFound();

    Sanctum::actingAs($both);
    expect(count($this->getJson('/api/expense-claims')->json('data')))->toBe(2);
    $this->postJson("/api/expense-claims/{$rejected}/reject", ['note' => 'No receipt'])->assertOk();
    $this->postJson("/api/expense-claims/{$rejected}/pay", ['payment_account_id' => $scope['cash_id']])->assertUnprocessable();

    $this->postJson("/api/expense-claims/{$locked}/approve")->assertOk();
    fcoLockThrough($scope['company_id'], '2026-10-31');
    $this->postJson("/api/expense-claims/{$locked}/pay", ['payment_account_id' => $scope['cash_id'], 'paid_on' => '2026-10-06'])->assertUnprocessable();
    expect(DB::table('expense_claims')->where('id', $locked)->value('status'))->toBe('approved')->and(DB::table('expense_claims')->where('id', $locked)->value('voucher_id'))->toBeNull();

    // a non-expense account and a foreign account are refused on the claim itself
    Sanctum::actingAs($claimant);
    $this->postJson('/api/expense-claims', w8bClaim($scope, ['lines' => [['expense_date' => '2026-10-04', 'account_id' => $scope['cash_id'], 'description' => 'x', 'amount' => 5]]]))->assertUnprocessable();
});

test('an approval route of the claims is covered by maker-checker and the Approval Center registry', function () {
    $scope = fcoScope();
    DB::table('security_settings')->insert(['company_id' => $scope['company_id'], 'maker_checker' => 1, 'policy_enabled' => 0, 'expiry_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
    $both = w8bStaff($scope, ['/expenseclaim', '/expenseclaim/add', '/expenseclaim/approve']);
    Sanctum::actingAs($both);

    $id = (int) $this->postJson('/api/expense-claims', w8bClaim($scope))->assertCreated()->json('data.id');
    $this->postJson("/api/expense-claims/{$id}/submit")->assertOk();
    $this->postJson("/api/expense-claims/{$id}/approve")->assertUnprocessable();

    expect(ApprovalRegistry::forPrefix('expense-claims')['type'])->toBe('expenseclaim');
});

// ---------------------------------------------------------------------------------------------------------------------
// storefront

function w8bShop(array $scope, string $code = 'acme-shop'): array
{
    $first = prdProduct($scope, 'Desk lamp', [], ['default_purchase_price' => 5, 'default_sell_price' => 20]);
    $second = prdProduct($scope, 'Hidden item', [], ['default_purchase_price' => 5, 'default_sell_price' => 30]);

    test()->putJson('/api/storefront-admin/settings', ['code' => $code, 'enabled' => true, 'title' => 'Acme shop', 'default_contact_id' => $scope['contact_id']])->assertOk();
    test()->postJson('/api/storefront-admin/products', ['product_id' => $first['product_id'], 'price' => 25, 'is_published' => true])->assertOk();
    test()->postJson('/api/storefront-admin/products', ['product_id' => $second['product_id'], 'is_published' => false])->assertOk();

    return ['lamp' => $first, 'hidden' => $second];
}

test('the public shop lists only published products of the shop that is switched on, and takes an order priced by the server', function () {
    $scope = fcoScope();
    Sanctum::actingAs(w8bStaff($scope, ['/storefront', '/storefront/manage']));
    $products = w8bShop($scope);
    $sales = DB::table('transactions')->where('company_id', $scope['company_id'])->count();

    app('auth')->forgetGuards();
    $catalog = $this->getJson('/api/storefront/acme-shop')->assertOk()->json('data');
    expect($catalog['title'])->toBe('Acme shop')->and(collect($catalog['products'])->pluck('name')->all())->toBe(['Desk lamp'])->and($catalog['products'][0]['price'])->toEqual(25)
        ->and(json_encode($catalog))->not->toContain('cost')->not->toContain('purchase');

    $order = ['name' => 'Walk <b>In</b>', 'phone' => '0300-1234567', 'address' => 'House 5', 'lines' => [['product_id' => $products['lamp']['product_id'], 'variation_id' => $products['lamp']['variation_id'], 'quantity' => 2, 'unit_price' => 1, 'price' => 1]]];
    $placed = $this->postJson('/api/storefront/acme-shop/orders', $order)->assertCreated()->json('data');

    expect($placed['total'])->toEqual(50)->and(DB::table('storefront_orders')->value('customer_name'))->toBe('Walk In')->and(DB::table('transactions')->where('company_id', $scope['company_id'])->count())->toBe($sales);

    // not on sale, a bad quantity, a bad phone, and the honeypot: nothing is stored
    $count = DB::table('storefront_orders')->count();
    $hidden = $order;
    $hidden['lines'][0] = ['product_id' => $products['hidden']['product_id'], 'variation_id' => $products['hidden']['variation_id'], 'quantity' => 1];
    $this->postJson('/api/storefront/acme-shop/orders', $hidden)->assertStatus(422);
    $this->postJson('/api/storefront/acme-shop/orders', ['lines' => [['product_id' => $products['lamp']['product_id'], 'variation_id' => $products['lamp']['variation_id'], 'quantity' => 5000]]] + $order)->assertStatus(422);
    $this->postJson('/api/storefront/acme-shop/orders', ['phone' => 'call me'] + $order)->assertStatus(422);
    $this->postJson('/api/storefront/acme-shop/orders', ['website_url' => 'http://spam.test'] + $order)->assertOk();
    expect(DB::table('storefront_orders')->count())->toBe($count);

    $this->getJson('/api/storefront/no-such-shop')->assertNotFound();
});

test('the shop is off until enabled, codes are unique across companies, the order endpoint is rate limited and shops stay apart', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    Sanctum::actingAs(w8bStaff($other, ['/storefront', '/storefront/manage']));
    $this->putJson('/api/storefront-admin/settings', ['code' => 'other-shop', 'enabled' => true])->assertOk();

    Sanctum::actingAs(w8bStaff($scope, ['/storefront', '/storefront/manage']));
    $this->putJson('/api/storefront-admin/settings', ['code' => 'other-shop', 'enabled' => true])->assertUnprocessable();
    $this->putJson('/api/storefront-admin/settings', ['code' => 'Bad Code!'])->assertUnprocessable();
    $products = w8bShop($scope, 'acme-two');

    // switched off: not found
    $this->putJson('/api/storefront-admin/settings', ['code' => 'acme-two', 'enabled' => false, 'default_contact_id' => $scope['contact_id']])->assertOk();
    app('auth')->forgetGuards();
    $this->getJson('/api/storefront/acme-two')->assertNotFound();
    expect($this->getJson('/api/storefront/other-shop')->assertOk()->json('data.products'))->toBe([]);

    // an item of this company cannot be ordered through the other shop
    $line = ['product_id' => $products['lamp']['product_id'], 'variation_id' => $products['lamp']['variation_id'], 'quantity' => 1];
    $this->postJson('/api/storefront/other-shop/orders', ['name' => 'Bob', 'phone' => '0300', 'lines' => [$line]])->assertStatus(422);

    // rate limit: 20 orders a minute
    Sanctum::actingAs(w8bStaff($scope, ['/storefront/manage']));
    $this->putJson('/api/storefront-admin/settings', ['code' => 'acme-two', 'enabled' => true])->assertOk();
    app('auth')->forgetGuards();
    $statuses = [];

    for ($i = 0; $i < 22; $i++) {
        $statuses[] = $this->postJson('/api/storefront/acme-two/orders', ['name' => 'Bob', 'phone' => '0300', 'lines' => [$line]])->status();
    }

    expect($statuses[0])->toBe(201)->and(in_array(429, $statuses, true))->toBeTrue();
});

test('staff turn a web order into a draft sale or reject it, only inside their company', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    Sanctum::actingAs(w8bStaff($scope, ['/storefront', '/storefront/manage']));
    $products = w8bShop($scope);
    app('auth')->forgetGuards();
    $line = ['product_id' => $products['lamp']['product_id'], 'variation_id' => $products['lamp']['variation_id'], 'quantity' => 3];
    $this->postJson('/api/storefront/acme-shop/orders', ['name' => 'Bob', 'phone' => '0300', 'lines' => [$line]])->assertCreated();
    $this->postJson('/api/storefront/acme-shop/orders', ['name' => 'Eve', 'phone' => '0301', 'lines' => [$line]])->assertCreated();
    $orderOne = (int) DB::table('storefront_orders')->orderBy('id')->value('id');
    $orderTwo = (int) DB::table('storefront_orders')->orderByDesc('id')->value('id');

    Sanctum::actingAs(w8bStaff($other, ['/storefront', '/storefront/manage']));
    $this->postJson("/api/storefront-admin/orders/{$orderOne}/convert")->assertNotFound();
    $this->postJson("/api/storefront-admin/orders/{$orderOne}/reject")->assertNotFound();
    expect($this->getJson('/api/storefront-admin/orders')->json('data'))->toBe([]);

    Sanctum::actingAs(w8bStaff($scope, ['/storefront']));
    $this->postJson("/api/storefront-admin/orders/{$orderOne}/convert")->assertForbidden();

    Sanctum::actingAs(w8bStaff($scope, ['/storefront', '/storefront/manage']));
    $saleId = (int) $this->postJson("/api/storefront-admin/orders/{$orderOne}/convert")->assertCreated()->json('data.sale_id');
    $sale = DB::table('transactions')->where('id', $saleId)->first();

    expect($sale->status)->toBe('draft')->and((float) $sale->final_amount)->toBe(75.0)->and((int) $sale->company_id)->toBe($scope['company_id'])
        ->and(DB::table('storefront_orders')->where('id', $orderOne)->value('status'))->toBe('converted');
    $this->postJson("/api/storefront-admin/orders/{$orderOne}/convert")->assertUnprocessable();
    $this->postJson("/api/storefront-admin/orders/{$orderTwo}/reject")->assertOk();
    $this->postJson("/api/storefront-admin/orders/{$orderTwo}/convert")->assertUnprocessable();
});
