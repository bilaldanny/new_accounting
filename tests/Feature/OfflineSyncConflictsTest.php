<?php

use App\Models\Contact;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Offline mode, the unhappy paths: a refusal is a conflict that keeps its reason, a conflict is edited and retried or discarded with an
 * audit line, and entries never cross a company or a user.
 */
test('a refusal is kept as a conflict with a code and the server own words, never dropped', function () {
    $scope = offlineWorld();
    Sanctum::actingAs($scope['user']);

    // a locked period
    fcoLockThrough($scope['company_id'], '2026-10-31');
    $locked = $this->postJson('/api/offline/sync', offlineSale($scope))->assertOk()->assertJsonPath('status', 'conflict')->assertJsonPath('conflict_code', 'period_locked');
    expect($locked->json('message'))->not->toBeEmpty();
    DB::table('period_locks')->where('company_id', $scope['company_id'])->delete();

    // a product that was deleted since
    DB::table('products')->where('id', $scope['product_id'])->update(['deleted_at' => now()]);
    $this->postJson('/api/offline/sync', offlineSale($scope))->assertOk()->assertJsonPath('status', 'conflict');
    DB::table('products')->where('id', $scope['product_id'])->update(['deleted_at' => null]);

    // a customer over the credit limit
    Contact::query()->findOrFail($scope['contact_id'])->update(['credit_limit' => 100]);
    $this->postJson('/api/offline/sync', offlineSale($scope))->assertOk()->assertJsonPath('status', 'conflict')->assertJsonPath('conflict_code', 'credit_limit');
    Contact::query()->findOrFail($scope['contact_id'])->update(['credit_limit' => 25000]);

    // stock below zero when the company blocks it
    offlineSetting($scope['company_id'], ['negative_stock_mode' => 'block']);
    $this->postJson('/api/offline/sync', offlineSale($scope))->assertOk()->assertJsonPath('status', 'conflict')->assertJsonPath('conflict_code', 'insufficient_stock');
    offlineSetting($scope['company_id'], ['negative_stock_mode' => 'allow']);

    // a user who has no permission to sell
    Sanctum::actingAs(fcoStaff($scope, ['/offline'], true));
    $this->postJson('/api/offline/sync', offlineSale($scope))->assertOk()->assertJsonPath('status', 'conflict')->assertJsonPath('conflict_code', 'no_permission');

    // none of the refused entries left a sale behind, and every one is kept
    expect(Transaction::query()->sells()->count())->toBe(0)->and(DB::table('offline_sync_entries')->where('status', 'conflict')->count())->toBe(5);
});

test('a sale whose payment is refused is rolled back as one unit', function () {
    $scope = offlineWorld();
    Sanctum::actingAs($scope['user']);

    $this->postJson('/api/offline/sync', offlineSale($scope, ['payload' => ['payment' => ['method' => 'cash', 'payment_account' => 999999, 'amount' => 240]]]))
        ->assertOk()->assertJsonPath('status', 'conflict');

    expect(Transaction::query()->sells()->count())->toBe(0)->and(DB::table('payments')->count())->toBe(0);
});

test('a conflict is edited and retried, or discarded with a reason, a confirmation and an audit line', function () {
    $scope = offlineWorld();
    Sanctum::actingAs($scope['user']);
    DB::table('products')->where('id', $scope['product_id'])->update(['deleted_at' => now()]);
    $id = $this->postJson('/api/offline/sync', offlineSale($scope))->assertOk()->assertJsonPath('status', 'conflict')->json('id');
    DB::table('products')->where('id', $scope['product_id'])->update(['deleted_at' => null]);

    // tried again as it was: the product is back, so it goes through once
    $this->postJson("/api/offline/entries/{$id}/retry")->assertOk()->assertJsonPath('status', 'synced');
    $this->postJson("/api/offline/entries/{$id}/retry")->assertUnprocessable();
    expect(Transaction::query()->sells()->count())->toBe(1);

    // an edited one: a date after the lock and a smaller quantity
    fcoLockThrough($scope['company_id'], '2026-10-31');
    $second = $this->postJson('/api/offline/sync', offlineSale($scope))->assertOk()->assertJsonPath('conflict_code', 'period_locked')->json('id');
    $edited = offlineSale($scope, ['payload' => ['date' => '2026-11-02', 'lines' => [['quantity' => 1]]]])['payload'];
    $this->postJson("/api/offline/entries/{$second}/retry", ['payload' => $edited])->assertOk()->assertJsonPath('status', 'synced');
    expect((float) DB::table('sell_lines')->orderByDesc('id')->value('quantity'))->toBe(1.0);

    // a discard needs the confirmation and a reason, and is audited
    $third = $this->postJson('/api/offline/sync', offlineSale($scope))->assertOk()->assertJsonPath('status', 'conflict')->json('id');
    $this->postJson("/api/offline/entries/{$third}/discard", ['reason' => 'Duplicate of a paper sale'])->assertUnprocessable();
    $this->postJson("/api/offline/entries/{$third}/discard", ['reason' => 'x', 'confirm' => true])->assertUnprocessable();
    $this->postJson("/api/offline/entries/{$third}/discard", ['reason' => 'Duplicate of a paper sale', 'confirm' => true])->assertOk();

    expect(DB::table('offline_sync_entries')->where('id', $third)->value('status'))->toBe('discarded');
    $log = DB::table('audit_logs')->where('event', 'offline_discarded')->first();
    expect($log)->not->toBeNull()->and((int) $log->user_id)->toBe((int) $scope['user']->id)->and((string) $log->new_values)->toContain('Duplicate of a paper sale');
});

test('entries of one company or user are never seen or changed by another', function () {
    $scope = offlineWorld();
    $other = offlineWorld2();
    Sanctum::actingAs($scope['user']);
    DB::table('products')->where('id', $scope['product_id'])->update(['deleted_at' => now()]);
    $id = $this->postJson('/api/offline/sync', offlineSale($scope))->assertOk()->assertJsonPath('status', 'conflict')->json('id');

    // another company
    Sanctum::actingAs($other['user']);
    $this->getJson('/api/offline/entries')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/offline/entries/{$id}/retry")->assertNotFound();
    $this->postJson("/api/offline/entries/{$id}/discard", ['reason' => 'mine now', 'confirm' => true])->assertNotFound();

    // a colleague without the resolve permission
    Sanctum::actingAs(fcoStaff($scope, OFFLINE_PERMS, true));
    $this->getJson('/api/offline/entries')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/offline/entries/{$id}/retry")->assertForbidden();

    // a supervisor with it sees and can decide
    Sanctum::actingAs(fcoStaff($scope, [...OFFLINE_PERMS, '/offline/resolve'], true));
    $this->getJson('/api/offline/entries')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/offline/entries/{$id}/discard", ['reason' => 'Product withdrawn', 'confirm' => true])->assertOk();
});

test('the entry size and the number of calls are limited, and the heartbeat is public', function () {
    $scope = offlineWorld();

    $this->getJson('/api/ping')->assertOk()->assertJsonPath('ok', true);
    $this->postJson('/api/offline/sync', offlineSale($scope))->assertUnauthorized();

    Sanctum::actingAs($scope['user']);
    $big = offlineSale($scope);
    $big['payload']['note'] = str_repeat('x', 270000);
    $this->postJson('/api/offline/sync', $big)->assertStatus(413);
    $this->postJson('/api/offline/sync', ['client_uuid' => 'abc', 'kind' => 'nonsense', 'payload' => []])->assertUnprocessable();
    expect(DB::table('offline_sync_entries')->count())->toBe(0);
});

test('the reference data is the signed-in users own company and branch, read only', function () {
    $scope = offlineWorld();
    $other = offlineWorld2();
    Sanctum::actingAs($scope['user']);

    $data = $this->getJson('/api/offline/reference')->assertOk()->json('data');
    expect($data['company_id'])->toBe((int) $scope['company_id'])->and(collect($data['products'])->pluck('product_id')->all())->toContain((int) $scope['product_id'])
        ->and(collect($data['contacts'])->pluck('id')->all())->not->toContain((int) $other['contact_id'])
        ->and(collect($data['payment_accounts'])->pluck('id')->all())->toContain((int) $scope['cash_id'])
        ->and($data['allowed']['sale'])->toBe(['/sell/add'])->and($data)->toHaveKeys(['balances_read_at', 'generated_at', 'open_invoices', 'units', 'methods']);

    Sanctum::actingAs(fcoStaff($scope, ['/dashboard'], true));
    $this->getJson('/api/offline/reference')->assertForbidden();
});

/**
 * A second, separate company with its own contact and user.
 *
 * @return array<string, mixed>
 */
function offlineWorld2(): array
{
    $company = DB::table('companies')->insertGetId(['code' => 'OFF2', 'name' => 'Other Offline Co', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branch = DB::table('branches')->insertGetId(['code' => 'OFFB2', 'company_id' => $company, 'name' => 'Other Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $contact = Contact::query()->create(['company_id' => $company, 'branch_id' => $branch, 'business_name' => 'Other Customer', 'first_name' => 'Zed', 'mobile' => '0300999', 'address' => 'x', 'code' => 'CU-OFF2', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'active' => true]);
    $scope = ['company_id' => $company, 'branch_id' => $branch, 'contact_id' => $contact->id, 'product_id' => 1, 'variation_id' => 1, 'unit_id' => 1];

    return $scope + ['user' => fcoStaff($scope, OFFLINE_PERMS, true)];
}
