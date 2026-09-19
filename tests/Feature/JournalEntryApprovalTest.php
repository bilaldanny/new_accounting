<?php

use App\Http\Controllers\VoucherApprovalController;
use App\Models\CompanySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TAccount;
use App\Models\TAccountDetail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Journal entry approval follows the purchase and sell approval pattern: a company toggle skips the
 * approval step, otherwise a permitted user approves (or rejects) from the approval list. Only
 * approved vouchers count towards account balances. The payment, expense, deposit and fund
 * transfer vouchers share the mechanism and are covered by VoucherApprovalTest; the helpers are in
 * tests/Support/voucherApproval.php.
 */

// ------------------------------------------------------------------ auto approval

dataset('auto approval modules', [
    'purchase' => ['purchase', 'purchase_approval'],
    'sell' => ['sell', 'sell_approval'],
    'journal' => ['journal', 'journal_entry'],
]);

test('auto approval reads one company toggle per module', function (string $module, string $column) {
    $scope = jeaScope();

    expect(CompanySetting::autoApproves($scope['company_id'], $module))->toBeFalse();

    jeaSetting($scope['company_id'], $column, true);
    expect(CompanySetting::autoApproves($scope['company_id'], $module))->toBeTrue();

    jeaSetting($scope['company_id'], $column, false);
    expect(CompanySetting::autoApproves($scope['company_id'], $module))->toBeFalse();
})->with('auto approval modules');

test('auto approval toggles do not leak between modules or companies', function () {
    $one = jeaScope('1');
    $two = jeaScope('2');

    jeaSetting($one['company_id'], 'journal_entry', true);
    jeaSetting($two['company_id'], 'purchase_approval', false);

    expect(CompanySetting::autoApproves($one['company_id'], 'journal'))->toBeTrue()
        ->and(CompanySetting::autoApproves($one['company_id'], 'purchase'))->toBeFalse()
        ->and(CompanySetting::autoApproves($one['company_id'], 'sell'))->toBeFalse()
        ->and(CompanySetting::autoApproves($two['company_id'], 'journal'))->toBeFalse();
});

test('auto approval never applies without a company and rejects an unknown module', function () {
    expect(CompanySetting::autoApproves(null, 'journal'))->toBeFalse();

    CompanySetting::autoApproves(1, 'payroll');
})->throws(InvalidArgumentException::class, 'Unknown approval module [payroll].');

test('a purchase is approved on save when auto approval is on and pending when it is off', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/purchases', validPurchasePayload($scope))->assertSuccessful();
    expect(Transaction::query()->purchases()->where('contact_id', $scope['contact_id'])->latest('id')->first()->status)->toBe('pending');

    jeaSetting($scope['company_id'], 'purchase_approval', true);

    $this->postJson('/api/purchases', validPurchasePayload($scope))->assertSuccessful();
    expect(Transaction::query()->purchases()->where('contact_id', $scope['contact_id'])->latest('id')->first()->status)->toBe('approved');
});

test('a sell is approved on save when auto approval is on and stays final when it is off', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
    expect(Transaction::query()->sells()->where('contact_id', $scope['contact_id'])->latest('id')->first()->status)->toBe('final');

    jeaSetting($scope['company_id'], 'sell_approval', true);

    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
    expect(Transaction::query()->sells()->where('contact_id', $scope['contact_id'])->latest('id')->first()->status)->toBe('approved');
});

test('a journal entry is approved on save when auto approval is on and pending when it is off', function () {
    $scope = jeaScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/journal-entries', jeaPayload($scope))->assertSuccessful();
    $off = TAccount::query()->manualJournals()->latest('id')->firstOrFail();

    jeaSetting($scope['company_id'], 'journal_entry', true);

    $this->postJson('/api/journal-entries', jeaPayload($scope))->assertSuccessful();
    $on = TAccount::query()->manualJournals()->latest('id')->firstOrFail();

    expect($off->status)->toBe('pending')
        ->and($on->status)->toBe('approved')
        ->and($on->approved_by)->toBeNull();
});

test('the superadmin follows the journal toggle like everyone else', function () {
    $scope = jeaScope();
    jeaSetting($scope['company_id'], 'journal_entry', true);
    Sanctum::actingAs(User::query()->findOrFail(1));

    expect(TAccount::resolveStatus($scope['company_id'], 'JV'))->toBe('approved')
        ->and(TAccount::resolveStatus($scope['company_id'], 'JE'))->toBe('approved');

    jeaSetting($scope['company_id'], 'journal_entry', false);

    expect(TAccount::resolveStatus($scope['company_id'], 'JV'))->toBe('pending');
});

test('a duplicated journal entry starts in the status the toggle decides', function () {
    $scope = jeaScope();
    $original = jeaJournal($scope, 'approved');

    $this->postJson('/api/journal-entries/duplicate', ['id' => $original->id])->assertSuccessful();
    expect(TAccount::query()->manualJournals()->latest('id')->first()->status)->toBe('pending');

    jeaSetting($scope['company_id'], 'journal_entry', true);

    $this->postJson('/api/journal-entries/duplicate', ['id' => $original->id])->assertSuccessful();
    expect(TAccount::query()->manualJournals()->latest('id')->first()->status)->toBe('approved');
});

test('the journal toggle does not decide the other voucher families', function () {
    $scope = jeaScope();
    jeaSetting($scope['company_id'], 'journal_entry', true);
    Sanctum::actingAs(User::query()->findOrFail(1));

    expect(TAccount::resolveStatus($scope['company_id'], 'JV'))->toBe('approved');

    foreach (['BP', 'CP', 'OP', 'EXP', 'BD', 'CD', 'OD', 'FT'] as $type) {
        expect(TAccount::resolveStatus($scope['company_id'], $type))->toBe('pending');
    }
});
// ------------------------------------------------------------------ approve and reject

test('guests cannot use the journal entry approval endpoints', function () {
    $this->getJson('/api/journal-entry-approvals')->assertUnauthorized();
    $this->postJson('/api/journal-entry-approvals/1/approve')->assertUnauthorized();
    $this->postJson('/api/journal-entry-approvals/1/reject')->assertUnauthorized();
});

test('approving a pending journal entry records who approved it and when', function () {
    $scope = jeaScope();
    $journal = jeaJournal($scope);

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Approved');

    $journal->refresh();

    expect($journal->status)->toBe('approved')
        ->and($journal->approved_by)->toBe(1)
        ->and($journal->approved_at)->not->toBeNull()
        ->and($journal->rejected_by)->toBeNull()
        ->and($journal->rejected_at)->toBeNull();
});

test('rejecting a pending journal entry records who rejected it and keeps the reason', function () {
    $scope = jeaScope();
    $journal = jeaJournal($scope);

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/reject", ['reason' => 'Wrong account'])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Rejected');

    $journal->refresh();

    expect($journal->status)->toBe('rejected')
        ->and($journal->rejected_by)->toBe(1)
        ->and($journal->rejected_at)->not->toBeNull()
        ->and($journal->approved_by)->toBeNull()
        ->and($journal->approved_at)->toBeNull()
        ->and($journal->comments)->toBe("Opening capital\nRejected: Wrong account");
});

test('a journal entry can be rejected without a reason and the comments stay as they were', function () {
    $scope = jeaScope();
    $journal = jeaJournal($scope);

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/reject")->assertSuccessful();

    expect($journal->fresh()->status)->toBe('rejected')
        ->and($journal->fresh()->comments)->toBe('Opening capital');
});

test('the rejection reason is limited in length', function () {
    $scope = jeaScope();
    $journal = jeaJournal($scope);

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/reject", ['reason' => str_repeat('x', 501)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    expect($journal->fresh()->status)->toBe('pending');
});

dataset('non pending statuses', ['approved', 'rejected', 'cancelled']);

test('only a pending journal entry can be approved or rejected', function (string $status) {
    $scope = jeaScope();
    $journal = jeaJournal($scope, $status);

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/approve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/reject")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($journal->fresh()->status)->toBe($status)
        ->and($journal->fresh()->approved_by)->toBeNull()
        ->and($journal->fresh()->rejected_by)->toBeNull();
})->with('non pending statuses');

test('an unbalanced journal entry cannot be approved', function () {
    $scope = jeaScope();
    $journal = jeaJournal($scope);

    TAccountDetail::query()->where('t_account_id', $journal->id)->where('credit', '>', 0)->update(['credit' => 1400]);

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/approve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('taccountdetails');

    expect($journal->fresh()->status)->toBe('pending');
});

test('approve and reject return not found for missing vouchers and for other voucher families', function () {
    $scope = jeaScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/journal-entry-approvals/9999/approve')->assertNotFound();
    $this->postJson('/api/journal-entry-approvals/9999/reject')->assertNotFound();

    $payment = TAccount::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'account_code' => $scope['debit_code'],
        'voucher_no' => 'CP-00001',
        'ref_no' => '',
        'cheque_no' => '',
        'comments' => '',
        'status' => 'pending',
    ]);

    $this->postJson("/api/journal-entry-approvals/{$payment->id}/approve")->assertNotFound();
    expect($payment->fresh()->status)->toBe('pending');
});

test('a company user cannot approve another company journal entry', function () {
    $mine = jeaScope('1');
    $theirs = jeaScope('2');
    $foreign = jeaJournal($theirs);

    $user = jeaUserWith($mine, ['/journalentry/:id/approve', '/journalentry/:id/reject']);
    Sanctum::actingAs($user);

    $this->postJson("/api/journal-entry-approvals/{$foreign->id}/approve")->assertNotFound();
    $this->postJson("/api/journal-entry-approvals/{$foreign->id}/reject")->assertNotFound();

    expect($foreign->fresh()->status)->toBe('pending');
});

// ------------------------------------------------------------------ permissions

test('approving and rejecting need their own menu permission', function () {
    $scope = jeaScope();
    $journal = jeaJournal($scope);
    $other = jeaJournal($scope);

    $nobody = jeaUserWith($scope, []);
    Sanctum::actingAs($nobody);
    $this->postJson("/api/journal-entry-approvals/{$journal->id}/approve")->assertForbidden();
    $this->postJson("/api/journal-entry-approvals/{$journal->id}/reject")->assertForbidden();

    $approver = jeaUserWith($scope, ['/journalentry/:id/approve']);
    Sanctum::actingAs($approver);
    $this->postJson("/api/journal-entry-approvals/{$journal->id}/reject")->assertForbidden();
    $this->postJson("/api/journal-entry-approvals/{$journal->id}/approve")->assertSuccessful();

    $rejecter = jeaUserWith($scope, ['/journalentry/:id/reject']);
    Sanctum::actingAs($rejecter);
    $this->postJson("/api/journal-entry-approvals/{$other->id}/approve")->assertForbidden();
    $this->postJson("/api/journal-entry-approvals/{$other->id}/reject")->assertSuccessful();

    expect($journal->fresh()->status)->toBe('approved')
        ->and($journal->fresh()->approved_by)->toBe($approver->id)
        ->and($other->fresh()->status)->toBe('rejected')
        ->and($other->fresh()->rejected_by)->toBe($rejecter->id);
});

test('the user who created a journal entry may approve it when their role allows it', function () {
    $scope = jeaScope();
    $user = jeaUserWith($scope, ['/journalentry/add', '/journalentry/:id/approve']);
    Sanctum::actingAs($user);

    $this->postJson('/api/journal-entries', jeaPayload($scope))->assertSuccessful();
    $journal = TAccount::query()->manualJournals()->firstOrFail();

    expect($journal->created_by)->toBe($user->id);

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/approve")->assertSuccessful();
    expect($journal->fresh()->status)->toBe('approved');
});

// ------------------------------------------------------------------ list and show

test('the approval list shows pending entries by default and filters by status', function () {
    $scope = jeaScope();
    $pending = jeaJournal($scope);
    $approved = jeaJournal($scope, 'approved');
    $rejected = jeaJournal($scope, 'rejected');

    $default = $this->getJson('/api/journal-entry-approvals')->assertSuccessful();
    expect(collect($default->json('data.data'))->pluck('id')->all())->toBe([$pending->id])
        ->and($default->json('data.data.0.status'))->toBe('pending')
        ->and($default->json('data.data.0.status_label'))->toBe('Pending');

    $all = $this->getJson('/api/journal-entry-approvals?status=all')->assertSuccessful();
    expect(collect($all->json('data.data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$pending->id, $approved->id, $rejected->id])->sort()->values()->all());

    $onlyRejected = $this->getJson('/api/journal-entry-approvals?status=rejected')->assertSuccessful();
    expect(collect($onlyRejected->json('data.data'))->pluck('id')->all())->toBe([$rejected->id])
        ->and($onlyRejected->json('data.data.0.status_label'))->toBe('Rejected');
});

test('the approval list only shows the current company', function () {
    $mine = jeaScope('1');
    $theirs = jeaScope('2');
    $own = jeaJournal($mine);
    jeaJournal($theirs);

    Sanctum::actingAs(jeaUserWith($mine, []));

    $response = $this->getJson('/api/journal-entry-approvals')->assertSuccessful();

    expect(collect($response->json('data.data'))->pluck('id')->all())->toBe([$own->id]);
});

test('the approval list leaves out payments and other voucher families', function () {
    $scope = jeaScope();
    jeaJournal($scope);
    TAccount::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'account_code' => $scope['debit_code'],
        'voucher_no' => 'CP-00001',
        'ref_no' => '',
        'cheque_no' => '',
        'comments' => '',
        'status' => 'pending',
    ]);

    $this->getJson('/api/journal-entry-approvals')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.data');
});

test('the journal entry list can filter the new rejected status', function () {
    $scope = jeaScope();
    $rejected = jeaJournal($scope, 'rejected');
    jeaJournal($scope);

    $response = $this->getJson('/api/journal-entries?status=rejected')->assertSuccessful();

    expect(collect($response->json('data.data'))->pluck('id')->all())->toBe([$rejected->id]);
});

test('the voucher shows whether it can still be approved and who decided', function () {
    $scope = jeaScope();
    $journal = jeaJournal($scope);

    $pending = $this->getJson("/api/journal-entry-approvals/{$journal->id}")->assertSuccessful();
    expect($pending->json('can_approve'))->toBeTrue()
        ->and($pending->json('status_label'))->toBe('Pending')
        ->and($pending->json('approved_by_name'))->toBeNull();

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/approve")->assertSuccessful();

    $approved = $this->getJson("/api/journal-entries/{$journal->id}")->assertSuccessful();
    expect($approved->json('can_approve'))->toBeFalse()
        ->and($approved->json('status'))->toBe('approved')
        ->and($approved->json('approved_by_name'))->toBe(User::query()->find(1)->full_name)
        ->and($approved->json('approved_at'))->not->toBeNull();

    $other = jeaJournal($scope);
    $this->postJson("/api/journal-entry-approvals/{$other->id}/reject")->assertSuccessful();

    $rejected = $this->getJson("/api/journal-entry-approvals/{$other->id}")->assertSuccessful();
    expect($rejected->json('can_approve'))->toBeFalse()
        ->and($rejected->json('rejected_by_name'))->toBe(User::query()->find(1)->full_name);
});

test('a system generated voucher never reports that it can be approved', function () {
    $scope = jeaScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $sellPayment = TAccount::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'account_code' => $scope['debit_code'],
        'voucher_no' => 'SP-00001',
        'ref_no' => '',
        'cheque_no' => '',
        'comments' => '',
        'status' => 'pending',
    ]);

    expect($sellPayment->presentForForm()['can_approve'])->toBeFalse();
});

// ------------------------------------------------------------------ accounting effect

test('a pending journal entry does not touch account balances until it is approved', function () {
    $scope = jeaScope();
    $journal = jeaJournal($scope);

    expect(jeaNets($scope))->toBe([$scope['debit_code'] => 0.0, $scope['credit_code'] => 0.0]);

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/approve")->assertSuccessful();

    expect(jeaNets($scope))->toBe([$scope['debit_code'] => 1500.0, $scope['credit_code'] => 1500.0]);
});

test('a rejected journal entry never reaches account balances', function () {
    $scope = jeaScope();
    $journal = jeaJournal($scope);

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/reject", ['reason' => 'Duplicate'])->assertSuccessful();

    expect(jeaNets($scope))->toBe([$scope['debit_code'] => 0.0, $scope['credit_code'] => 0.0]);
});

test('an auto approved journal entry counts towards balances straight away', function () {
    $scope = jeaScope();
    jeaSetting($scope['company_id'], 'journal_entry', true);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/journal-entries', jeaPayload($scope, 800))->assertSuccessful();

    expect(jeaNets($scope))->toBe([$scope['debit_code'] => 800.0, $scope['credit_code'] => 800.0]);
});

test('approving does not change the lines of the voucher', function () {
    $scope = jeaScope();
    $journal = jeaJournal($scope);
    $before = TAccountDetail::query()->where('t_account_id', $journal->id)->orderBy('id')->get(['id', 'account_code', 'debit', 'credit'])->toArray();

    $this->postJson("/api/journal-entry-approvals/{$journal->id}/approve")->assertSuccessful();

    $after = TAccountDetail::query()->where('t_account_id', $journal->id)->orderBy('id')->get(['id', 'account_code', 'debit', 'credit'])->toArray();

    expect($after)->toBe($before)
        ->and((float) $journal->fresh()->total_amount)->toBe(1500.0);
});

// ------------------------------------------------------------------ pages and menu

test('the approval pages render for a signed in user and guests are sent away', function () {
    $this->get(route('journalentry.approval'))->assertRedirect();

    $this->actingAs(User::query()->findOrFail(1))
        ->get(route('journalentry.approval'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('approval/journalentry/index'));

    $this->actingAs(User::query()->findOrFail(1))
        ->get(route('journalentry.approval.view', 7))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('journalentry/view')
            ->where('id', '7')
            ->where('returnTo', '/journalentry/approval')
            ->where('listTitle', 'Journal Entry Approval'));
});

test('the approval pages resolve from the sidebar paths', function () {
    $match = app('router')->getRoutes()->match(Request::create('/journalentry/approval', 'GET'));

    expect($match->getName())->toBe('journalentry.approval');
});

function jeaMenuMigration(): object
{
    return require database_path('migrations/2026_09_19_162000_add_journal_entry_approval_menu.php');
}

function jeaSeedApprovalGroup(): int
{
    $groupId = DB::table('menus')->insertGetId([
        'parent_id' => null,
        'name' => 'Approval',
        'icon' => 'fal fa-thumbs-up',
        'route_name' => '',
        'route_path' => '',
        'menu_color' => '#6a0dad',
        'sort_order' => 5,
        'is_hidden' => 0,
        'is_active' => 1,
        'is_admin' => 0,
        'is_permission' => 0,
        'type' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([['Purchase Approval', '/purchase/approval', 1], ['Sell Approval', '/sell/approval', 2]] as [$name, $path, $order]) {
        DB::table('menus')->insert([
            'parent_id' => $groupId,
            'name' => $name,
            'icon' => 'bx bx-buildings',
            'route_name' => ltrim(str_replace('/', '', $path), '/'),
            'route_path' => $path,
            'menu_color' => '#6a0dad',
            'sort_order' => $order,
            'is_hidden' => 0,
            'is_active' => 1,
            'is_admin' => 0,
            'is_permission' => 0,
            'type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $groupId;
}

test('the menu migration adds a visible list row after the purchase and sell approval rows', function () {
    $groupId = jeaSeedApprovalGroup();

    jeaMenuMigration()->up();

    $row = DB::table('menus')->where('route_path', '/journalentry/approval')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->parent_id)->toBe($groupId)
        ->and($row->name)->toBe('Journal Entry Approval')
        ->and((int) $row->is_hidden)->toBe(0)
        ->and((int) $row->is_active)->toBe(1)
        ->and((int) $row->type)->toBe(1)
        ->and((int) $row->sort_order)->toBe(3);
});

test('the menu migration is idempotent and grants no permissions', function () {
    jeaSeedApprovalGroup();
    Role::query()->create(['name' => 'companyadmin', 'is_active' => true]);

    jeaMenuMigration()->up();
    jeaMenuMigration()->up();

    expect(DB::table('menus')->where('route_path', '/journalentry/approval')->count())->toBe(1)
        ->and(DB::table('permissions')->count())->toBe(0);
});

test('the menu migration adds nothing when the approval group is missing', function () {
    jeaMenuMigration()->up();

    expect(DB::table('menus')->where('route_path', '/journalentry/approval')->exists())->toBeFalse();
});

test('the menu migration can be rolled back with its permission rows', function () {
    jeaSeedApprovalGroup();
    jeaMenuMigration()->up();

    $menuId = (int) DB::table('menus')->where('route_path', '/journalentry/approval')->value('id');
    $role = Role::query()->create(['name' => 'companyadmin', 'is_active' => true]);
    Permission::query()->create(['role_id' => $role->id, 'menu_id' => $menuId, 'status' => 1]);

    jeaMenuMigration()->down();

    expect(DB::table('menus')->where('route_path', '/journalentry/approval')->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $menuId)->exists())->toBeFalse();
});

test('the approval controller checks the journal entry approve and reject rows that already exist in live data', function () {
    expect(VoucherApprovalController::PERMISSIONS['journal'])->toBe([
        'approve' => '/journalentry/:id/approve',
        'reject' => '/journalentry/:id/reject',
    ]);
});
