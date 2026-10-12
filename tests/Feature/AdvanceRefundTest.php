<?php

use App\Models\CashCollection;
use App\Models\CompanySetting;
use App\Models\Payment;
use App\Models\PeriodLockEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Task A of the final closeout: a customer advance (cash collection) and a supplier advance (supplier payment) behave the same
 * way: kept, used on documents, paid back, reversed. The refund is the piece the supplier side did not have.
 */
function arfStaff(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'arf_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
}

function arfSupplierAdvance(array $scope, int $cash, float $amount = 100): array
{
    return test()->postJson('/api/supplier-payments', [
        'contact_id' => $scope['contact_id'], 'paid_on' => '2026-09-10', 'payment_account' => $cash, 'method' => 'cash', 'amount' => $amount, 'keep_advance' => true,
    ])->assertCreated()->json('data');
}

test('a supplier advance is paid back in part, the balance follows and reversing the payment removes the refund', function () {
    $scope = seedPurchaseScope();
    $cash = insertPurchaseChartAccount($scope, '101-09101', 'ARF Cash', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cash);
    $bill = createPurchaseRecord($scope, ['final_amount' => 50, 'invoice_no' => 'ARF-B1']);
    Sanctum::actingAs(arfStaff($scope, ['/supplierpayment', '/supplierpayment/add', '/supplierpayment/reverse', '/supplierpayment/refund']));

    $batch = arfSupplierAdvance($scope, $cash);

    $refund = $this->postJson("/api/supplier-payments/{$batch['id']}/refund-advance", ['refunded_on' => '2026-09-15', 'amount' => 30, 'payment_account' => $cash])->assertCreated();
    expect($refund->json('advance_remaining'))->toEqual(70);

    // cash was debited 30 by the refund and credited 100 by the advance
    expect((float) DB::table('t_account_details')->where('coa_id', $cash)->sum('debit'))->toBe(30.0)
        ->and((float) DB::table('t_account_details')->where('coa_id', $cash)->sum('credit'))->toBe(100.0);

    $this->postJson("/api/supplier-payments/{$batch['id']}/refund-advance", ['refunded_on' => '2026-09-16', 'amount' => 80, 'payment_account' => $cash])->assertUnprocessable();
    $this->postJson("/api/supplier-payments/{$batch['id']}/apply-advance", ['paid_on' => '2026-09-17', 'allocations' => [['transaction_id' => $bill->id, 'amount' => 50]]])->assertOk();
    $this->postJson("/api/supplier-payments/{$batch['id']}/apply-advance", ['paid_on' => '2026-09-17', 'allocations' => [['transaction_id' => $bill->id, 'amount' => 50]]])->assertUnprocessable();

    $this->postJson("/api/supplier-payments/{$batch['id']}/reverse")->assertOk();

    expect(Payment::query()->count())->toBe(0)
        ->and(DB::table('advance_refunds')->count())->toBe(0)
        ->and((float) DB::table('t_account_details')->where('coa_id', $cash)->sum('debit'))->toBe(0.0);
});

test('a supplier advance refund needs its permission, a batch of the own company and respects the period lock', function () {
    $scope = seedPurchaseScope();
    $otherCompany = DB::table('companies')->insertGetId(['code' => 'ARF2', 'name' => 'Other Co', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $other = ['company_id' => $otherCompany, 'branch_id' => DB::table('branches')->insertGetId(['code' => 'ARFB2', 'company_id' => $otherCompany, 'name' => 'Other Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()])];
    $cash = insertPurchaseChartAccount($scope, '101-09102', 'ARF Cash 2', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cash);
    Sanctum::actingAs(arfStaff($scope, ['/supplierpayment', '/supplierpayment/add']));
    $batch = arfSupplierAdvance($scope, $cash);

    // no refund permission
    $this->postJson("/api/supplier-payments/{$batch['id']}/refund-advance", ['refunded_on' => '2026-09-15', 'amount' => 10, 'payment_account' => $cash])->assertForbidden();

    // another company's user cannot reach the batch, whatever company_id they send
    Sanctum::actingAs(arfStaff($other, ['/supplierpayment', '/supplierpayment/refund']));
    $this->postJson("/api/supplier-payments/{$batch['id']}/refund-advance", ['refunded_on' => '2026-09-15', 'amount' => 10, 'payment_account' => $cash, 'company_id' => $scope['company_id']])->assertNotFound();

    // a locked period refuses the refund and nothing is left behind
    PeriodLockEntry::query()->create(['company_id' => $scope['company_id'], 'locked_through' => '2026-09-30', 'action' => 'lock', 'locked_by' => 1]);
    Sanctum::actingAs(arfStaff($scope, ['/supplierpayment', '/supplierpayment/refund']));
    $this->postJson("/api/supplier-payments/{$batch['id']}/refund-advance", ['refunded_on' => '2026-09-20', 'amount' => 10, 'payment_account' => $cash])->assertStatus(422);

    expect(DB::table('advance_refunds')->count())->toBe(0);
});

test('a customer advance kept by a cash collection is paid back, and reversing the collection removes the refund', function () {
    $scope = seedSellScope();
    $cash = insertPurchaseChartAccount($scope, '111-09101', 'ARF Customer Cash', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cash);
    (CompanySetting::query()->where('company_id', $scope['company_id'])->first() ?? CompanySetting::createCompanySettings((int) $scope['company_id']))
        ->forceFill(['cash_collection' => true])->save();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $collection = CashCollection::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['customer_id'],
        'reference' => CashCollection::nextReference($scope['company_id']), 'collected_on' => '2026-09-10', 'amount' => 100, 'status' => 'pending',
    ]);
    $this->postJson("/api/cash-collections/{$collection->id}/complete", ['payment_account' => $cash, 'allocations' => [], 'keep_advance' => true])->assertSuccessful();
    expect($collection->fresh()->advanceRemaining())->toEqual(100.0);

    $this->postJson("/api/cash-collections/{$collection->id}/refund-advance", ['refunded_on' => '2026-09-12', 'amount' => 40, 'payment_account' => $cash])
        ->assertCreated()->assertJsonPath('advance_remaining', 60);
    $this->postJson("/api/cash-collections/{$collection->id}/refund-advance", ['refunded_on' => '2026-09-12', 'amount' => 61, 'payment_account' => $cash])->assertUnprocessable();

    expect((float) DB::table('t_account_details')->where('coa_id', $cash)->sum('credit'))->toBe(40.0);

    $this->postJson("/api/cash-collections/{$collection->id}/reverse")->assertSuccessful();

    expect(DB::table('advance_refunds')->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});
