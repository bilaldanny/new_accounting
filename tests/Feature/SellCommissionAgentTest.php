<?php

use App\Models\CommissionAgent;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function scaAgent(array $scope, array $attributes = []): CommissionAgent
{
    return CommissionAgent::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'name' => 'Imran Khan',
        'commission_percent' => 2.5,
        'is_active' => true,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function scaUpdatePayload(array $scope, Transaction $sell, array $overrides = []): array
{
    $payload = validSellPayload($scope, $overrides);
    $payload['selllines'][0]['id'] = $sell->selllines()->first()->id;

    return $payload;
}

test('the transactions table has a nullable commission agent column, indexed and keyed to commission agents', function () {
    $column = collect(DB::select("PRAGMA table_info('transactions')"))->firstWhere('name', 'commission_agent_id');
    $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('transactions')"))->where('from', 'commission_agent_id');

    expect($column)->not->toBeNull()
        ->and((int) $column->notnull)->toBe(0)
        ->and($foreignKeys)->toHaveCount(1)
        ->and($foreignKeys->first()->table)->toBe('commission_agents')
        ->and($foreignKeys->first()->on_delete)->toBe('SET NULL');
});

test('a sale is created with a commission agent, and the assignment changes no amount', function () {
    $scope = seedSellScope();
    $agent = scaAgent($scope, ['commission_percent' => 10]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', validSellPayload($scope, ['commission_agent_id' => $agent->id]))->assertSuccessful();

    $sell = Transaction::query()->sells()->firstOrFail();

    expect($sell->commission_agent_id)->toBe($agent->id)
        ->and($sell->commissionAgent->name)->toBe('Imran Khan')
        ->and((float) $sell->final_amount)->toBe(280.0);
});

test('a sale without a commission agent has none', function (mixed $value) {
    $scope = seedSellScope();
    scaAgent($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', validSellPayload($scope, ['commission_agent_id' => $value]))->assertSuccessful();

    expect(Transaction::query()->sells()->firstOrFail()->commission_agent_id)->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
]);

test('a sale created without the field at all has no commission agent', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();

    expect(Transaction::query()->sells()->firstOrFail()->commission_agent_id)->toBeNull();
});

test('a sale can be given, moved to another and cleared of a commission agent', function () {
    $scope = seedSellScope();
    $first = scaAgent($scope, ['name' => 'First Agent']);
    $second = scaAgent($scope, ['name' => 'Second Agent']);
    $sell = createSellRecord($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/sells/'.$sell->id, scaUpdatePayload($scope, $sell, ['commission_agent_id' => $first->id]))->assertSuccessful();
    expect($sell->refresh()->commission_agent_id)->toBe($first->id);

    $this->putJson('/api/sells/'.$sell->id, scaUpdatePayload($scope, $sell, ['commission_agent_id' => $second->id]))->assertSuccessful();
    expect($sell->refresh()->commission_agent_id)->toBe($second->id);

    $this->putJson('/api/sells/'.$sell->id, scaUpdatePayload($scope, $sell, ['commission_agent_id' => null]))->assertSuccessful();
    expect($sell->refresh()->commission_agent_id)->toBeNull();
});

test('a sale rejects an agent of another company, an inactive agent, a deleted agent and an unknown one', function (string $kind) {
    $scope = seedSellScope();
    $otherCompany = DB::table('companies')->insertGetId([
        'code' => 'SCA002', 'name' => 'Other Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $agentId = match ($kind) {
        'other company' => scaAgent($scope, ['company_id' => $otherCompany])->id,
        'inactive' => scaAgent($scope, ['is_active' => false])->id,
        'deleted' => tap(scaAgent($scope))->delete()->id,
        'unknown' => 999999,
    };

    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', validSellPayload($scope, ['commission_agent_id' => $agentId]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['commission_agent_id']);

    expect(Transaction::query()->sells()->count())->toBe(0);
})->with(['other company', 'inactive', 'deleted', 'unknown']);

test('a rejected agent on an update leaves the sale as it was', function () {
    $scope = seedSellScope();
    $agent = scaAgent($scope, ['is_active' => false]);
    $sell = createSellRecord($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/sells/'.$sell->id, scaUpdatePayload($scope, $sell, [
        'commission_agent_id' => $agent->id,
        'invoice_no' => 'INV-CHANGED',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['commission_agent_id']);

    $sell->refresh();

    expect($sell->commission_agent_id)->toBeNull()
        ->and($sell->invoice_no)->toBe('INV-00001');
});

test('editing a sale keeps its agent even after the agent was switched off or deleted', function (string $change) {
    $scope = seedSellScope();
    $agent = scaAgent($scope);
    $sell = createSellRecord($scope, ['commission_agent_id' => $agent->id]);

    if ($change === 'inactive') {
        $agent->update(['is_active' => false]);
    } else {
        $agent->delete();
    }

    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/sells/'.$sell->id, scaUpdatePayload($scope, $sell, [
        'commission_agent_id' => $agent->id,
        'invoice_no' => 'INV-EDITED',
    ]))->assertSuccessful();

    $sell->refresh();

    expect($sell->commission_agent_id)->toBe($agent->id)
        ->and($sell->invoice_no)->toBe('INV-EDITED');
})->with(['inactive', 'deleted']);

test('an edit from a client that does not send the agent leaves the sale agent as it is', function () {
    $scope = seedSellScope();
    $agent = scaAgent($scope);
    $sell = createSellRecord($scope, ['commission_agent_id' => $agent->id]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/sells/'.$sell->id, scaUpdatePayload($scope, $sell, ['invoice_no' => 'INV-POS-EDIT']))->assertSuccessful();

    $sell->refresh();

    expect($sell->commission_agent_id)->toBe($agent->id)
        ->and($sell->invoice_no)->toBe('INV-POS-EDIT');

    $this->putJson('/api/sells/'.$sell->id, scaUpdatePayload($scope, $sell, ['commission_agent_id' => '']))->assertSuccessful();

    expect($sell->refresh()->commission_agent_id)->toBeNull();
});

test('sells show returns the commission agent id and name', function () {
    $scope = seedSellScope();
    $agent = scaAgent($scope, ['name' => 'Imran Khan']);
    $sell = createSellRecord($scope, ['commission_agent_id' => $agent->id]);
    $plain = createSellRecord($scope, ['invoice_no' => 'INV-00002']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/sells/'.$sell->id)
        ->assertSuccessful()
        ->assertJsonPath('commission_agent_id', $agent->id)
        ->assertJsonPath('commission_agent_name', 'Imran Khan');

    $this->getJson('/api/sells/'.$plain->id)
        ->assertSuccessful()
        ->assertJsonPath('commission_agent_id', null)
        ->assertJsonPath('commission_agent_name', null);
});

test('deleting an agent for good leaves the sale and clears its agent', function () {
    $scope = seedSellScope();
    $agent = scaAgent($scope);
    $sell = createSellRecord($scope, ['commission_agent_id' => $agent->id]);

    $agent->forceDelete();

    expect(Transaction::query()->find($sell->id))->not->toBeNull()
        ->and($sell->refresh()->commission_agent_id)->toBeNull();
});

test('the commission agents the sale form offers are the active ones of the sale company', function () {
    $scope = seedSellScope();
    scaAgent($scope, ['name' => 'Offered Agent']);
    scaAgent($scope, ['name' => 'Retired Agent', 'is_active' => false]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $rows = $this->getJson('/api/fetchcommissionagents?company_id='.$scope['company_id'])->assertSuccessful()->json();

    expect(collect($rows)->pluck('text')->all())->toBe(['Offered Agent']);
});
