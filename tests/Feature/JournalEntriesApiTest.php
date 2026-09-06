<?php

use App\Models\Role;
use App\Models\TAccount;
use App\Models\TAccountDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{company_id: int, branch_id: int, debit_account_id: int, credit_account_id: int, debit_code: string, credit_code: string}
 */
function seedJournalScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'JRN001',
        'name' => 'Journal Test Company',
        'address' => '1 Ledger Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'JRB001',
        'company_id' => $companyId,
        'name' => 'Journal Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $debitAccountId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'code' => '101-00001',
        'name' => 'Cash in Hand',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $creditAccountId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'code' => '401-00001',
        'name' => 'Capital',
        'acc_type' => 't',
        'acc_nature' => 'cr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'debit_account_id' => $debitAccountId,
        'credit_account_id' => $creditAccountId,
        'debit_code' => '101-00001',
        'credit_code' => '401-00001',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validJournalPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_type' => 'JV',
        'voucher_date' => '2026-09-04',
        'comments' => 'Opening capital',
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'code' => $scope['debit_code'],
                'account_name' => 'Cash in Hand',
                'account_nature' => 'dr',
                'description' => 'Cash received',
                'debit' => 1500,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'code' => $scope['credit_code'],
                'account_name' => 'Capital',
                'account_nature' => 'cr',
                'description' => 'Owner capital',
                'debit' => 0,
                'credit' => 1500,
            ],
        ],
    ], $overrides);
}

test('journal entries api creates a balanced voucher with line items', function () {
    $scope = seedJournalScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/journal-entries', validJournalPayload($scope))
        ->assertSuccessful();

    $journal = TAccount::query()->manualJournals()->first();

    expect($journal)->not->toBeNull()
        ->and($journal->company_id)->toBe($scope['company_id'])
        ->and($journal->branch_id)->toBe($scope['branch_id'])
        ->and($journal->voucher_no)->toStartWith('JV-')
        ->and((float) $journal->total_amount)->toBe(1500.0)
        ->and($journal->status)->toBe('pending')
        ->and($journal->comments)->toBe('Opening capital');

    expect(TAccountDetail::query()->where('t_account_id', $journal->id)->count())->toBe(2)
        ->and((float) TAccountDetail::query()->where('t_account_id', $journal->id)->sum('debit'))
        ->toBe((float) TAccountDetail::query()->where('t_account_id', $journal->id)->sum('credit'));
});

test('journal entries api rejects an unbalanced voucher', function () {
    $scope = seedJournalScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/journal-entries', validJournalPayload($scope, [
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'debit' => 1500,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'debit' => 0,
                'credit' => 500,
            ],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['taccountdetails']);
});

test('journal entries index lists only manual journal vouchers', function () {
    $scope = seedJournalScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/journal-entries', validJournalPayload($scope))->assertSuccessful();

    TAccount::factory()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_no' => 'PE-00001',
        'voucher_date' => now(),
    ]);

    $response = $this->getJson('/api/journal-entries');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name'))->toBe('Journal Test Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Journal Branch')
        ->and($response->json('data.data.0.voucher_no'))->toStartWith('JV-');
});

test('journal entries api updates lines and can delete a voucher', function () {
    $scope = seedJournalScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/journal-entries', validJournalPayload($scope))->assertSuccessful();

    $journal = TAccount::query()->manualJournals()->firstOrFail();

    $this->putJson('/api/journal-entries/'.$journal->id, validJournalPayload($scope, [
        'comments' => 'Updated capital',
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'code' => $scope['debit_code'],
                'debit' => 2000,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'code' => $scope['credit_code'],
                'debit' => 0,
                'credit' => 2000,
            ],
        ],
    ]))->assertSuccessful();

    $journal->refresh();

    expect($journal->comments)->toBe('Updated capital')
        ->and((float) $journal->total_amount)->toBe(2000.0);

    $this->getJson('/api/journal-entries/'.$journal->id)
        ->assertSuccessful()
        ->assertJsonPath('taccountdetails.0.debit', 2000);

    $this->postJson('/api/journal-entries/bulk_delete', [$journal->id])->assertSuccessful();

    expect(TAccount::query()->find($journal->id))->toBeNull();
});

test('journal entries voucher number endpoint returns the next jv number', function () {
    $scope = seedJournalScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/journal-entries/voucher-no?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id'].'&type=JV')
        ->assertSuccessful()
        ->assertSee('JV-');
});

test('journal entries duplicate creates a fresh pending voucher with copied lines', function () {
    $scope = seedJournalScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/journal-entries', validJournalPayload($scope))->assertSuccessful();

    $original = TAccount::query()->manualJournals()->firstOrFail();
    $original->update(['status' => 'approved']);

    $this->postJson('/api/journal-entries/duplicate', ['id' => $original->id])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Duplicated');

    $journals = TAccount::query()->manualJournals()->orderBy('id')->get();

    expect($journals)->toHaveCount(2);

    $duplicate = $journals->last();

    expect($duplicate->id)->not->toBe($original->id)
        ->and($duplicate->voucher_no)->not->toBe($original->voucher_no)
        ->and($duplicate->voucher_no)->toStartWith('JV-')
        ->and($duplicate->status)->toBe('pending')
        ->and($duplicate->approved_by)->toBeNull()
        ->and($duplicate->approved_at)->toBeNull()
        ->and($duplicate->transaction_id)->toBeNull()
        ->and((float) $duplicate->total_amount)->toBe((float) $original->total_amount)
        ->and($duplicate->comments)->toBe($original->comments);

    $originalLines = TAccountDetail::query()->where('t_account_id', $original->id)->orderBy('id')->get();
    $duplicateLines = TAccountDetail::query()->where('t_account_id', $duplicate->id)->orderBy('id')->get();

    expect($duplicateLines)->toHaveCount($originalLines->count());

    foreach ($originalLines->values() as $index => $line) {
        expect((float) $duplicateLines[$index]->debit)->toBe((float) $line->debit)
            ->and((float) $duplicateLines[$index]->credit)->toBe((float) $line->credit)
            ->and($duplicateLines[$index]->account_code)->toBe($line->account_code);
    }
});

test('journal entries duplicate returns not found for a nonexistent voucher', function () {
    seedJournalScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/journal-entries/duplicate', ['id' => 999999])
        ->assertNotFound();
});

test('journal entries duplicate is forbidden without menu permission', function () {
    $scope = seedJournalScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/journal-entries', validJournalPayload($scope))->assertSuccessful();
    $original = TAccount::query()->manualJournals()->firstOrFail();

    $role = Role::query()->create([
        'name' => 'companyadmin',
        'company_id' => $scope['company_id'],
        'is_active' => true,
    ]);

    $user = createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/journal-entries/duplicate', ['id' => $original->id])
        ->assertForbidden();

    expect(TAccount::query()->manualJournals()->count())->toBe(1);
});
