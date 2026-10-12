<?php

use App\Models\Discount;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountsVoucher;
use App\Services\Approvals\ApprovalWorkflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 2, Approval Center: workflows with levels, sequential and parallel approvers, trigger rules, delegation, inbox / outbox,
 * history, notifications and discount code approval. A document that matches no workflow keeps its single approval step.
 */
function w2Approver(array $scope, array $extra = []): User
{
    $role = Role::query()->create(['name' => 'w2_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach (['/journalentry/:id/approve', '/journalentry/:id/reject', '/journalentry', '/approvalcenter', '/approvalcenter/manage'] as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, $extra + ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now(), 'email' => 'w2-'.uniqid().'@example.test']);
}

function w2Voucher(array $scope, float $amount = 100, ?User $maker = null): int
{
    $voucher = app(AccountsVoucher::class)->post((int) $scope['company_id'], (int) $scope['branch_id'], '2026-10-05', [
        ['account_id' => $scope['cash_id'], 'debit' => $amount], ['account_id' => $scope['rent_id'], 'credit' => $amount],
    ], 'Workflow test');

    if ($maker !== null) {
        DB::table('t_accounts')->where('id', $voucher->id)->update(['created_by' => $maker->id]);
    }

    return (int) $voucher->id;
}

function w2Workflow(array $levels, array $extra = []): int
{
    return (int) test()->postJson('/api/approval-center/workflows', $extra + ['name' => 'Journals', 'document_type' => 'journal', 'levels' => $levels])->assertCreated()->json('id');
}

function w2Status(int $voucherId): string
{
    return (string) DB::table('t_accounts')->where('id', $voucherId)->value('status');
}

test('with no workflow the single approval step is exactly what it was', function () {
    $scope = fcoScope(autoApprove: false);
    $approver = w2Approver($scope);
    $voucher = w2Voucher($scope);
    Sanctum::actingAs($approver);

    $this->postJson("/api/journal-entry-approvals/{$voucher}/approve")->assertOk();

    expect(w2Status($voucher))->toBe('approved')->and(DB::table('approval_requests')->count())->toBe(0);
});

test('a two level workflow takes the approvers in order and only the last level approves the document', function () {
    $scope = fcoScope(autoApprove: false);
    $first = w2Approver($scope);
    $second = w2Approver($scope);
    $outsider = w2Approver($scope);
    $maker = w2Approver($scope);
    Sanctum::actingAs($first);
    w2Workflow([
        ['label' => 'Supervisor', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $first->id]]],
        ['label' => 'Finance', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $second->id]]],
    ]);
    $voucher = w2Voucher($scope, 100, $maker);

    // not their turn, and not an approver at all
    Sanctum::actingAs($second);
    $this->postJson("/api/journal-entry-approvals/{$voucher}/approve")->assertForbidden();
    Sanctum::actingAs($outsider);
    $this->postJson("/api/journal-entry-approvals/{$voucher}/approve")->assertForbidden();

    Sanctum::actingAs($first);
    $step = $this->postJson("/api/journal-entry-approvals/{$voucher}/approve")->assertOk();
    expect($step->json('complete'))->toBeFalse()->and($step->json('message'))->toContain('waiting for level 2')->and(w2Status($voucher))->toBe('pending');
    $this->postJson("/api/journal-entry-approvals/{$voucher}/approve")->assertForbidden();

    Sanctum::actingAs($second);
    $this->postJson("/api/journal-entry-approvals/{$voucher}/approve")->assertOk();
    expect(w2Status($voucher))->toBe('approved')
        ->and(DB::table('approval_actions')->orderBy('id')->pluck('level_no')->map(fn ($n): int => (int) $n)->all())->toBe([1, 2])
        ->and(DB::table('approval_requests')->value('status'))->toBe('approved');

    // the history and the creator's notice
    $history = $this->getJson('/api/approval-center/history')->assertOk()->json('data');
    expect($history)->toHaveCount(2)
        ->and(DB::table('app_notifications')->where('user_id', $maker->id)->where('kind', 'approval_approved')->count())->toBe(1);
});

test('a parallel level needs every approver and a rejection ends the workflow and rejects the document', function () {
    $scope = fcoScope(autoApprove: false);
    $a = w2Approver($scope);
    $b = w2Approver($scope);
    Sanctum::actingAs($a);
    w2Workflow([['label' => 'Both directors', 'mode' => 'all', 'approvers' => [['type' => 'user', 'id' => $a->id], ['type' => 'user', 'id' => $b->id]]]]);
    $voucher = w2Voucher($scope);
    $other = w2Voucher($scope);

    $first = $this->postJson("/api/journal-entry-approvals/{$voucher}/approve")->assertOk();
    expect($first->json('complete'))->toBeFalse()->and(w2Status($voucher))->toBe('pending');
    Sanctum::actingAs($b);
    $this->postJson("/api/journal-entry-approvals/{$voucher}/approve")->assertOk();
    expect(w2Status($voucher))->toBe('approved');

    // a rejection by an approver rejects the document and closes the request
    $this->postJson("/api/journal-entry-approvals/{$other}/reject", ['reason' => 'Wrong account'])->assertOk();
    expect(w2Status($other))->toBe('rejected')->and(DB::table('approval_requests')->where('document_id', $other)->value('status'))->toBe('rejected');
});

test('trigger rules choose the workflow by amount, and a small document keeps the single step', function () {
    $scope = fcoScope(autoApprove: false);
    $approver = w2Approver($scope);
    $director = w2Approver($scope);
    Sanctum::actingAs($approver);
    w2Workflow([['label' => 'Director', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $director->id]]]], ['min_amount' => 1000]);
    $big = w2Voucher($scope, 5000);
    $small = w2Voucher($scope, 50);

    $this->postJson("/api/journal-entry-approvals/{$big}/approve")->assertForbidden();
    $this->postJson("/api/journal-entry-approvals/{$small}/approve")->assertOk();
    expect(w2Status($small))->toBe('approved')->and(w2Status($big))->toBe('pending');
});

test('a delegate acts for an approver who is away, and the history says for whom', function () {
    $scope = fcoScope(autoApprove: false);
    $boss = w2Approver($scope);
    $deputy = w2Approver($scope);
    Sanctum::actingAs($boss);
    w2Workflow([['label' => 'Boss', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $boss->id]]]]);
    $voucher = w2Voucher($scope);

    Sanctum::actingAs($deputy);
    $this->postJson("/api/journal-entry-approvals/{$voucher}/approve")->assertForbidden();
    expect($this->getJson('/api/approval-center/inbox')->json('data'))->toBe([]);

    Sanctum::actingAs($boss);
    $this->postJson('/api/approval-center/delegations', ['to_user_id' => $deputy->id, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(3)->toDateString(), 'reason' => 'Leave'])->assertCreated();
    $this->postJson('/api/approval-center/delegations', ['to_user_id' => $boss->id, 'starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString()])->assertUnprocessable();

    Sanctum::actingAs($deputy);
    $inbox = $this->getJson('/api/approval-center/inbox')->assertOk()->json('data');
    expect($inbox)->toHaveCount(1)->and($inbox[0]['number'])->toStartWith('JV-');
    $this->postJson("/api/journal-entry-approvals/{$voucher}/approve")->assertOk();
    expect(w2Status($voucher))->toBe('approved')
        ->and((int) DB::table('approval_actions')->value('on_behalf_of'))->toBe((int) $boss->id);
});

test('the inbox shows a user only what waits for them, the outbox the progress of their own documents, and approvers are notified once', function () {
    $scope = fcoScope(autoApprove: false);
    $approver = w2Approver($scope);
    $other = w2Approver($scope);
    $maker = w2Approver($scope);
    Sanctum::actingAs($approver);
    w2Workflow([['label' => 'Approver', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $approver->id]]]]);
    w2Voucher($scope, 100, $maker);

    $workflows = app(ApprovalWorkflows::class);
    $first = $workflows->openPending((int) $scope['company_id']);
    $again = $workflows->openPending((int) $scope['company_id']);
    expect($first)->toBe(['opened' => 1, 'notified' => 1])->and($again)->toBe(['opened' => 0, 'notified' => 0])
        ->and(DB::table('app_notifications')->where('user_id', $approver->id)->where('kind', 'approval_waiting')->count())->toBe(1)
        ->and(DB::table('app_notifications')->where('user_id', $maker->id)->count())->toBe(0);

    expect($this->getJson('/api/approval-center/inbox')->json('data'))->toHaveCount(1);
    Sanctum::actingAs($other);
    expect($this->getJson('/api/approval-center/inbox')->json('data'))->toBe([]);
    Sanctum::actingAs($maker);
    $outbox = $this->getJson('/api/approval-center/outbox')->assertOk()->json('data');
    expect($outbox)->toHaveCount(1)->and($outbox[0]['status'])->toBe('pending')->and($outbox[0]['levels'])->toBe(1);
});

test('workflows are company scoped: foreign approvers are refused, the body company is ignored and nobody else sees them', function () {
    $scope = fcoScope(autoApprove: false);
    $otherCompany = fcoOtherCompany();
    $admin = w2Approver($scope);
    $foreign = createStaffUserForRole(Role::query()->create(['name' => 'w2f', 'company_id' => $otherCompany['company_id'], 'is_active' => true]), ['company_id' => $otherCompany['company_id'], 'branch_id' => $otherCompany['branch_id'], 'email_verified_at' => now()]);
    Sanctum::actingAs($admin);

    $this->postJson('/api/approval-center/workflows', ['name' => 'Bad', 'document_type' => 'journal', 'levels' => [['label' => 'L', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $foreign->id]]]]])->assertUnprocessable();
    $id = w2Workflow([['label' => 'L', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $admin->id]]]], ['company_id' => $otherCompany['company_id']]);
    expect((int) DB::table('approval_workflows')->where('id', $id)->value('company_id'))->toBe((int) $scope['company_id']);

    $stranger = w2Approver($otherCompany);
    Sanctum::actingAs($stranger);
    $this->getJson('/api/approval-center/workflows')->assertOk()->assertJsonCount(0, 'data');
    $this->putJson("/api/approval-center/workflows/{$id}", ['name' => 'xx', 'document_type' => 'journal', 'levels' => [['label' => 'L', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $stranger->id]]]]])->assertNotFound();
});

test('discount codes need approval once the company asks for it, and an unapproved code cannot be used', function () {
    $scope = fcoScope();
    $admin = w2Approver($scope);
    Sanctum::actingAs($admin);

    $plain = Discount::createDiscount(new Request(['company_id' => $scope['company_id'], 'name' => 'Plain', 'code' => 'PLAIN', 'discount_type' => 'percentage', 'value' => 10]));
    expect($plain->fresh()->approval_status)->toBe('approved')->and($plain->rejectionReason(100))->toBeNull();

    $this->putJson('/api/approval-center/settings', ['discount_code_approval' => true])->assertOk();
    $gated = Discount::createDiscount(new Request(['company_id' => $scope['company_id'], 'name' => 'Gated', 'code' => 'GATED', 'discount_type' => 'percentage', 'value' => 20]));
    expect($gated->fresh()->approval_status)->toBe('pending')->and($gated->fresh()->rejectionReason(100))->toBe('not_approved');

    $this->getJson('/api/approval-center/discount-codes')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/approval-center/discount-codes/{$gated->id}/approve")->assertOk();
    expect($gated->fresh()->rejectionReason(100))->toBeNull();
});
