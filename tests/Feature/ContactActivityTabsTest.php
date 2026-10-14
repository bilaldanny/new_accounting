<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The tabs of a customer or supplier page besides the ledger: their documents with what was paid, the payments, the products traded, and
 * the files attached to those documents.
 */
function catScope(): array
{
    $scope = seedSellScope();
    $sale = createSellRecord($scope, ['invoice_no' => 'CAT-1', 'final_amount' => 1000, 'transaction_date' => '2026-09-10', 'attachment' => 'signed-invoice.pdf']);
    createSellRecord($scope, ['invoice_no' => 'CAT-2', 'final_amount' => 400, 'transaction_date' => '2026-09-12']);
    DB::table('payments')->insert([
        'transaction_id' => $sale->id, 'contact_id' => $scope['contact_id'], 'amount' => 600, 'method' => 'cash', 'paid_on' => '2026-09-11', 'is_return' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $scope + ['sale_id' => $sale->id];
}

test('a party lists its documents with paid and due, its payments and its products', function () {
    $scope = catScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $orders = $this->getJson('/api/contacts/'.$scope['contact_id'].'/orders')->assertOk();
    expect($orders->json('summary.count'))->toBe(2)->and((float) $orders->json('summary.total'))->toBe(1400.0)
        ->and((float) $orders->json('summary.paid'))->toBe(600.0)->and((float) $orders->json('summary.due'))->toBe(800.0);

    $first = collect($orders->json('data'))->firstWhere('invoice_no', 'CAT-1');
    expect($first['payment_status'])->toBe('due')->and((float) $first['due'])->toBe(400.0)->and($first['has_attachment'])->toBeTrue()
        ->and($first['view_url'])->toBe('/sell/'.$scope['sale_id'].'/view');

    $payments = $this->getJson('/api/contacts/'.$scope['contact_id'].'/payments')->assertOk();
    expect($payments->json('summary.count'))->toBe(1)->and((float) $payments->json('summary.total'))->toBe(600.0)->and($payments->json('data.0.invoice_no'))->toBe('CAT-1');

    $stock = $this->getJson('/api/contacts/'.$scope['contact_id'].'/stock')->assertOk();
    expect($stock->json('kind'))->toBe('sold')->and($stock->json('summary.products'))->toBe(1)->and((float) $stock->json('data.0.quantity'))->toBe(2.0);
});

test('the files attached to the sales of a party are listed with a link', function () {
    $scope = catScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $files = collect($this->getJson('/api/contacts/'.$scope['contact_id'].'/document-attachments')->assertOk()->json('data'));

    expect($files)->toHaveCount(1)->and($files[0]['invoice_no'])->toBe('CAT-1')->and($files[0]['document'])->toBe('Sale')
        ->and($files[0]['file_name'])->toBe('signed-invoice.pdf')->and($files[0]['url'])->toContain('signed-invoice.pdf')->and($files[0]['is_image'])->toBeFalse();
});

test('a user reaches only the parties of their own company and only with a customer or supplier menu', function () {
    $scope = catScope();
    $otherCompany = DB::table('companies')->insertGetId(['code' => 'CAT-OTHER', 'name' => 'Other', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $role = Role::query()->create(['name' => 'viewer'.uniqid(), 'company_id' => $otherCompany, 'is_active' => true]);
    grantMenuPermission($role->id, '/customer', 'customer'.uniqid());
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $otherCompany]));

    foreach (['orders', 'payments', 'stock', 'document-attachments'] as $path) {
        $this->getJson('/api/contacts/'.$scope['contact_id'].'/'.$path)->assertNotFound();
    }

    $bare = Role::query()->create(['name' => 'bare'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($bare, ['company_id' => $scope['company_id']]));
    $this->getJson('/api/contacts/'.$scope['contact_id'].'/orders')->assertForbidden();
});
