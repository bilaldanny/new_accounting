<?php

use App\Models\SellLine;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access issue notes', function () {
    $this->getJson('/api/issue-notes')
        ->assertUnauthorized();
});

test('issue notes api creates a goods issue from an approved sell', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, [
        'status' => 'approved',
        'invoice_no' => 'INV-APPROVED',
    ]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/issue-notes', validIssueNotePayload($scope, $sell))
        ->assertSuccessful();

    $note = Transaction::query()->issueNotes()->where('parent_id', $sell->id)->first();
    $sell->refresh();
    $line = $sell->selllines()->first();

    expect($note)->not->toBeNull()
        ->and($note->type)->toBe(Transaction::TYPE_ISSUE_NOTE)
        ->and($note->status)->toBe('issue')
        ->and($note->invoice_no)->not->toBeEmpty()
        ->and($sell->status)->toBe('issue')
        ->and((float) $line->quantity_issue)->toBe(1.0);
});

test('issue notes api rejects a final sell invoice', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/issue-notes', validIssueNotePayload($scope, $sell))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_id']);
});

test('issue notes api rejects issue quantity above the sold quantity', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/issue-notes', validIssueNotePayload($scope, $sell, [
        'selllines' => [
            [
                'id' => $sell->selllines()->first()->id,
                'quantity_issue' => 9,
            ],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['selllines']);
});

test('issue notes eligible sells returns only approved invoices without a gin', function () {
    $scope = seedSellScope();
    createSellRecord($scope, ['invoice_no' => 'INV-FINAL']);
    $approved = createSellRecord($scope, [
        'invoice_no' => 'INV-ELIGIBLE',
        'status' => 'approved',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/issue-notes/eligible-sells?'.http_build_query([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
    ]));

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.invoice_no'))->toBe('INV-ELIGIBLE')
        ->and($response->json('0.id'))->toBe($approved->id);
});

test('issue notes index returns the sell invoice number', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, [
        'status' => 'approved',
        'invoice_no' => 'INV-00001',
    ]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/issue-notes', validIssueNotePayload($scope, $sell))
        ->assertSuccessful();

    $response = $this->getJson('/api/issue-notes');

    $response->assertSuccessful();
    expect($response->json('data.data.0.sell_order_no'))->toBe('INV-00001')
        ->and($response->json('data.data.0.customer_name'))->toBe('Acme Retail')
        ->and($response->json('data.data.0.status'))->toBe('issue');
});

test('issue notes show returns issued line quantities', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/issue-notes', validIssueNotePayload($scope, $sell))
        ->assertSuccessful();

    $note = Transaction::query()->issueNotes()->where('parent_id', $sell->id)->firstOrFail();

    $response = $this->getJson('/api/issue-notes/'.$note->id);

    $response->assertSuccessful();
    expect($response->json('transaction_id'))->toBe($sell->id)
        ->and($response->json('sell_order_no'))->toBe('INV-00001')
        ->and($response->json('selllines.0.product_name'))->toBe('Premium Basmati Rice')
        ->and((float) $response->json('selllines.0.quantity_issue'))->toBe(1.0);
});

test('issue notes sell lines endpoint returns sold quantities', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/issue-notes/sell/'.$sell->id);

    $response->assertSuccessful();
    expect($response->json('id'))->toBe($sell->id)
        ->and($response->json('selllines.0.id'))->toBe($sell->selllines()->first()->id)
        ->and((float) $response->json('selllines.0.quantity'))->toBe(1.0);
});

test('issue notes api can update issued quantities', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, ['status' => 'approved']);
    $line = $sell->selllines()->first();
    $line->quantity = 4;
    $line->save();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/issue-notes', validIssueNotePayload($scope, $sell, [
        'selllines' => [
            ['id' => $line->id, 'quantity_issue' => 4],
        ],
    ]))->assertSuccessful();

    $note = Transaction::query()->issueNotes()->where('parent_id', $sell->id)->firstOrFail();

    $this->putJson('/api/issue-notes/'.$note->id, [
        'selllines' => [
            ['id' => $line->id, 'quantity_issue' => 2],
        ],
    ])->assertSuccessful();

    expect((float) SellLine::query()->findOrFail($line->id)->quantity_issue)->toBe(2.0);
});

test('issue notes api can soft delete a note and restore the sell', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/issue-notes', validIssueNotePayload($scope, $sell))
        ->assertSuccessful();

    $note = Transaction::query()->issueNotes()->where('parent_id', $sell->id)->firstOrFail();

    $this->deleteJson('/api/issue-notes/'.$note->id)
        ->assertSuccessful();

    $sell->refresh();
    $line = $sell->selllines()->first();

    expect(Transaction::query()->find($note->id))->toBeNull()
        ->and(Transaction::onlyTrashed()->find($note->id))->not->toBeNull()
        ->and($sell->status)->toBe('approved')
        ->and((float) $line->quantity_issue)->toBe(0.0);
});
