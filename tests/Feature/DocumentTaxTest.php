<?php

use App\Models\Company;
use App\Models\Tax;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

function dtTax(int $companyId, string $name, float $rate, array $extra = []): int
{
    return Tax::query()->create(array_merge(['company_id' => $companyId, 'name' => $name, 'percentage' => $rate, 'type' => 0, 'status' => true], $extra))->id;
}

function dtLatest(string $type): Transaction
{
    return Transaction::query()->where('type', $type)->latest('id')->firstOrFail()->load('selllines', 'purchaselines');
}

/**
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function dtSellPayload(array $scope, ?int $taxId, array $overrides = []): array
{
    $payload = validSellPayload($scope, $overrides);

    if ($taxId !== null) {
        $payload['selllines'][0]['tax_id'] = $taxId;
    }

    return $payload;
}

test('an exclusive tax is added on top of a sale, line by line, and reaches the journal', function () {
    $scope = seedSellScope();
    $tax = dtTax($scope['company_id'], 'GST 10', 10);

    $this->postJson('/api/sells', dtSellPayload($scope, $tax))->assertSuccessful();

    $sell = dtLatest('sell');
    expect((float) $sell->tax_amount)->toBe(24.0)
        ->and($sell->tax_id)->toBe($tax)
        ->and($sell->tax_inclusive)->toBeFalse()
        ->and((float) $sell->total_before_tax)->toBe(240.0)
        ->and((float) $sell->final_amount)->toBe(304.0)
        ->and($sell->selllines->first()->tax_id)->toBe($tax)
        ->and((float) $sell->selllines->first()->tax_amount)->toBe(24.0)
        ->and((float) DB::table('t_accounts')->latest('id')->value('total_tax'))->toBe(24.0);
});

test('a form that already added the tax is not charged twice', function () {
    $scope = seedSellScope();
    $tax = dtTax($scope['company_id'], 'GST 10', 10);

    $this->postJson('/api/sells', dtSellPayload($scope, $tax, ['final_amount' => 304, 'tax_amount' => 24]))->assertSuccessful();

    expect((float) dtLatest('sell')->final_amount)->toBe(304.0);
});

test('inclusive prices carve the tax out and leave the payable total alone', function () {
    $scope = seedSellScope();
    $tax = dtTax($scope['company_id'], 'GST 10', 10);

    $this->postJson('/api/sells', dtSellPayload($scope, $tax, ['tax_inclusive' => true, 'tax_amount' => 21.82]))->assertSuccessful();

    $sell = dtLatest('sell');
    expect($sell->tax_inclusive)->toBeTrue()
        ->and((float) $sell->tax_amount)->toBe(21.82)
        ->and((float) $sell->total_before_tax)->toBe(218.18)
        ->and((float) $sell->final_amount)->toBe(280.0);
});

test('a group is added or compounded in its order, and a fractional rate works', function () {
    $scope = seedSellScope();
    $sales = dtTax($scope['company_id'], 'Sales tax', 5);
    $levy = dtTax($scope['company_id'], 'Levy', 10);
    $added = dtTax($scope['company_id'], 'Added', 15, ['type' => 1, 'sub_tax' => [$sales, $levy]]);
    $stacked = dtTax($scope['company_id'], 'Stacked', 15.5, ['type' => 1, 'compound' => true, 'sub_tax' => [$sales, $levy]]);
    $half = dtTax($scope['company_id'], 'Half', 0.5);

    $this->postJson('/api/sells', dtSellPayload($scope, $added))->assertSuccessful();
    expect((float) dtLatest('sell')->tax_amount)->toBe(36.0);

    $this->postJson('/api/sells', dtSellPayload($scope, $stacked))->assertSuccessful();
    expect((float) dtLatest('sell')->tax_amount)->toBe(37.2);

    $this->postJson('/api/sells', dtSellPayload($scope, $half))->assertSuccessful();
    expect((float) dtLatest('sell')->tax_amount)->toBe(1.2);

    // Compound and inclusive: 240 / (1.05 * 1.10) = 207.79 before tax.
    $this->postJson('/api/sells', dtSellPayload($scope, $stacked, ['tax_inclusive' => true]))->assertSuccessful();
    expect((float) dtLatest('sell')->tax_amount)->toBe(32.21);
});

test('a document discount is shared between the lines before the tax is worked out', function () {
    $scope = seedSellScope();
    $tax = dtTax($scope['company_id'], 'GST 10', 10);

    $this->postJson('/api/sells', dtSellPayload($scope, $tax, ['discount_type' => 'fixed', 'discount_amount' => 24, 'final_amount' => 256]))->assertSuccessful();

    $sell = dtLatest('sell');
    expect((float) $sell->tax_amount)->toBe(21.6)
        ->and((float) $sell->total_before_tax)->toBe(216.0)
        ->and((float) $sell->final_amount)->toBe(277.6);
});

test('a sale whose lines name no tax keeps the single amount the client sent', function () {
    $scope = seedSellScope();

    $this->postJson('/api/sells', dtSellPayload($scope, null, ['tax_amount' => 30]))->assertSuccessful();

    $sell = dtLatest('sell');
    expect((float) $sell->tax_amount)->toBe(30.0)
        ->and((float) $sell->final_amount)->toBe(280.0)
        ->and($sell->selllines->first()->tax_id)->toBeNull()
        ->and($sell->selllines->first()->tax_amount)->toBeNull();
});

test('a tax of another company, an unknown one or a withholding tax is refused and nothing is saved', function () {
    $scope = seedSellScope();
    $other = Company::query()->create(['code' => 'CO-DT-02', 'name' => 'Other', 'is_active' => true, 'max_users' => 5, 'max_branches' => 1]);
    $before = Transaction::query()->count();

    foreach ([dtTax($other->id, 'Foreign', 10), 999999, dtTax($scope['company_id'], 'WHT', 4, ['kind' => 'withholding'])] as $bad) {
        $this->postJson('/api/sells', dtSellPayload($scope, $bad))->assertUnprocessable()->assertJsonValidationErrors(['selllines.0.tax_id']);
    }

    expect(Transaction::query()->count())->toBe($before);
});

test('editing a sale works its tax out again, and taking the tax off the lines goes back to the header amount', function () {
    $scope = seedSellScope();
    $tax = dtTax($scope['company_id'], 'GST 10', 10);

    $this->postJson('/api/sells', dtSellPayload($scope, $tax))->assertSuccessful();
    $id = dtLatest('sell')->id;

    $this->putJson("/api/sells/{$id}", dtSellPayload($scope, dtTax($scope['company_id'], 'GST 5', 5)))->assertSuccessful();
    $sell = dtLatest('sell');
    expect((float) $sell->tax_amount)->toBe(12.0)->and((float) $sell->final_amount)->toBe(292.0);

    $this->putJson("/api/sells/{$id}", dtSellPayload($scope, null))->assertSuccessful();
    $sell = dtLatest('sell');
    expect((float) $sell->tax_amount)->toBe(0.0)->and($sell->tax_id)->toBeNull()->and($sell->selllines->first()->tax_id)->toBeNull();

    $this->putJson("/api/sells/{$id}", dtSellPayload($scope, $tax))->assertSuccessful();
    $shown = $this->getJson("/api/sells/{$id}")->assertSuccessful()->json();
    expect($shown['selllines'][0]['tax_id'])->toBe($tax)->and((float) $shown['selllines'][0]['tax_amount'])->toBe(24.0);
});

test('a purchase takes its tax the same way', function () {
    $scope = seedPurchaseScope();
    $tax = dtTax($scope['company_id'], 'GST 10', 10);
    $payload = validPurchasePayload($scope);
    $payload['purchaselines'][0]['tax_id'] = $tax;

    $this->postJson('/api/purchases', $payload)->assertSuccessful();

    $purchase = dtLatest('purchaseorder');
    expect((float) $purchase->tax_amount)->toBe(20.0)
        ->and((float) $purchase->total_before_tax)->toBe(200.0)
        ->and((float) $purchase->final_amount)->toBe(270.0)
        ->and($purchase->purchaselines->first()->tax_id)->toBe($tax)
        ->and((float) $purchase->purchaselines->first()->tax_amount)->toBe(20.0);
});

test('the preview answers with the same figures the saved document gets', function () {
    $scope = seedSellScope();
    $sales = dtTax($scope['company_id'], 'Sales tax', 5);
    $levy = dtTax($scope['company_id'], 'Levy', 10);
    $stacked = dtTax($scope['company_id'], 'Stacked', 15.5, ['type' => 1, 'compound' => true, 'sub_tax' => [$sales, $levy]]);

    $this->postJson('/api/taxes/preview', [
        'company_id' => $scope['company_id'],
        'lines' => [['tax_id' => $stacked, 'amount' => 240], ['tax_id' => null, 'amount' => 100]],
    ])->assertSuccessful()
        ->assertJsonPath('tax_total', 37.2)
        ->assertJsonPath('exclusive_tax', 37.2)
        ->assertJsonPath('lines.0.tax_amount', 37.2)
        ->assertJsonPath('lines.1.tax_id', null);

    $this->postJson('/api/taxes/preview', ['lines' => 'nope'])->assertUnprocessable();
});
