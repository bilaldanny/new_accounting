<?php

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Role;
use App\Models\TAccount;
use App\Models\TAccountDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Credit Note (`CN`, a customer's balance goes down) and Debit Note (`DN`, a supplier's balance goes
 * down): a standalone 2-line balanced voucher in the same t_accounts engine every manual voucher family
 * uses, family 'creditdebitnote'. The caller only picks a contact, an offsetting account and an amount;
 * the controller builds the balanced pair itself so the contact line can only ever be their own
 * customer_gl_id/supplier_gl_id account.
 */

/**
 * A company/branch plus one "Sales Returns & Allowances" account to use as the free-choice offset.
 *
 * @return array{company_id: int, branch_id: int, offset_account_id: int}
 */
function cdnScope(string $suffix = '1'): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CDN'.$suffix,
        'name' => 'Credit Debit Note Co '.$suffix,
        'address' => '1 Note Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'CDNB'.$suffix,
        'company_id' => $companyId,
        'name' => 'Note Branch '.$suffix,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $offsetAccountId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'code' => '521-0000'.$suffix,
        'name' => 'Sales Returns & Allowances',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'bs' => 0,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['company_id' => $companyId, 'branch_id' => $branchId, 'offset_account_id' => $offsetAccountId];
}

/**
 * @param  array{company_id: int, branch_id: int, offset_account_id: int}  $scope
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cdnPayload(array $scope, string $voucherType, int $contactId, float $amount, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_type' => $voucherType,
        'voucher_date' => '2026-09-10',
        'ref_no' => 'INV-1001',
        'comments' => 'Damaged goods',
        'contact_id' => $contactId,
        'account_id' => $scope['offset_account_id'],
        'amount' => $amount,
    ], $overrides);
}

function cdnSetting(int $companyId, bool $on): void
{
    $setting = CompanySetting::query()->where('company_id', $companyId)->first()
        ?? CompanySetting::createCompanySettings($companyId, 'Credit Debit Note Co');

    $setting->forceFill(['credit_debit_note_approval' => $on])->save();
}

/**
 * @param  list<string>  $paths
 */
function cdnUserWith(array $scope, array $paths): User
{
    $role = Role::query()->create([
        'name' => 'notesclerk'.uniqid(),
        'company_id' => $scope['company_id'],
        'is_active' => true,
    ]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path, ltrim(str_replace('/', '', $path), '/').uniqid());
    }

    return createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);
}

// --- balance effect --------------------------------------------------------------------------------

test('a credit note credits the customer\'s account and reduces what they owe', function () {
    $scope = cdnScope('A');
    prpFinancialYear($scope['company_id']);
    cdnSetting($scope['company_id'], true);
    $customer = prpLinkedContact($scope, 'customer', '101-0001A', 'CN Customer');
    prpOpeningBalance($scope, $customer['coa_id'], 1000, 'dr');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer['id'], 300))->assertSuccessful();

    $row = prpRow(prpGet('customer-outstanding', ['end_date' => '2026-09-30', 'show_record' => 100]), $customer['id']);
    expect($row['balance'])->toEqual(700.0);
});

test('a debit note debits the supplier\'s account and reduces what is owed to them', function () {
    $scope = cdnScope('B');
    prpFinancialYear($scope['company_id']);
    cdnSetting($scope['company_id'], true);
    $supplier = prpLinkedContact($scope, 'supplier', '311-0001B', 'DN Supplier');
    prpOpeningBalance($scope, $supplier['coa_id'], 2000, 'cr');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'DN', $supplier['id'], 500))->assertSuccessful();

    $row = prpRow(prpGet('supplier-outstanding', ['end_date' => '2026-09-30', 'show_record' => 100]), $supplier['id']);
    expect($row['balance'])->toEqual(1500.0);
});

test('the two lines a note posts are always balanced: the contact line first, the offset line second', function () {
    $scope = cdnScope('C');
    cdnSetting($scope['company_id'], true);
    $customer = prpLinkedContact($scope, 'customer', '101-0001C', 'Balance Customer');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer['id'], 250))->assertSuccessful();

    $note = TAccount::query()->manualCreditDebitNotes()->latest('id')->firstOrFail();
    $lines = TAccountDetail::query()->where('t_account_id', $note->id)->orderBy('id')->get();

    expect($lines)->toHaveCount(2)
        ->and((float) $lines[0]->credit)->toBe(250.0)
        ->and((float) $lines[0]->debit)->toBe(0.0)
        ->and($lines[0]->contact_id)->toBe($customer['id'])
        ->and((float) $lines[1]->debit)->toBe(250.0)
        ->and((float) $lines[1]->credit)->toBe(0.0)
        ->and($lines[1]->contact_id)->toBeNull()
        ->and($lines->sum('debit'))->toEqual($lines->sum('credit'));
});

// --- contact-type enforcement ----------------------------------------------------------------------

test('a credit note can only be raised against a customer', function () {
    $scope = cdnScope('D');
    $supplier = prpLinkedContact($scope, 'supplier', '311-0001D', 'Not A Customer');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $supplier['id'], 100))
        ->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);

    expect(TAccount::query()->manualCreditDebitNotes()->count())->toBe(0);
});

test('a debit note can only be raised against a supplier', function () {
    $scope = cdnScope('E');
    $customer = prpLinkedContact($scope, 'customer', '101-0001E', 'Not A Supplier');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'DN', $customer['id'], 100))
        ->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);

    expect(TAccount::query()->manualCreditDebitNotes()->count())->toBe(0);
});

test('a contact who is both customer and supplier can receive either note type', function () {
    $scope = cdnScope('F');
    cdnSetting($scope['company_id'], true);
    $both = Contact::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'business_name' => 'Both Ways',
        'first_name' => '', 'last_name' => '', 'mobile' => '03007654321', 'address' => 'x', 'code' => 'BOTH-1',
        'user_type' => 'both', 'type' => 'local', 'ntn_number' => '1', 'pay_type' => 'day', 'credit_limit' => 0,
        'active' => true, 'link_account' => true, 'customer_gl_id' => '101-0001F', 'supplier_gl_id' => '311-0001F',
    ]);
    DB::table('chart_of_accounts')->insert([
        ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'code' => '101-0001F', 'name' => 'Both AR', 'acc_type' => 't', 'acc_nature' => 'dr', 'bs' => 1, 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'code' => '311-0001F', 'name' => 'Both AP', 'acc_type' => 't', 'acc_nature' => 'cr', 'bs' => 1, 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $both->id, 50))->assertSuccessful();
    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'DN', $both->id, 60))->assertSuccessful();

    expect(TAccount::query()->manualCreditDebitNotes()->count())->toBe(2);
});

test('raising a note against a contact with no linked chart of account is refused', function () {
    $scope = cdnScope('G');
    $customer = prpContact($scope, 'customer', 'Unlinked Customer');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer, 100))
        ->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
});

test('a contact from another company is refused, even with a plausible id', function () {
    $mine = cdnScope('H');
    $theirs = cdnScope('I');
    $foreign = prpLinkedContact($theirs, 'customer', '101-0001I', 'Foreign Customer');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/credit-debit-notes', cdnPayload($mine, 'CN', $foreign['id'], 100))
        ->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
});

// --- ref_no -----------------------------------------------------------------------------------------

test('the free-text reference is saved and shown back, and stays optional', function () {
    $scope = cdnScope('J');
    $customer = prpLinkedContact($scope, 'customer', '101-0001J', 'Ref Customer');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer['id'], 75, ['ref_no' => 'INV-9001']))->assertSuccessful();
    $note = TAccount::query()->manualCreditDebitNotes()->latest('id')->firstOrFail();

    expect($note->ref_no)->toBe('INV-9001')
        ->and($this->getJson("/api/credit-debit-notes/{$note->id}")->assertSuccessful()->json('ref_no'))->toBe('INV-9001')
        ->and($this->getJson('/api/credit-debit-notes')->assertSuccessful()->json('data.data.0.ref_no'))->toBe('INV-9001');

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer['id'], 20, ['ref_no' => null]))->assertSuccessful();
});

// --- approval flow -----------------------------------------------------------------------------------

test('a note is pending by default and does not affect balances until approved', function () {
    $scope = cdnScope('K');
    prpFinancialYear($scope['company_id']);
    $customer = prpLinkedContact($scope, 'customer', '101-0001K', 'Pending Customer');
    prpOpeningBalance($scope, $customer['coa_id'], 1000, 'dr');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer['id'], 300))->assertSuccessful();
    $note = TAccount::query()->manualCreditDebitNotes()->latest('id')->firstOrFail();

    expect($note->status)->toBe('pending')
        ->and(prpRow(prpGet('customer-outstanding', ['end_date' => '2026-09-30', 'show_record' => 100]), $customer['id'])['balance'])->toEqual(1000.0);
});

test('approving a pending note changes its status and its balance now takes effect', function () {
    $scope = cdnScope('L');
    prpFinancialYear($scope['company_id']);
    $customer = prpLinkedContact($scope, 'customer', '101-0001L', 'Approve Customer');
    prpOpeningBalance($scope, $customer['coa_id'], 1000, 'dr');
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer['id'], 300))->assertSuccessful();
    $note = TAccount::query()->manualCreditDebitNotes()->latest('id')->firstOrFail();

    $this->postJson("/api/credit-debit-note-approvals/{$note->id}/approve")->assertSuccessful();

    expect($note->refresh()->status)->toBe('approved')
        ->and($note->approved_by)->toBe(1)
        ->and(prpRow(prpGet('customer-outstanding', ['end_date' => '2026-09-30', 'show_record' => 100]), $customer['id'])['balance'])->toEqual(700.0);
});

test('rejecting a pending note keeps it out of balances and it cannot be approved afterwards', function () {
    $scope = cdnScope('M');
    prpFinancialYear($scope['company_id']);
    $customer = prpLinkedContact($scope, 'customer', '101-0001M', 'Reject Customer');
    prpOpeningBalance($scope, $customer['coa_id'], 1000, 'dr');
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer['id'], 300))->assertSuccessful();
    $note = TAccount::query()->manualCreditDebitNotes()->latest('id')->firstOrFail();

    $this->postJson("/api/credit-debit-note-approvals/{$note->id}/reject", ['reason' => 'Wrong customer'])->assertSuccessful();

    expect($note->refresh()->status)->toBe('rejected')
        ->and($note->comments)->toContain('Wrong customer')
        ->and(prpRow(prpGet('customer-outstanding', ['end_date' => '2026-09-30', 'show_record' => 100]), $customer['id'])['balance'])->toEqual(1000.0);

    $this->postJson("/api/credit-debit-note-approvals/{$note->id}/approve")->assertUnprocessable();
});

test('the company auto-approval toggle posts a new note as approved straight away', function () {
    $scope = cdnScope('N');
    cdnSetting($scope['company_id'], true);
    $customer = prpLinkedContact($scope, 'customer', '101-0001N', 'Auto Customer');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer['id'], 300))->assertSuccessful();

    expect(TAccount::query()->manualCreditDebitNotes()->latest('id')->firstOrFail()->status)->toBe('approved');
});

// --- permissions --------------------------------------------------------------------------------------

test('creating a note needs the add permission', function () {
    $scope = cdnScope('O');
    $customer = prpLinkedContact($scope, 'customer', '101-0001O', 'Perm Customer');
    Sanctum::actingAs(cdnUserWith($scope, []));

    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer['id'], 100))->assertForbidden();

    Sanctum::actingAs(cdnUserWith($scope, ['/creditdebitnote', '/creditdebitnote/add']));
    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer['id'], 100))->assertSuccessful();
});

test('the list needs its own permission and approving/rejecting need theirs', function () {
    $scope = cdnScope('P');
    $customer = prpLinkedContact($scope, 'customer', '101-0001P', 'Approver Customer');
    Sanctum::actingAs(cdnUserWith($scope, ['/creditdebitnote', '/creditdebitnote/add']));
    $this->postJson('/api/credit-debit-notes', cdnPayload($scope, 'CN', $customer['id'], 100))->assertSuccessful();
    $note = TAccount::query()->manualCreditDebitNotes()->latest('id')->firstOrFail();

    Sanctum::actingAs(cdnUserWith($scope, []));
    $this->getJson('/api/credit-debit-notes')->assertForbidden();

    $approver = cdnUserWith($scope, ['/creditdebitnote/:id/approve']);
    Sanctum::actingAs($approver);
    $this->postJson("/api/credit-debit-note-approvals/{$note->id}/reject")->assertForbidden();
    $this->postJson("/api/credit-debit-note-approvals/{$note->id}/approve")->assertSuccessful();
});
