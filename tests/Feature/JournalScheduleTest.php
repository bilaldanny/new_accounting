<?php

use App\Models\JournalSchedule;
use App\Models\PeriodLockEntry;
use App\Models\TAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Task C, items 1-4: recurring and reversing journals, recurring expenses, prepaid / deferred amortization and accruals with
 * automatic reversal, all through the journal schedule engine.
 */
const JS_PERMS = ['/journalschedule', '/journalschedule/add', '/journalschedule/:id/edit', '/journalschedule/delete', '/journalschedule/run'];

function jsRecurring(array $scope, array $extra = []): array
{
    return $extra + [
        'kind' => 'recurring', 'name' => 'Office rent', 'frequency' => 'monthly', 'start_date' => '2026-08-31',
        'lines' => [
            ['account_id' => $scope['rent_id'], 'debit' => 500, 'credit' => 0],
            ['account_id' => $scope['cash_id'], 'debit' => 0, 'credit' => 500],
        ],
    ];
}

test('with no schedule nothing is posted and a recurring journal posts each due month once, whatever the number of runs', function () {
    $scope = fcoScope();
    Sanctum::actingAs(fcoStaff($scope, JS_PERMS));
    $vouchers = TAccount::query()->count();

    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-10-12'])->assertOk()->assertJsonPath('data.posted', 0);
    expect(TAccount::query()->count())->toBe($vouchers);

    $this->postJson('/api/journal-schedules', jsRecurring($scope))->assertCreated();

    $first = $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-10-12'])->assertOk();
    expect($first->json('data.posted'))->toBe(2)->and($first->json('data.total'))->toEqual(1000);

    $again = $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-10-12'])->assertOk();
    expect($again->json('data.posted'))->toBe(0)
        ->and(DB::table('journal_schedule_runs')->where('kind', 'post')->count())->toBe(2)
        ->and(DB::table('t_accounts')->whereDate('voucher_date', '2026-09-30')->count())->toBe(1)
        ->and(fcoBalance($scope['rent_id']))->toBe(1000.0)
        ->and(fcoTrialBalance($scope['company_id']))->toBe(['debit' => 1000.0, 'credit' => 1000.0]);

    // the run reaches the next month-end only when it comes
    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-10-31'])->assertOk()->assertJsonPath('data.posted', 1);
});

test('a schedule set to draft for approval leaves its vouchers pending until someone approves them', function () {
    $scope = fcoScope();
    Sanctum::actingAs(fcoStaff($scope, JS_PERMS));
    $this->postJson('/api/journal-schedules', jsRecurring($scope, ['auto_post' => false]))->assertCreated();

    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-09-30'])->assertOk()->assertJsonPath('data.posted', 2);

    expect(TAccount::query()->where('status', 'pending')->count())->toBe(2)
        ->and(fcoBalance($scope['rent_id']))->toBe(0.0)
        ->and(fcoTrialBalance($scope['company_id']))->toBe(['debit' => 0.0, 'credit' => 0.0]);
});

test('an accrual is posted at month end and reversed on the first day of the next month, linked both ways', function () {
    $scope = fcoScope();
    Sanctum::actingAs(fcoStaff($scope, JS_PERMS));
    $this->postJson('/api/journal-schedules', [
        'kind' => 'accrual', 'name' => 'Utilities', 'frequency' => 'monthly', 'start_date' => '2026-08-31', 'max_runs' => 1,
        'meta' => ['amount' => 300, 'expense_account_id' => $scope['rent_id'], 'liability_account_id' => $scope['accrued_id']],
    ])->assertCreated()->assertJsonPath('data.auto_reverse', true);

    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-08-31'])->assertOk()->assertJsonPath('data.posted', 1)->assertJsonPath('data.reversed', 0);
    expect(fcoBalance($scope['accrued_id']))->toBe(-300.0);

    $run = $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-09-01'])->assertOk();
    expect($run->json('data.reversed'))->toBe(1);

    $original = TAccount::query()->whereDate('voucher_date', '2026-08-31')->firstOrFail();
    $reversal = TAccount::query()->whereDate('voucher_date', '2026-09-01')->firstOrFail();

    expect((int) $original->reversed_by_id)->toBe((int) $reversal->id)
        ->and((int) $reversal->reversal_of_id)->toBe((int) $original->id)
        ->and($reversal->status)->toBe('approved')
        ->and(fcoBalance($scope['accrued_id']))->toBe(0.0)
        ->and(fcoBalance($scope['rent_id']))->toBe(0.0);

    // running again does not reverse twice
    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-10-31'])->assertOk()->assertJsonPath('data.reversed', 0);
    expect(TAccount::query()->count())->toBe(2);
});

test('a reversal waits for the original to be approved', function () {
    $scope = fcoScope(autoApprove: false);
    Sanctum::actingAs(fcoStaff($scope, JS_PERMS));
    $this->postJson('/api/journal-schedules', jsRecurring($scope, ['kind' => 'reversing', 'name' => 'Provision', 'max_runs' => 1]))->assertCreated();

    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-09-05'])->assertOk()->assertJsonPath('data.posted', 1)->assertJsonPath('data.reversed', 0);
    $original = TAccount::query()->firstOrFail();
    expect($original->status)->toBe('pending');

    $original->forceFill(['status' => 'approved'])->save();
    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-09-05'])->assertOk()->assertJsonPath('data.reversed', 1);

    expect(fcoTrialBalance($scope['company_id']))->toBe(['debit' => 1000.0, 'credit' => 1000.0]);
});

test('prepaid amortization is straight line by month, the last month absorbs the rounding and a locked month stops the run', function () {
    $scope = fcoScope();
    Sanctum::actingAs(fcoStaff($scope, JS_PERMS));
    $this->postJson('/api/journal-schedules', [
        'kind' => 'amortization', 'name' => 'Insurance 2026', 'frequency' => 'monthly', 'start_date' => '2026-07-01',
        'meta' => ['total_amount' => 1000, 'months' => 3, 'source_account_id' => $scope['prepaid_id'], 'target_account_id' => $scope['insurance_id'], 'direction' => 'expense'],
    ])->assertCreated();

    fcoLockThrough($scope['company_id'], '2026-07-31');
    $blocked = $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-09-30'])->assertOk();
    expect($blocked->json('data.posted'))->toBe(0)->and($blocked->json('data.blocked.0.date'))->toBe('2026-07-31')
        ->and(JournalSchedule::query()->first()->blocked_reason)->not->toBeNull();

    // reopened: it continues from July with nothing skipped
    PeriodLockEntry::query()->create(['company_id' => $scope['company_id'], 'locked_through' => null, 'action' => 'clear', 'locked_by' => 1]);
    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-09-30'])->assertOk()->assertJsonPath('data.posted', 3);

    $amounts = DB::table('journal_schedule_runs')->orderBy('period_date')->pluck('amount')->map(fn ($v): float => (float) $v)->all();
    expect($amounts)->toBe([333.33, 333.33, 333.34])
        ->and(fcoBalance($scope['insurance_id']))->toBe(1000.0)
        ->and(JournalSchedule::query()->first()->status)->toBe('completed')
        ->and(fcoTrialBalance($scope['company_id']))->toBe(['debit' => 1000.0, 'credit' => 1000.0]);
});

test('deferred revenue is released into revenue month by month', function () {
    $scope = fcoScope();
    Sanctum::actingAs(fcoStaff($scope, JS_PERMS));
    $this->postJson('/api/journal-schedules', [
        'kind' => 'amortization', 'name' => 'Annual subscription', 'frequency' => 'monthly', 'start_date' => '2026-09-01',
        'meta' => ['total_amount' => 1200, 'months' => 12, 'source_account_id' => $scope['deferred_id'], 'target_account_id' => $scope['revenue_id'], 'direction' => 'revenue'],
    ])->assertCreated();

    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-10-31'])->assertOk()->assertJsonPath('data.posted', 2);

    expect(fcoBalance($scope['revenue_id']))->toBe(-200.0)->and(fcoBalance($scope['deferred_id']))->toBe(200.0);
});

test('a recurring expense posts an expense voucher that follows the expense approval rule', function () {
    $scope = fcoScope(autoApprove: true, expenseAutoApprove: false);
    Sanctum::actingAs(fcoStaff($scope, JS_PERMS));
    $this->postJson('/api/journal-schedules', [
        'kind' => 'expense', 'name' => 'Internet', 'frequency' => 'monthly', 'start_date' => '2026-09-05', 'max_runs' => 2,
        'meta' => ['amount' => 80, 'expense_account_id' => $scope['rent_id'], 'paid_from_account_id' => $scope['bank_id'], 'payee_contact_id' => $scope['contact_id'] ?? $scope['customer_id']],
    ])->assertCreated();

    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-10-31'])->assertOk()->assertJsonPath('data.posted', 2);

    $vouchers = TAccount::query()->get();
    expect($vouchers->every(fn (TAccount $v): bool => str_starts_with((string) $v->voucher_no, 'EXP-')))->toBeTrue()
        ->and($vouchers->every(fn (TAccount $v): bool => $v->status === 'pending'))->toBeTrue();
});

test('schedules are company scoped, validated and cannot use another company account', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $foreign = insertPurchaseChartAccount($other + ['contact_id' => 0], '401-09999', 'Foreign Expense', 'dr', true);
    Sanctum::actingAs(fcoStaff($scope, JS_PERMS));

    $this->postJson('/api/journal-schedules', jsRecurring($scope, ['lines' => [
        ['account_id' => $foreign, 'debit' => 5, 'credit' => 0], ['account_id' => $scope['cash_id'], 'debit' => 0, 'credit' => 5],
    ]]))->assertUnprocessable();
    $this->postJson('/api/journal-schedules', jsRecurring($scope, ['lines' => [
        ['account_id' => $scope['rent_id'], 'debit' => 5, 'credit' => 0], ['account_id' => $scope['cash_id'], 'debit' => 0, 'credit' => 4],
    ]]))->assertUnprocessable();

    // company_id in the body is ignored
    $id = $this->postJson('/api/journal-schedules', jsRecurring($scope, ['company_id' => $other['company_id']]))->assertCreated()->json('data.id');
    expect((int) JournalSchedule::query()->find($id)->company_id)->toBe((int) $scope['company_id']);

    Sanctum::actingAs(fcoStaff($other, JS_PERMS));
    $this->putJson("/api/journal-schedules/{$id}", jsRecurring($scope))->assertNotFound();
    $this->getJson('/api/journal-schedules')->assertOk()->assertJsonCount(0, 'data');
});

test('a schedule that has posted cannot be deleted, only cancelled, and a cancelled one posts nothing more', function () {
    $scope = fcoScope();
    Sanctum::actingAs(fcoStaff($scope, JS_PERMS));
    $id = $this->postJson('/api/journal-schedules', jsRecurring($scope))->assertCreated()->json('data.id');
    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-08-31'])->assertOk()->assertJsonPath('data.posted', 1);

    $this->deleteJson("/api/journal-schedules/{$id}")->assertUnprocessable();
    $this->postJson("/api/journal-schedules/{$id}/cancel")->assertOk();
    $this->postJson('/api/journal-schedules/run', ['up_to' => '2026-12-31'])->assertOk()->assertJsonPath('data.posted', 0);
});
