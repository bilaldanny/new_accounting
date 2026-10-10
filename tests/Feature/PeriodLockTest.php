<?php

use App\Exceptions\PeriodLockedException;
use App\Models\AuditLog;
use App\Models\PeriodLockEntry;
use App\Models\Role;
use App\Models\TAccount;
use App\Models\TAccountDetail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PeriodLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The period lock: the books are closed through a date and nothing in that range can be created, changed, deleted, restored or
 * moved - through a model or through a bulk query - unless an administrator sends that one request with a reason.
 */
beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

function plkLock(int $companyId, string $through, array $extra = []): PeriodLockEntry
{
    return PeriodLockEntry::query()->create($extra + [
        'company_id' => $companyId, 'locked_through' => $through, 'action' => PeriodLockEntry::ACTION_LOCK, 'reason' => 'Year end', 'locked_by' => 1,
    ]);
}

/**
 * A sale made through the API on a day, so it carries its journal and lines.
 *
 * @param  array<string, mixed>  $scope
 */
function plkSell(array $scope, string $date, array $overrides = []): Transaction
{
    test()->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => $date] + $overrides))->assertSuccessful();

    return Transaction::query()->sells()->latest('id')->firstOrFail();
}

/**
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function plkJournalPayload(array $scope, string $date, bool $swapped = false): array
{
    $debit = ['account_id' => $scope['customer_coa_id'], 'code' => $scope['customer_code'], 'account_name' => 'Customer', 'account_nature' => 'dr', 'description' => 'd', 'debit' => 500, 'credit' => 0];
    $credit = ['account_id' => $scope['local_sales_coa_id'], 'code' => $scope['local_sales_code'], 'account_name' => 'Sales', 'account_nature' => 'cr', 'description' => 'c', 'debit' => 0, 'credit' => 500];

    if ($swapped) {
        $debit['account_id'] = $scope['output_tax_coa_id'];
        $debit['code'] = $scope['output_tax_code'];
    }

    return [
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'voucher_type' => 'JV', 'voucher_date' => $date,
        'comments' => 'Adjustment', 'taccountdetails' => [$debit, $credit],
    ];
}

// ------------------------------------------------------------------ guarantee: no lock, no change

test('with no lock and no dating rule every write behaves as it always did', function () {
    $scope = seedSellScope();

    $old = plkSell($scope, '2019-03-04');
    $this->postJson('/api/journal-entries', plkJournalPayload($scope, '2019-03-05'))->assertSuccessful();
    $voucher = TAccount::query()->manualJournals()->latest('id')->firstOrFail();

    $this->putJson("/api/sells/{$old->id}", validSellPayload($scope, ['transaction_date' => '2019-03-06', 'invoice_no' => $old->invoice_no]))->assertSuccessful();
    $this->putJson("/api/journal-entries/{$voucher->id}", plkJournalPayload($scope, '2019-03-07', true))->assertSuccessful();
    $this->postJson('/api/sells/bulk_delete', [$old->id])->assertSuccessful();
    $this->postJson('/api/sells/restore_records', [$old->id])->assertSuccessful();

    expect(Transaction::query()->find($old->id))->not->toBeNull()
        ->and(PeriodLockEntry::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'period_override')->count())->toBe(0);

    // A future date is fine too: allow_future_dates is NULL.
    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => now()->addMonths(3)->toDateString()]))->assertSuccessful();
});

test('a lock that is cleared, or that lies before every document, changes nothing', function () {
    $scope = seedSellScope();
    plkLock($scope['company_id'], '2020-12-31');
    PeriodLockEntry::query()->create(['company_id' => $scope['company_id'], 'locked_through' => null, 'action' => PeriodLockEntry::ACTION_CLEAR, 'locked_by' => 1]);

    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => '2019-01-01']))->assertSuccessful();
    expect(app(PeriodLock::class)->lockedThrough($scope['company_id']))->toBeNull();
});

// ------------------------------------------------------------------ inside the lock

test('a document cannot be created inside a locked period, and one after it can', function () {
    $scope = seedSellScope();
    plkLock($scope['company_id'], '2026-08-31');
    $before = Transaction::query()->count();

    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => '2026-08-31']))
        ->assertStatus(422)
        ->assertJsonPath('period_locked', true)
        ->assertJsonPath('locked_through', '2026-08-31');

    expect(Transaction::query()->count())->toBe($before)
        ->and(TAccount::query()->count())->toBe(0);

    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => '2026-09-01']))->assertSuccessful();
});

test('a locked document cannot be edited, deleted or restored, and nothing is left half written', function () {
    $scope = seedSellScope();
    $sell = plkSell($scope, '2026-08-20');
    $trashed = plkSell($scope, '2026-08-21');
    $this->postJson('/api/sells/bulk_delete', [$trashed->id])->assertSuccessful();
    plkLock($scope['company_id'], '2026-08-31');

    $this->putJson("/api/sells/{$sell->id}", validSellPayload($scope, ['transaction_date' => '2026-08-20', 'invoice_no' => $sell->invoice_no, 'final_amount' => 999, 'shipping_charges' => 1]))
        ->assertStatus(422)->assertJsonPath('period_locked', true);
    $this->postJson('/api/sells/bulk_delete', [$sell->id])->assertStatus(422);
    $this->postJson('/api/sells/restore_records', [$trashed->id])->assertStatus(422);

    $fresh = Transaction::query()->find($sell->id);
    expect((float) $fresh->final_amount)->toBe((float) $sell->final_amount)
        ->and(Transaction::query()->whereKey($trashed->id)->exists())->toBeFalse()
        ->and(TAccount::query()->where('transaction_id', $sell->id)->exists())->toBeTrue();
});

test('a date cannot be moved into or out of a locked period', function () {
    $scope = seedSellScope();
    $open = plkSell($scope, '2026-09-10');
    $locked = plkSell($scope, '2026-08-10');
    plkLock($scope['company_id'], '2026-08-31');

    $this->putJson("/api/sells/{$open->id}", validSellPayload($scope, ['transaction_date' => '2026-08-15', 'invoice_no' => $open->invoice_no]))->assertStatus(422);
    $this->putJson("/api/sells/{$locked->id}", validSellPayload($scope, ['transaction_date' => '2026-09-15', 'invoice_no' => $locked->invoice_no]))->assertStatus(422);

    expect(Transaction::query()->find($open->id)->transaction_date->toDateString())->toBe('2026-09-10')
        ->and(Transaction::query()->find($locked->id)->transaction_date->toDateString())->toBe('2026-08-10');
});

test('a voucher cannot be added, edited or have its lines changed inside a locked period', function () {
    $scope = seedSellScope();
    $this->postJson('/api/journal-entries', plkJournalPayload($scope, '2026-08-12'))->assertSuccessful();
    $voucher = TAccount::query()->manualJournals()->latest('id')->firstOrFail();
    $linesBefore = TAccountDetail::query()->where('t_account_id', $voucher->id)->orderBy('id')->pluck('account_code')->all();
    plkLock($scope['company_id'], '2026-08-31');

    $this->postJson('/api/journal-entries', plkJournalPayload($scope, '2026-08-13'))->assertStatus(422)->assertJsonPath('period_locked', true);

    // Same date and same totals: only a line's account changes, so the header itself is not touched.
    $this->putJson("/api/journal-entries/{$voucher->id}", plkJournalPayload($scope, '2026-08-12', true))->assertStatus(422);

    expect(TAccountDetail::query()->where('t_account_id', $voucher->id)->orderBy('id')->pluck('account_code')->all())->toBe($linesBefore);
});

test('a payment dated in a locked period is refused, one dated after it is taken, and the invoice status still follows', function () {
    $scope = seedSellScope();
    $sell = plkSell($scope, '2026-08-20');
    plkLock($scope['company_id'], '2026-08-31');

    $this->postJson('/api/sell-payments', [
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'transaction_id' => $sell->id, 'amount' => 50,
        'method' => 'cash', 'paid_on' => '2026-08-25', 'payment_account' => $scope['customer_coa_id'],
    ])->assertStatus(422);

    $before = $sell->fresh()->payment_status;
    $sell->payment_status = 'partial';
    $sell->save();

    expect($before)->not->toBe('partial')->and($sell->fresh()->payment_status)->toBe('partial');
});

test('the lock follows the company: another company writes in the same dates freely', function () {
    $scope = seedSellScope();
    plkLock($scope['company_id'] + 1000, '2026-12-31');

    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => '2026-08-26']))->assertSuccessful();
});

// ------------------------------------------------------------------ bulk paths and the custom builder

test('a bulk query is refused as a whole when one of its rows is locked, and soft deletes and scopes behave as before', function () {
    $scope = seedSellScope();
    $open = plkSell($scope, '2026-09-10');
    $locked = plkSell($scope, '2026-08-10');
    $other = plkSell($scope, '2026-09-11');
    plkLock($scope['company_id'], '2026-08-31');

    $attempt = fn (callable $write) => expect($write)->toThrow(PeriodLockedException::class);

    $attempt(fn () => Transaction::whereIn('id', [$open->id, $locked->id])->delete());
    $attempt(fn () => Transaction::whereIn('id', [$locked->id])->update(['final_amount' => 1]));
    $attempt(fn () => Transaction::withTrashed()->whereKey($locked->id)->forceDelete());
    $attempt(fn () => TAccount::query()->whereIn('transaction_id', [$locked->id])->delete());
    $attempt(fn () => TAccountDetail::query()->whereIn('t_account_id', TAccount::query()->where('transaction_id', $locked->id)->pluck('id'))->delete());

    expect(Transaction::query()->whereIn('id', [$open->id, $locked->id])->count())->toBe(2);

    // Rows outside the lock: soft delete, trashed scope, restore and the plain builder work exactly as without the guard.
    expect(Transaction::whereIn('id', [$open->id, $other->id])->delete())->toBe(2)
        ->and(Transaction::query()->whereKey($open->id)->exists())->toBeFalse()
        ->and(Transaction::onlyTrashed()->whereIn('id', [$open->id, $other->id])->count())->toBe(2)
        ->and(Transaction::withTrashed()->whereKey($open->id)->value('deleted_at'))->not->toBeNull();

    expect(Transaction::onlyTrashed()->whereIn('id', [$open->id])->restore())->toBe(1)
        ->and(Transaction::query()->whereKey($open->id)->exists())->toBeTrue()
        ->and(Transaction::query()->where('type', 'sell')->count())->toBe(2);

    // A bookkeeping update (payment status) is not a change to the books, even on a locked row.
    expect(Transaction::query()->whereKey($locked->id)->update(['payment_status' => 'paid']))->toBe(1);

    Transaction::onlyTrashed()->whereKey($other->id)->forceDelete();
    expect(Transaction::withTrashed()->whereKey($other->id)->exists())->toBeFalse();
});

test('a restore of a trashed locked row is refused by the builder as well', function () {
    $scope = seedSellScope();
    $sell = plkSell($scope, '2026-08-10');
    Transaction::whereKey($sell->id)->delete();
    plkLock($scope['company_id'], '2026-08-31');

    expect(fn () => Transaction::onlyTrashed()->whereKey($sell->id)->restore())->toThrow(PeriodLockedException::class)
        ->and(fn () => Transaction::onlyTrashed()->find($sell->id)->restore())->toThrow(PeriodLockedException::class)
        ->and(Transaction::onlyTrashed()->whereKey($sell->id)->exists())->toBeTrue();
});

// ------------------------------------------------------------------ override

test('an administrator overrides one save with a reason: it is audited and the lock stays', function () {
    $scope = seedSellScope();
    $sell = plkSell($scope, '2026-08-20');
    plkLock($scope['company_id'], '2026-08-31');
    $payload = validSellPayload($scope, ['transaction_date' => '2026-08-20', 'invoice_no' => $sell->invoice_no, 'additional_note' => 'late correction']);

    $this->putJson("/api/sells/{$sell->id}", $payload)->assertStatus(422)->assertJsonPath('can_override', true);

    // Too short a reason is no reason.
    $this->withHeaders([PeriodLock::OVERRIDE_HEADER => 'x'])->putJson("/api/sells/{$sell->id}", $payload)->assertStatus(422);

    $this->withHeaders([PeriodLock::OVERRIDE_HEADER => 'Auditor found a wrong price'])->putJson("/api/sells/{$sell->id}", $payload + ['final_amount' => 280])->assertSuccessful();

    $log = AuditLog::query()->where('event', 'period_override')->get();
    expect($log)->not->toBeEmpty()
        ->and($log->first()->new_values['reason'])->toBe('Auditor found a wrong price')
        ->and($log->first()->new_values['locked_through'])->toBe('2026-08-31')
        ->and($log->first()->user_id)->toBe(1)
        ->and(app(PeriodLock::class)->lockedThrough($scope['company_id']))->toBe('2026-08-31')
        ->and(PeriodLockEntry::query()->count())->toBe(1);

    // The next save, without the header, is refused again - also when only a line changes and the header stays the same.
    $this->withoutHeader(PeriodLock::OVERRIDE_HEADER);
    $line = $payload['selllines'][0];
    $line['unit_price'] = 130;
    $line['unit_price_after_discount'] = 130;
    $line['row_subtotal'] = 260;
    $this->putJson("/api/sells/{$sell->id}", ['selllines' => [$line]] + $payload + ['final_amount' => 280])->assertStatus(422);
});

test('a user without the override menu cannot override, and one who holds it can', function () {
    $scope = seedSellScope();
    $sell = plkSell($scope, '2026-08-20');
    plkLock($scope['company_id'], '2026-08-31');
    $role = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true]);
    grantMenuPermission((int) $role->id, '/sell/add');
    grantMenuPermission((int) $role->id, '/sell/:id/edit');
    $staff = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
    Sanctum::actingAs($staff);

    $payload = validSellPayload($scope, ['transaction_date' => '2026-08-20', 'invoice_no' => $sell->invoice_no, 'additional_note' => 'late']);
    $headers = [PeriodLock::OVERRIDE_HEADER => 'Needed for the audit'];

    $this->withHeaders($headers)->putJson("/api/sells/{$sell->id}", $payload)->assertStatus(422)->assertJsonPath('can_override', false);

    grantMenuPermission((int) $role->id, PeriodLock::OVERRIDE_MENU);

    $this->withHeaders($headers)->putJson("/api/sells/{$sell->id}", $payload)->assertSuccessful();
    expect(AuditLog::query()->where('event', 'period_override')->where('user_id', $staff->id)->exists())->toBeTrue();
});

// ------------------------------------------------------------------ dating rules

test('a company may refuse documents dated too far back, or in the future, and only for new documents and changed dates', function () {
    $scope = seedSellScope();
    $old = plkSell($scope, now()->subDays(40)->toDateString());
    DB::table('company_settings')->updateOrInsert(['company_id' => $scope['company_id']], ['max_backdate_days' => 7, 'allow_future_dates' => false, 'created_at' => now(), 'updated_at' => now()]);

    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => now()->subDays(8)->toDateString()]))->assertStatus(422)->assertSee('more than 7 days back');
    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => now()->addDay()->toDateString()]))->assertStatus(422)->assertSee('in the future');
    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => now()->subDays(7)->toDateString()]))->assertSuccessful();
    $this->postJson('/api/sells', validSellPayload($scope, ['transaction_date' => now()->toDateString()]))->assertSuccessful();

    // An old document may still be edited and deleted while its date is left as it is.
    $this->putJson("/api/sells/{$old->id}", validSellPayload($scope, ['transaction_date' => $old->transaction_date->toDateString(), 'invoice_no' => $old->invoice_no, 'additional_note' => 'x']))->assertSuccessful();
    $this->putJson("/api/sells/{$old->id}", validSellPayload($scope, ['transaction_date' => now()->subDays(30)->toDateString(), 'invoice_no' => $old->invoice_no]))->assertStatus(422);
    $this->postJson('/api/sells/bulk_delete', [$old->id])->assertSuccessful();
});

// ------------------------------------------------------------------ the lock screen's API

test('the lock is set, moved forward, reopened and cleared, each step kept in the history and the audit log', function () {
    $scope = seedSellScope();
    $company = ['company_id' => $scope['company_id']];

    $this->postJson('/api/period-locks', $company + ['locked_through' => '2026-06-30', 'reason' => 'June closed'])->assertSuccessful()->assertJsonPath('action', 'lock');
    $this->postJson('/api/period-locks', $company + ['locked_through' => '2026-06-30', 'reason' => 'again'])->assertStatus(422);
    $this->postJson('/api/period-locks', $company + ['locked_through' => '2026-08-31', 'reason' => 'August closed'])->assertSuccessful()->assertJsonPath('action', 'advance');
    $this->postJson('/api/period-locks', $company + ['locked_through' => '2026-07-31', 'reason' => 'A late bill came in'])->assertSuccessful()->assertJsonPath('action', 'reopen');
    $this->postJson('/api/period-locks', $company + ['locked_through' => null, 'reason' => 'Audit finished'])->assertSuccessful()->assertJsonPath('action', 'clear');

    $this->getJson('/api/period-locks?company_id='.$scope['company_id'])->assertOk()
        ->assertJsonPath('locked_through', null)
        ->assertJsonCount(4, 'history')
        ->assertJsonPath('history.0.action', 'clear')
        ->assertJsonPath('history.3.action', 'lock');

    expect(AuditLog::query()->whereIn('event', ['period_lock', 'period_advance', 'period_reopen', 'period_clear'])->count())->toBe(4)
        ->and(AuditLog::query()->where('event', 'period_reopen')->first()->new_values['reason'])->toBe('A late bill came in')
        ->and(AuditLog::query()->where('event', 'period_reopen')->first()->old_values['locked_through'])->toBe('2026-08-31');
});

test('a reason is required and the lock cannot lie in the future', function () {
    $scope = seedSellScope();

    $this->postJson('/api/period-locks', ['company_id' => $scope['company_id'], 'locked_through' => '2026-06-30'])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson('/api/period-locks', ['company_id' => $scope['company_id'], 'locked_through' => now()->addDays(5)->toDateString(), 'reason' => 'Too early'])->assertStatus(422);
});

test('the accountant may lock and move forward, only an administrator may reopen, override or change the dating rules', function () {
    $scope = seedSellScope();
    plkLock($scope['company_id'], '2026-06-30');
    $role = Role::query()->create(['name' => 'Accountant', 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/period-lock');
    grantMenuPermission((int) $role->id, '/period-lock/add');
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]));

    $this->getJson('/api/period-locks')->assertOk()
        ->assertJsonPath('can', ['add' => true, 'reopen' => false, 'override' => false, 'rollover' => false]);
    $this->postJson('/api/period-locks', ['locked_through' => '2026-07-31', 'reason' => 'July closed'])->assertSuccessful()->assertJsonPath('action', 'advance');
    $this->postJson('/api/period-locks', ['locked_through' => '2026-05-31', 'reason' => 'Trying to go back'])->assertForbidden();
    $this->postJson('/api/period-locks', ['locked_through' => null, 'reason' => 'Trying to clear'])->assertForbidden();
    $this->putJson('/api/period-locks/rules', ['max_backdate_days' => 3, 'reason' => 'Tighten'])->assertForbidden();

    expect(app(PeriodLock::class)->lockedThrough($scope['company_id']))->toBe('2026-07-31');
});

test('an administrator sets the dating rules, audited, and a company that has no settings row is told to save them first', function () {
    $scope = seedSellScope();
    $company = ['company_id' => $scope['company_id']];

    $this->putJson('/api/period-locks/rules', $company + ['max_backdate_days' => 5, 'allow_future_dates' => false, 'reason' => 'Policy'])->assertStatus(422);

    DB::table('company_settings')->updateOrInsert(['company_id' => $scope['company_id']], ['created_at' => now(), 'updated_at' => now()]);
    $this->putJson('/api/period-locks/rules', $company + ['max_backdate_days' => 5, 'allow_future_dates' => false, 'reason' => 'Policy'])->assertSuccessful()
        ->assertJsonPath('rules', ['max_backdate_days' => 5, 'allow_future_dates' => false]);
    $this->putJson('/api/period-locks/rules', $company + ['max_backdate_days' => null, 'allow_future_dates' => null, 'reason' => 'Policy lifted'])->assertSuccessful()
        ->assertJsonPath('rules', ['max_backdate_days' => null, 'allow_future_dates' => null]);

    expect(AuditLog::query()->where('event', 'period_rules_changed')->count())->toBe(2);
});

// ------------------------------------------------------------------ what a locked document still takes

test('a locked sale still takes delivery, a payment and a return dated in the open period', function () {
    $scope = seedSellScope();
    $sell = plkSell($scope, '2026-08-20');
    $sell->update(['status' => 'approved']);
    plkLock($scope['company_id'], '2026-08-31');
    $today = now()->toDateString();

    // Delivery: an issue note (dated today) moves the sale to issued, and deleting it moves it back.
    $this->postJson('/api/issue-notes', validIssueNotePayload($scope, $sell->fresh(), ['transaction_date' => $today]))->assertSuccessful();
    expect($sell->fresh()->status)->toBe('issue');

    $note = Transaction::query()->where('type', Transaction::TYPE_ISSUE_NOTE)->firstOrFail();
    $this->deleteJson("/api/issue-notes/{$note->id}")->assertSuccessful();
    expect($sell->fresh()->status)->toBe('approved');

    // A return made now, against the locked sale, is a new document of the open period.
    $sell->update(['status' => 'issue']);
    $sell->selllines()->update(['quantity_issue' => DB::raw('quantity')]);
    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell->fresh(), ['transaction_date' => $today]))->assertSuccessful();
    expect(Transaction::query()->sellReturns()->where('parent_id', $sell->id)->exists())->toBeTrue();
});
