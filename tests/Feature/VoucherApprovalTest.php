<?php

use App\Http\Controllers\VoucherApprovalController;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TAccount;
use App\Models\TAccountDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Approval for payments, expenses, deposits and fund transfers: the same workflow as journal entries
 * (JournalEntryApprovalTest), served by one controller. Journal entries are included where a test is
 * about the boundary between families. Helpers: tests/Support/voucherApproval.php.
 */
dataset('voucher families', [
    'payment' => ['payment', 'payments'],
    'expense' => ['expense', 'expenses'],
    'deposit' => ['deposit', 'deposits'],
    'fund transfer' => ['fundtransfer', 'fund transfers'],
]);

// ------------------------------------------------------------------ auto approval

test('each voucher family has its own toggle and it starts off for a new company', function (string $family) {
    $scope = jeaScope();
    $column = voucherFamilies()[$family]['column'];

    expect(CompanySetting::AUTO_APPROVAL_COLUMNS[$family])->toBe($column);

    $setting = CompanySetting::createCompanySettings($scope['company_id'], 'Fresh company');

    expect((bool) $setting->fresh()->{$column})->toBeFalse()
        ->and(CompanySetting::autoApproves($scope['company_id'], $family))->toBeFalse();

    jeaSetting($scope['company_id'], $column, true);
    expect(CompanySetting::autoApproves($scope['company_id'], $family))->toBeTrue();
})->with('voucher families');

test('a voucher is approved on save when its toggle is on and pending when it is off', function (string $family) {
    $scope = jeaScope();
    $column = voucherFamilies()[$family]['column'];

    $off = jeaVoucher($scope, $family);

    jeaSetting($scope['company_id'], $column, true);
    $on = jeaVoucher($scope, $family, status: 'approved');

    expect($off->status)->toBe('pending')
        ->and($on->status)->toBe('approved')
        ->and($on->approved_by)->toBeNull();
})->with('voucher families');

test('the superadmin follows the toggle of the voucher family too', function (string $family) {
    $scope = jeaScope();
    $column = voucherFamilies()[$family]['column'];
    $type = voucherFamilies()[$family]['type'];
    Sanctum::actingAs(User::query()->findOrFail(1));

    jeaSetting($scope['company_id'], $column, true);
    expect(TAccount::resolveStatus($scope['company_id'], $type))->toBe('approved');

    jeaSetting($scope['company_id'], $column, false);
    expect(TAccount::resolveStatus($scope['company_id'], $type))->toBe('pending');
})->with('voucher families');

test('one toggle never approves another voucher family', function () {
    $scope = jeaScope();
    jeaSetting($scope['company_id'], 'expense_approval', true);
    Sanctum::actingAs(User::query()->findOrFail(1));

    expect(TAccount::resolveStatus($scope['company_id'], 'EXP'))->toBe('approved')
        ->and(TAccount::resolveStatus($scope['company_id'], 'BP'))->toBe('pending')
        ->and(TAccount::resolveStatus($scope['company_id'], 'BD'))->toBe('pending')
        ->and(TAccount::resolveStatus($scope['company_id'], 'FT'))->toBe('pending')
        ->and(TAccount::resolveStatus($scope['company_id'], 'JV'))->toBe('pending');
});

test('every prefix of a family follows the same toggle', function () {
    $scope = jeaScope();
    jeaSetting($scope['company_id'], 'payment_voucher_approval', true);
    jeaSetting($scope['company_id'], 'deposit_approval', true);

    foreach (['BP', 'CP', 'OP', 'BD', 'CD', 'OD'] as $type) {
        expect(TAccount::resolveStatus($scope['company_id'], $type))->toBe('approved');
    }
});

test('the toggles are per company', function () {
    $one = jeaScope('1');
    $two = jeaScope('2');
    jeaSetting($one['company_id'], 'payment_voucher_approval', true);
    jeaSetting($two['company_id'], 'payment_voucher_approval', false);

    expect(CompanySetting::autoApproves($one['company_id'], 'payment'))->toBeTrue()
        ->and(CompanySetting::autoApproves($two['company_id'], 'payment'))->toBeFalse();
});

test('a duplicated voucher starts in the status its own toggle decides', function (string $family) {
    $scope = jeaScope();
    $column = voucherFamilies()[$family]['column'];
    $original = jeaVoucher($scope, $family, 'approved');
    $create = voucherFamilies()[$family]['create'];

    $this->postJson($create.'/duplicate', ['id' => $original->id])->assertSuccessful();
    expect(TAccount::query()->manualFamily($family)->latest('id')->first()->status)->toBe('pending');

    jeaSetting($scope['company_id'], $column, true);

    $this->postJson($create.'/duplicate', ['id' => $original->id])->assertSuccessful();
    expect(TAccount::query()->manualFamily($family)->latest('id')->first()->status)->toBe('approved');
})->with('voucher families');

test('the migration switches the toggles on for existing companies and leaves new ones off', function () {
    $scope = jeaScope();
    jeaSetting($scope['company_id'], 'expense_approval', false);

    $migration = require database_path('migrations/2026_09_20_100000_add_voucher_approval_toggles_to_company_settings_table.php');
    $migration->down();

    expect(Schema::hasColumn('company_settings', 'expense_approval'))->toBeFalse();

    $migration->up();

    $existing = CompanySetting::query()->where('company_id', $scope['company_id'])->first();

    expect((bool) $existing->payment_voucher_approval)->toBeTrue()
        ->and((bool) $existing->expense_approval)->toBeTrue()
        ->and((bool) $existing->deposit_approval)->toBeTrue()
        ->and((bool) $existing->fund_transfer_approval)->toBeTrue();

    $newCompany = jeaScope('2');
    $fresh = CompanySetting::createCompanySettings($newCompany['company_id'], 'New company');

    expect((bool) $fresh->fresh()->expense_approval)->toBeFalse();
});

test('the company settings carry the four toggles both ways', function () {
    $scope = jeaScope();
    $company = Company::query()->findOrFail($scope['company_id']);
    $setting = CompanySetting::createCompanySettings($scope['company_id'], 'Settings company');

    $response = CompanySetting::formatForResponse($setting->fresh(), $company);

    expect($response)->toMatchArray([
        'payment_voucher_approval' => false,
        'expense_approval' => false,
        'deposit_approval' => false,
        'fund_transfer_approval' => false,
    ]);

    CompanySetting::updateFromRequest(Request::create('/', 'PUT', [
        'expense_approval' => true,
        'fund_transfer_approval' => true,
    ]), $setting, $company);
    $setting->save();

    expect(CompanySetting::formatForResponse($setting->fresh(), $company))->toMatchArray([
        'payment_voucher_approval' => false,
        'expense_approval' => true,
        'deposit_approval' => false,
        'fund_transfer_approval' => true,
    ]);
});

test('the company settings api validates the new toggles as booleans', function () {
    $scope = jeaScope();
    CompanySetting::createCompanySettings($scope['company_id'], 'Settings company');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/company-settings/'.$scope['company_id'], ['expense_approval' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expense_approval');
});

// ------------------------------------------------------------------ approve and reject

test('approving a pending voucher records who approved it and when', function (string $family) {
    $scope = jeaScope();
    $voucher = jeaVoucher($scope, $family);
    $endpoint = voucherFamilies()[$family]['approvals'];

    $this->postJson("{$endpoint}/{$voucher->id}/approve")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Approved');

    $voucher->refresh();

    expect($voucher->status)->toBe('approved')
        ->and($voucher->approved_by)->toBe(1)
        ->and($voucher->approved_at)->not->toBeNull()
        ->and($voucher->rejected_by)->toBeNull();
})->with('voucher families');

test('rejecting a pending voucher records who rejected it and keeps the reason', function (string $family) {
    $scope = jeaScope();
    $voucher = jeaVoucher($scope, $family);
    $endpoint = voucherFamilies()[$family]['approvals'];

    $this->postJson("{$endpoint}/{$voucher->id}/reject", ['reason' => 'Wrong account'])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Rejected');

    $voucher->refresh();

    expect($voucher->status)->toBe('rejected')
        ->and($voucher->rejected_by)->toBe(1)
        ->and($voucher->rejected_at)->not->toBeNull()
        ->and($voucher->approved_by)->toBeNull()
        ->and($voucher->comments)->toBe("Opening capital\nRejected: Wrong account");
})->with('voucher families');

test('only a pending voucher can be decided and the message names the family', function (string $family, string $plural) {
    $scope = jeaScope();
    $endpoint = voucherFamilies()[$family]['approvals'];

    foreach (['approved', 'rejected', 'cancelled'] as $status) {
        $voucher = jeaVoucher($scope, $family, $status);

        $this->postJson("{$endpoint}/{$voucher->id}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', "Only pending {$plural} can be approved.");

        $this->postJson("{$endpoint}/{$voucher->id}/reject")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', "Only pending {$plural} can be rejected.");

        expect($voucher->fresh()->status)->toBe($status);
    }
})->with('voucher families');

test('an unbalanced voucher cannot be approved', function (string $family) {
    $scope = jeaScope();
    $voucher = jeaVoucher($scope, $family);

    TAccountDetail::query()->where('t_account_id', $voucher->id)->where('credit', '>', 0)->update(['credit' => 1400]);

    $this->postJson(voucherFamilies()[$family]['approvals']."/{$voucher->id}/approve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('taccountdetails');

    expect($voucher->fresh()->status)->toBe('pending');
})->with('voucher families');

test('a voucher can only be decided through its own family endpoint', function () {
    $scope = jeaScope();
    $payment = jeaVoucher($scope, 'payment');
    $expense = jeaVoucher($scope, 'expense');

    $this->postJson("/api/expense-approvals/{$payment->id}/approve")->assertNotFound();
    $this->postJson("/api/payment-approvals/{$expense->id}/reject")->assertNotFound();
    $this->postJson("/api/journal-entry-approvals/{$payment->id}/approve")->assertNotFound();
    $this->postJson("/api/deposit-approvals/{$expense->id}/approve")->assertNotFound();
    $this->getJson("/api/fund-transfer-approvals/{$payment->id}")->assertNotFound();

    expect($payment->fresh()->status)->toBe('pending')
        ->and($expense->fresh()->status)->toBe('pending');
});

test('the approval endpoints reject guests and unknown ids', function (string $family) {
    $endpoint = voucherFamilies()[$family]['approvals'];

    $this->getJson($endpoint)->assertUnauthorized();
    $this->postJson("{$endpoint}/1/approve")->assertUnauthorized();
    $this->postJson("{$endpoint}/1/reject")->assertUnauthorized();

    jeaScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson("{$endpoint}/9999/approve")->assertNotFound();
    $this->postJson("{$endpoint}/9999/reject")->assertNotFound();
    $this->getJson("{$endpoint}/9999")->assertNotFound();
})->with('voucher families');

test('a company user cannot decide another company voucher', function (string $family) {
    $mine = jeaScope('1');
    $theirs = jeaScope('2');
    $foreign = jeaVoucher($theirs, $family);
    $menu = voucherFamilies()[$family]['menu'];
    $endpoint = voucherFamilies()[$family]['approvals'];

    Sanctum::actingAs(jeaUserWith($mine, ["{$menu}/:id/approve", "{$menu}/:id/reject"]));

    $this->postJson("{$endpoint}/{$foreign->id}/approve")->assertNotFound();
    $this->postJson("{$endpoint}/{$foreign->id}/reject")->assertNotFound();

    expect($foreign->fresh()->status)->toBe('pending');
})->with('voucher families');

// ------------------------------------------------------------------ permissions

test('approving and rejecting need the family menu rows', function (string $family) {
    $scope = jeaScope();
    $menu = voucherFamilies()[$family]['menu'];
    $endpoint = voucherFamilies()[$family]['approvals'];
    $first = jeaVoucher($scope, $family);
    $second = jeaVoucher($scope, $family);

    Sanctum::actingAs(jeaUserWith($scope, []));
    $this->postJson("{$endpoint}/{$first->id}/approve")->assertForbidden();
    $this->postJson("{$endpoint}/{$first->id}/reject")->assertForbidden();

    $approver = jeaUserWith($scope, ["{$menu}/:id/approve"]);
    Sanctum::actingAs($approver);
    $this->postJson("{$endpoint}/{$first->id}/reject")->assertForbidden();
    $this->postJson("{$endpoint}/{$first->id}/approve")->assertSuccessful();

    $rejecter = jeaUserWith($scope, ["{$menu}/:id/reject"]);
    Sanctum::actingAs($rejecter);
    $this->postJson("{$endpoint}/{$second->id}/approve")->assertForbidden();
    $this->postJson("{$endpoint}/{$second->id}/reject")->assertSuccessful();

    expect($first->fresh()->status)->toBe('approved')
        ->and($first->fresh()->approved_by)->toBe($approver->id)
        ->and($second->fresh()->status)->toBe('rejected')
        ->and($second->fresh()->rejected_by)->toBe($rejecter->id);
})->with('voucher families');

test('the permission of one family does not open another family', function () {
    $scope = jeaScope();
    $payment = jeaVoucher($scope, 'payment');
    $expense = jeaVoucher($scope, 'expense');

    $expenseApprover = jeaUserWith($scope, ['/expense/:id/approve', '/expense/:id/reject', '/journalentry/:id/approve']);
    Sanctum::actingAs($expenseApprover);

    $this->postJson("/api/payment-approvals/{$payment->id}/approve")->assertForbidden();
    $this->postJson("/api/payment-approvals/{$payment->id}/reject")->assertForbidden();
    $this->postJson("/api/expense-approvals/{$expense->id}/approve")->assertSuccessful();

    expect($payment->fresh()->status)->toBe('pending');
});

test('the controller checks exactly the approve and reject rows the menu migration creates', function () {
    $migration = file_get_contents(database_path('migrations/2026_09_20_110000_add_voucher_approval_menus.php'));

    foreach (VoucherApprovalController::PERMISSIONS as $family => $keys) {
        if ($family === 'journal') {
            continue;
        }

        $menu = voucherFamilies()[$family]['menu'];

        expect($keys)->toBe(['approve' => "{$menu}/:id/approve", 'reject' => "{$menu}/:id/reject"])
            ->and($migration)->toContain("'{$menu}' =>");
    }

    expect(array_keys(VoucherApprovalController::PERMISSIONS))->toBe(array_keys(voucherFamilies()));
});

// ------------------------------------------------------------------ list and show

test('the approval list shows pending vouchers of its own family by default', function (string $family) {
    $scope = jeaScope();
    $endpoint = voucherFamilies()[$family]['approvals'];
    $pending = jeaVoucher($scope, $family);
    jeaVoucher($scope, $family, 'approved');
    jeaVoucher($scope, $family === 'expense' ? 'payment' : 'expense');
    jeaJournal($scope);

    $response = $this->getJson($endpoint)->assertSuccessful();

    expect(collect($response->json('data.data'))->pluck('id')->all())->toBe([$pending->id])
        ->and($response->json('data.data.0.status_label'))->toBe('Pending');
})->with('voucher families');

test('the approval list can filter approved and rejected vouchers', function (string $family) {
    $scope = jeaScope();
    $endpoint = voucherFamilies()[$family]['approvals'];
    jeaVoucher($scope, $family);
    $approved = jeaVoucher($scope, $family, 'approved');
    $rejected = jeaVoucher($scope, $family, 'rejected');

    $this->getJson("{$endpoint}?status=approved")->assertSuccessful()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.id', $approved->id);

    $this->getJson("{$endpoint}?status=rejected")->assertSuccessful()->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.id', $rejected->id)
        ->assertJsonPath('data.data.0.status_label', 'Rejected');

    $this->getJson("{$endpoint}?status=all")->assertSuccessful()->assertJsonCount(3, 'data.data');
})->with('voucher families');

test('the voucher list of each module can filter the rejected status', function (string $family) {
    $scope = jeaScope();
    $rejected = jeaVoucher($scope, $family, 'rejected');
    jeaVoucher($scope, $family);

    $response = $this->getJson(voucherFamilies()[$family]['create'].'?status=rejected')->assertSuccessful();

    expect(collect($response->json('data.data'))->pluck('id')->all())->toBe([$rejected->id]);
})->with('voucher families');

test('the approval list only shows the current company', function () {
    $mine = jeaScope('1');
    $theirs = jeaScope('2');
    $own = jeaVoucher($mine, 'payment');
    jeaVoucher($theirs, 'payment');

    Sanctum::actingAs(jeaUserWith($mine, []));

    $response = $this->getJson('/api/payment-approvals')->assertSuccessful();

    expect(collect($response->json('data.data'))->pluck('id')->all())->toBe([$own->id]);
});

test('a voucher reports whether it can be decided and who decided it', function (string $family) {
    $scope = jeaScope();
    $endpoint = voucherFamilies()[$family]['approvals'];
    $voucher = jeaVoucher($scope, $family);
    $other = jeaVoucher($scope, $family);

    $pending = $this->getJson("{$endpoint}/{$voucher->id}")->assertSuccessful();
    expect($pending->json('can_approve'))->toBeTrue()
        ->and($pending->json('status_label'))->toBe('Pending')
        ->and($pending->json('approved_by_name'))->toBeNull();

    $this->postJson("{$endpoint}/{$voucher->id}/approve")->assertSuccessful();
    $this->postJson("{$endpoint}/{$other->id}/reject")->assertSuccessful();

    $name = User::query()->find(1)->full_name;

    $approved = $this->getJson(voucherFamilies()[$family]['create']."/{$voucher->id}")->assertSuccessful();
    expect($approved->json('can_approve'))->toBeFalse()
        ->and($approved->json('status'))->toBe('approved')
        ->and($approved->json('approved_by_name'))->toBe($name)
        ->and($approved->json('approved_at'))->not->toBeNull();

    $rejected = $this->getJson("{$endpoint}/{$other->id}")->assertSuccessful();
    expect($rejected->json('can_approve'))->toBeFalse()
        ->and($rejected->json('rejected_by_name'))->toBe($name);
})->with('voucher families');

// ------------------------------------------------------------------ accounting effect

test('a pending voucher does not touch account balances until it is approved', function (string $family) {
    $scope = jeaScope();
    $voucher = jeaVoucher($scope, $family);

    expect(jeaNets($scope))->toBe([$scope['debit_code'] => 0.0, $scope['credit_code'] => 0.0]);

    $this->postJson(voucherFamilies()[$family]['approvals']."/{$voucher->id}/approve")->assertSuccessful();

    expect(jeaNets($scope))->toBe([$scope['debit_code'] => 1500.0, $scope['credit_code'] => 1500.0]);
})->with('voucher families');

test('a rejected voucher never reaches account balances', function (string $family) {
    $scope = jeaScope();
    $voucher = jeaVoucher($scope, $family);

    $this->postJson(voucherFamilies()[$family]['approvals']."/{$voucher->id}/reject", ['reason' => 'Duplicate'])->assertSuccessful();

    expect(jeaNets($scope))->toBe([$scope['debit_code'] => 0.0, $scope['credit_code'] => 0.0]);
})->with('voucher families');

test('an auto approved voucher counts towards balances straight away', function (string $family) {
    $scope = jeaScope();
    jeaSetting($scope['company_id'], voucherFamilies()[$family]['column'], true);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $config = voucherFamilies()[$family];
    $this->postJson($config['create'], jeaPayload($scope, 800, $config['type']))->assertSuccessful();

    expect(jeaNets($scope))->toBe([$scope['debit_code'] => 800.0, $scope['credit_code'] => 800.0]);
})->with('voucher families');

test('a pending voucher of another family is not counted when one is approved', function () {
    $scope = jeaScope();
    $payment = jeaVoucher($scope, 'payment', amount: 500);
    jeaVoucher($scope, 'expense', amount: 300);

    $this->postJson("/api/payment-approvals/{$payment->id}/approve")->assertSuccessful();

    expect(jeaNets($scope))->toBe([$scope['debit_code'] => 500.0, $scope['credit_code'] => 500.0]);
});

// ------------------------------------------------------------------ pages

dataset('approval pages', [
    'payment' => ['/acpayment', 'acpayment', 'approval/payment/index', 'payment/view', 'Payment Approval'],
    'expense' => ['/expense', 'expense', 'approval/expense/index', 'expense/view', 'Expense Approval'],
    'deposit' => ['/deposit', 'deposit', 'approval/deposit/index', 'deposit/view', 'Deposit Approval'],
    'fund transfer' => ['/fundtransfer', 'fundtransfer', 'approval/fundtransfer/index', 'fundtransfer/view', 'Fund Transfer Approval'],
]);

test('the approval pages render for a signed in user and guests are sent away', function (string $path, string $name, string $listPage, string $viewPage, string $title) {
    $this->get(route("{$name}.approval"))->assertRedirect();

    $user = User::query()->findOrFail(1);

    $this->actingAs($user)->get(route("{$name}.approval"))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($listPage));

    $this->actingAs($user)->get(route("{$name}.approval.view", 5))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component($viewPage)
            ->where('id', '5')
            ->where('returnTo', "{$path}/approval")
            ->where('listTitle', $title));

    $match = app('router')->getRoutes()->match(Request::create("{$path}/approval", 'GET'));
    expect($match->getName())->toBe("{$name}.approval");
})->with('approval pages');

test('the ordinary view pages still default to their own list', function (string $path, string $name, string $listPage, string $viewPage) {
    $this->actingAs(User::query()->findOrFail(1))->get(route("{$name}.view", 5))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($viewPage)->where('id', '5')->missing('returnTo'));
})->with('approval pages');

// ------------------------------------------------------------------ menu

function vaMenuMigration(): object
{
    return require database_path('migrations/2026_09_20_110000_add_voucher_approval_menus.php');
}

/**
 * A fresh test schema already holds menu rows from the seeding migrations; start from an empty menu tree.
 */
function vaClearMenus(): void
{
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
}

/**
 * The live shape: the Approval group with Purchase and Sell Approval, and the four voucher rows.
 *
 * @return array{group: int, vouchers: array<string, int>}
 */
function vaSeedMenus(bool $withApprovalGroup = true): array
{
    vaClearMenus();

    $row = fn (?int $parentId, string $name, string $path, int $type = 1, int $hidden = 0): int => DB::table('menus')->insertGetId([
        'parent_id' => $parentId, 'name' => $name, 'icon' => '', 'route_name' => ltrim(str_replace('/', '', $path), '/'), 'route_path' => $path,
        'menu_color' => '#000000', 'sort_order' => 1, 'is_hidden' => $hidden, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => $type, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $group = 0;

    if ($withApprovalGroup) {
        $group = $row(null, 'Approval', '', 2);
        $row($group, 'Purchase Approval', '/purchase/approval');
        $row($group, 'Sell Approval', '/sell/approval');
    }

    $accounts = $row(null, 'Accounts', '', 2);
    $vouchers = [];

    foreach (['/acpayment', '/expense', '/deposit', '/fundtransfer'] as $path) {
        $vouchers[$path] = $row($accounts, ucfirst(ltrim($path, '/')), $path);
    }

    return ['group' => $group, 'vouchers' => $vouchers];
}

test('the menu migration adds a list row in the approval group and hidden approve and reject rows per voucher', function () {
    $menus = vaSeedMenus();

    vaMenuMigration()->up();

    foreach ($menus['vouchers'] as $path => $voucherMenuId) {
        $list = DB::table('menus')->where('route_path', $path.'/approval')->first();

        expect($list)->not->toBeNull()
            ->and((int) $list->parent_id)->toBe($menus['group'])
            ->and((int) $list->is_hidden)->toBe(0)
            ->and((int) $list->is_active)->toBe(1);

        foreach (['/:id/approve', '/:id/reject'] as $suffix) {
            $hidden = DB::table('menus')->where('route_path', $path.$suffix)->first();

            expect($hidden)->not->toBeNull()
                ->and((int) $hidden->parent_id)->toBe($voucherMenuId)
                ->and((int) $hidden->is_hidden)->toBe(1);
        }
    }

    $view = DB::table('menus')->where('route_path', '/acpayment/:id/view')->first();
    expect($view)->not->toBeNull()
        ->and((int) $view->parent_id)->toBe($menus['vouchers']['/acpayment'])
        ->and((int) $view->is_hidden)->toBe(1);

    expect(DB::table('menus')->whereIn('route_path', ['/expense/:id/view', '/deposit/:id/view', '/fundtransfer/:id/view'])->exists())->toBeFalse();
});

test('the new list rows go after the existing approval rows', function () {
    $menus = vaSeedMenus();

    vaMenuMigration()->up();

    $orders = DB::table('menus')->where('parent_id', $menus['group'])->orderBy('sort_order')->pluck('route_path')->all();

    expect($orders)->toBe(['/purchase/approval', '/sell/approval', '/acpayment/approval', '/expense/approval', '/deposit/approval', '/fundtransfer/approval']);
});

test('the menu migration is idempotent and grants no permissions', function () {
    vaSeedMenus();
    Role::query()->create(['name' => 'companyadmin', 'is_active' => true]);

    vaMenuMigration()->up();
    $count = DB::table('menus')->count();
    vaMenuMigration()->up();

    expect(DB::table('menus')->count())->toBe($count)
        ->and(DB::table('permissions')->count())->toBe(0);
});

test('without the approval group only the hidden permission rows are added', function () {
    vaSeedMenus(withApprovalGroup: false);

    vaMenuMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '%/approval')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/expense/:id/approve')->exists())->toBeTrue();
});

test('a voucher whose menu row is missing gets nothing', function () {
    vaClearMenus();

    vaMenuMigration()->up();

    expect(DB::table('menus')->count())->toBe(0);
});

test('the menu migration rolls back with its permission rows', function () {
    vaSeedMenus();
    vaMenuMigration()->up();

    $menuId = (int) DB::table('menus')->where('route_path', '/expense/:id/approve')->value('id');
    $role = Role::query()->create(['name' => 'companyadmin', 'is_active' => true]);
    Permission::query()->create(['role_id' => $role->id, 'menu_id' => $menuId, 'status' => 1]);

    vaMenuMigration()->down();

    expect(DB::table('menus')->where('route_path', 'like', '%/approval')->where('route_path', '!=', '/purchase/approval')->where('route_path', '!=', '/sell/approval')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', 'like', '%/:id/approve')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/acpayment/:id/view')->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $menuId)->exists())->toBeFalse()
        ->and(DB::table('menus')->whereIn('route_path', ['/acpayment', '/expense', '/deposit', '/fundtransfer', '/purchase/approval', '/sell/approval'])->count())->toBe(6);
});
