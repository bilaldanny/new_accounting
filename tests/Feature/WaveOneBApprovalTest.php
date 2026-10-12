<?php

use App\Models\Role;
use App\Models\User;
use App\Services\AccountsVoucher;
use App\Services\Approvals\ApprovalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 1b, the Approval Center rows wave 2 left partial: the department trigger rule is evaluated (the department of the person who
 * made the document), price lists are covered by workflows like the other documents, and the editor has a directory of the people,
 * roles and departments to pick from.
 */
function w1baUser(array $scope, array $paths, array $extra = []): User
{
    $role = Role::query()->create(['name' => 'w1ba_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, $extra + ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now(), 'email' => 'w1ba-'.uniqid().'@example.test']);
}

function w1baDepartment(array $scope, string $name): int
{
    return (int) DB::table('departments')->insertGetId(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
}

test('a workflow with a department trigger applies only to documents made by people of that department', function () {
    $scope = fcoScope(autoApprove: false);
    $sales = w1baDepartment($scope, 'Sales');
    $stores = w1baDepartment($scope, 'Stores');
    $first = w1baUser($scope, ['/journalentry/:id/approve', '/approvalcenter', '/approvalcenter/manage']);
    $second = w1baUser($scope, ['/journalentry/:id/approve']);
    Sanctum::actingAs($first);

    $this->postJson('/api/approval-center/workflows', [
        'name' => 'Sales journals', 'document_type' => 'journal', 'department_id' => $sales,
        'levels' => [
            ['label' => 'Head', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $first->id]]],
            ['label' => 'Finance', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $second->id]]],
        ],
    ])->assertCreated();

    $make = function (int $department) use ($scope): int {
        $maker = w1baUser($scope, [], ['department_id' => $department]);
        $voucher = app(AccountsVoucher::class)->post($scope['company_id'], $scope['branch_id'], '2026-10-05', [['account_id' => $scope['cash_id'], 'debit' => 100], ['account_id' => $scope['rent_id'], 'credit' => 100]], 'Dept test');
        DB::table('t_accounts')->where('id', $voucher->id)->update(['created_by' => $maker->id]);

        return (int) $voucher->id;
    };

    $salesDocument = $make($sales);
    $storesDocument = $make($stores);

    // the sales document needs both levels, the stores document keeps the single step
    Sanctum::actingAs($second);
    $this->postJson("/api/journal-entry-approvals/{$salesDocument}/approve")->assertForbidden();
    $this->postJson("/api/journal-entry-approvals/{$storesDocument}/approve")->assertOk();
    expect(DB::table('t_accounts')->where('id', $storesDocument)->value('status'))->toBe('approved');

    Sanctum::actingAs($first);
    $this->postJson("/api/journal-entry-approvals/{$salesDocument}/approve")->assertOk()->assertJsonPath('complete', false);
    expect(DB::table('t_accounts')->where('id', $salesDocument)->value('status'))->toBe('pending');

    Sanctum::actingAs($second);
    $this->postJson("/api/journal-entry-approvals/{$salesDocument}/approve")->assertOk();
    expect(DB::table('t_accounts')->where('id', $salesDocument)->value('status'))->toBe('approved');
});

test('a price list can be put behind an approval workflow', function () {
    $scope = fcoScope();
    $first = w1baUser($scope, ['/pricelist/approval', '/approvalcenter', '/approvalcenter/manage']);
    $second = w1baUser($scope, ['/pricelist/approval']);
    $maker = w1baUser($scope, ['/pricelist']);
    $list = DB::table('price_lists')->insertGetId(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'date' => '2026-10-01', 'discount' => 5, 'status' => 'pending', 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(ApprovalRegistry::forPrefix('pricelist-approvals')['type'])->toBe('pricelist');

    Sanctum::actingAs($first);
    $this->postJson('/api/approval-center/workflows', [
        'name' => 'Price lists', 'document_type' => 'pricelist',
        'levels' => [
            ['label' => 'Sales head', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $first->id]]],
            ['label' => 'Director', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $second->id]]],
        ],
    ])->assertCreated();

    Sanctum::actingAs($second);
    $this->postJson("/api/pricelist-approvals/{$list}/approve")->assertForbidden();

    Sanctum::actingAs($first);
    $this->postJson("/api/pricelist-approvals/{$list}/approve")->assertOk();
    expect(DB::table('price_lists')->where('id', $list)->value('status'))->toBe('pending');

    Sanctum::actingAs($second);
    $this->postJson("/api/pricelist-approvals/{$list}/approve")->assertOk();
    expect(DB::table('price_lists')->where('id', $list)->value('status'))->toBe('approved');

    // the inbox lists a waiting price list for its current approver
    $another = DB::table('price_lists')->insertGetId(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'date' => '2026-10-02', 'discount' => 7, 'status' => 'pending', 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->artisan('approvals:notify-pending')->assertSuccessful();
    expect(DB::table('approval_requests')->where('document_type', 'pricelist')->where('document_id', $another)->exists())->toBeTrue();
});

test('the directory lists the people, roles and departments of the company only', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    w1baDepartment($scope, 'Sales');
    w1baDepartment($other, 'Foreign department');
    $foreign = w1baUser($other, []);
    Sanctum::actingAs(w1baUser($scope, ['/approvalcenter']));

    $data = $this->getJson('/api/approval-center/directory')->assertOk()->json('data');

    expect(collect($data['departments'])->pluck('name')->all())->toBe(['Sales'])->and(collect($data['users'])->pluck('id')->all())->not->toContain($foreign->id)
        ->and(count($data['roles']))->toBeGreaterThan(0);

    Sanctum::actingAs(w1baUser($scope, []));
    $this->getJson('/api/approval-center/directory')->assertForbidden();
});
