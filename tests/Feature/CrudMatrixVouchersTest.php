<?php

use App\Models\TAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 5 of the second round: add, edit, view, delete and validation of the six voucher families (journal, payment, expense, deposit,
 * fund transfer, credit / debit note) the same way, plus another company's voucher being out of reach.
 */
// the credit / debit note carries a contact and its own account rules and is proved by CreditDebitNote tests
dataset('voucher-families', array_values(array_diff(array_keys(voucherFamilies()), ['creditdebitnote'])));

test('a voucher is added, validated, viewed, edited and deleted', function (string $family) {
    $config = voucherFamilies()[$family];
    $scope = jeaScope('c'.substr(md5($family), 0, 3));
    jeaSetting($scope['company_id'], $config['column'], false);
    Sanctum::actingAs(User::query()->findOrFail(1));

    // add + validation (an unbalanced voucher and a missing date are refused)
    $unbalanced = jeaPayload($scope, 1500, $config['type']);
    $unbalanced['taccountdetails'][1]['credit'] = 1400;
    $this->postJson($config['create'], $unbalanced)->assertUnprocessable();
    $this->postJson($config['create'], array_merge(jeaPayload($scope, 1500, $config['type']), ['voucher_date' => '']))->assertUnprocessable();
    $this->postJson($config['create'], jeaPayload($scope, 1500, $config['type']))->assertSuccessful();

    $voucher = TAccount::query()->where('company_id', $scope['company_id'])->latest('id')->firstOrFail();

    // view
    $this->getJson($config['create'].'/'.$voucher->id)->assertSuccessful();

    // edit
    $changed = array_merge(jeaPayload($scope, 1800, $config['type']), ['comments' => 'Edited '.$family]);
    $this->putJson($config['create'].'/'.$voucher->id, $changed)->assertSuccessful();
    expect($voucher->fresh()->comments)->toBe('Edited '.$family);

    // delete
    $this->deleteJson($config['create'].'/'.$voucher->id)->assertSuccessful();
    expect(TAccount::query()->find($voucher->id))->toBeNull();
})->with('voucher-families');

test('a voucher of one company is not found from another', function (string $family) {
    $config = voucherFamilies()[$family];
    $mine = jeaScope('m'.substr(md5($family), 0, 3));
    $other = jeaScope('o'.substr(md5($family), 0, 3));
    jeaSetting($mine['company_id'], $config['column'], false);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson($config['create'], jeaPayload($mine, 900, $config['type']))->assertSuccessful();
    $id = (int) TAccount::query()->where('company_id', $mine['company_id'])->latest('id')->value('id');

    Sanctum::actingAs(jeaUserWith($other, [$config['menu'], $config['menu'].'/:id/edit', $config['menu'].'/delete']));
    $this->getJson($config['create'].'/'.$id)->assertNotFound();
    $this->putJson($config['create'].'/'.$id, jeaPayload($other, 900, $config['type']))->assertNotFound();
    $this->deleteJson($config['create'].'/'.$id)->assertNotFound();
})->with('voucher-families');
