<?php

use App\Models\Company;
use App\Models\FbrSetting;
use App\Models\FbrSubmission;
use App\Models\Tax;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fbr\FbrInvoiceGateway;
use App\Services\Fbr\FbrResult;
use App\Services\Fbr\StubFbrGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * @param  array<string, mixed>  $scope
 */
function fbrEnable(array $scope, array $extra = []): void
{
    Company::query()->whereKey($scope['company_id'])->update(['ntn_no' => '1234567-8', 'strn_no' => 'STRN-001']);
    test()->putJson('/api/fbr-settings', array_merge(['company_id' => $scope['company_id'], 'enabled' => true, 'environment' => 'sandbox'], $extra))->assertSuccessful();
}

test('the gateway that is bound is the stub, and it sends nothing anywhere', function () {
    Http::fake();

    expect(app(FbrInvoiceGateway::class))->toBeInstanceOf(StubFbrGateway::class);

    $scope = seedSellScope();
    fbrEnable($scope);
    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();

    Http::assertNothingSent();
});

test('the settings start switched off and empty, and the secrets are stored encrypted and never returned', function () {
    $scope = seedSellScope();

    $this->getJson('/api/fbr-settings?company_id='.$scope['company_id'])->assertSuccessful()
        ->assertJsonPath('data.enabled', false)->assertJsonPath('data.environment', 'sandbox')
        ->assertJsonPath('data.has_password', false)->assertJsonPath('data.has_api_token', false)->assertJsonPath('data.live_integration', false);

    fbrEnable($scope, ['username' => 'pos-user', 'password' => 'secret-pass', 'api_token' => 'tok-123', 'pos_id' => 'POS-1']);

    $response = $this->getJson('/api/fbr-settings?company_id='.$scope['company_id'])->assertJsonPath('data.has_password', true)->assertJsonPath('data.has_api_token', true);
    expect($response->getContent())->not->toContain('secret-pass')->not->toContain('tok-123');

    $raw = DB::table('fbr_settings')->where('company_id', $scope['company_id'])->first();
    expect($raw->password)->not->toContain('secret-pass')->and($raw->api_token)->not->toContain('tok-123')
        ->and(FbrSetting::query()->where('company_id', $scope['company_id'])->first()->password)->toBe('secret-pass');

    // Empty fields keep the saved secrets; clearing removes them.
    $this->putJson('/api/fbr-settings', ['company_id' => $scope['company_id'], 'enabled' => true, 'environment' => 'sandbox'])->assertJsonPath('data.has_password', true);
    $this->putJson('/api/fbr-settings', ['company_id' => $scope['company_id'], 'enabled' => true, 'environment' => 'sandbox', 'clear_credentials' => true])->assertJsonPath('data.has_password', false)->assertJsonPath('data.has_api_token', false);
});

test('FBR cannot be switched on without the company NTN, and the environment is checked', function () {
    $scope = seedSellScope();

    $this->putJson('/api/fbr-settings', ['company_id' => $scope['company_id'], 'enabled' => true, 'environment' => 'sandbox'])->assertUnprocessable()->assertJsonValidationErrors(['enabled']);
    $this->putJson('/api/fbr-settings', ['company_id' => $scope['company_id'], 'enabled' => false, 'environment' => 'moon'])->assertUnprocessable()->assertJsonValidationErrors(['environment']);
});

test('a company that has not switched FBR on is not touched, a drafts is not recorded', function () {
    $scope = seedSellScope();

    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
    expect(FbrSubmission::query()->count())->toBe(0);

    fbrEnable($scope);
    $this->postJson('/api/sells', validSellPayload($scope, ['status' => 'draft']))->assertSuccessful();
    expect(FbrSubmission::query()->count())->toBe(0);
});

test('a posted sale is recorded as a stub with the invoice structure, and never as a real FBR number', function () {
    $scope = seedSellScope();
    fbrEnable($scope, ['pos_id' => 'POS-9']);
    Company::query()->whereKey($scope['company_id'])->update(['name' => 'Seller Co']);
    $tax = Tax::query()->create(['company_id' => $scope['company_id'], 'name' => 'GST 10', 'percentage' => 10, 'type' => 0, 'status' => true])->id;
    $payload = validSellPayload($scope);
    $payload['selllines'][0]['tax_id'] = $tax;

    $this->postJson('/api/sells', $payload)->assertSuccessful();

    $sell = Transaction::query()->where('type', 'sell')->latest('id')->firstOrFail();
    $submission = FbrSubmission::query()->where('transaction_id', $sell->id)->firstOrFail();

    expect($submission->status)->toBe('stub')
        ->and($submission->fbr_invoice_number)->toStartWith('FBR-STUB-')
        ->and($submission->attempts)->toBe(1)
        ->and($submission->submitted_at)->toBeNull()
        ->and($submission->payload['structure'])->toContain('NOT the FBR specification')
        ->and($submission->payload['seller'])->toMatchArray(['name' => 'Seller Co', 'ntn' => '1234567-8', 'strn' => 'STRN-001', 'pos_id' => 'POS-9'])
        ->and($submission->payload['buyer']['ntn'])->toBe('7654321')
        ->and($submission->payload['invoice']['number'])->toBe($sell->invoice_no)
        ->and($submission->payload['items'][0])->toMatchArray(['tax_rate' => 10.0, 'tax_amount' => 24.0, 'exempt' => false])
        ->and($submission->payload['totals'])->toMatchArray(['tax' => 24.0, 'total' => 304.0]);

    // The printed invoice carries no FBR number for a stub.
    $this->getJson("/api/sells/{$sell->id}")->assertJsonPath('print_settings.fbr_invoice_number', null);
});

test('a number FBR really issued is the only one the invoice prints', function () {
    $scope = seedSellScope();
    fbrEnable($scope);
    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
    $sell = Transaction::query()->where('type', 'sell')->latest('id')->firstOrFail();

    FbrSubmission::query()->where('transaction_id', $sell->id)->update(['status' => 'submitted', 'fbr_invoice_number' => 'REAL-123']);

    $this->getJson("/api/sells/{$sell->id}")->assertJsonPath('print_settings.fbr_invoice_number', 'REAL-123');

    // An accepted submission is not sent again.
    $this->postJson("/api/fbr-submissions/{$sell->id}")->assertSuccessful();
    expect(FbrSubmission::query()->where('transaction_id', $sell->id)->value('fbr_invoice_number'))->toBe('REAL-123');
});

test('a sale can be handed over again by hand and the tries are counted, only when FBR is on', function () {
    $scope = seedSellScope();
    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
    $sell = Transaction::query()->where('type', 'sell')->latest('id')->firstOrFail();

    $this->postJson("/api/fbr-submissions/{$sell->id}")->assertUnprocessable()->assertJsonValidationErrors(['fbr']);

    fbrEnable($scope);
    $this->postJson("/api/fbr-submissions/{$sell->id}")->assertSuccessful()->assertJsonPath('data.status', 'stub');
    $this->postJson("/api/fbr-submissions/{$sell->id}")->assertSuccessful();

    expect(FbrSubmission::query()->where('transaction_id', $sell->id)->value('attempts'))->toBe(2);
    $this->getJson('/api/fbr-submissions?company_id='.$scope['company_id'])->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.status', 'stub');
});

test('a gateway that throws is recorded as failed and does not break saving the sale', function () {
    $scope = seedSellScope();
    fbrEnable($scope);
    app()->bind(FbrInvoiceGateway::class, fn () => new class implements FbrInvoiceGateway
    {
        public function submit(array $payload, FbrSetting $settings): FbrResult
        {
            throw new RuntimeException('FBR unreachable');
        }
    });

    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();

    $submission = FbrSubmission::query()->firstOrFail();
    expect($submission->status)->toBe('failed')->and($submission->error)->toBe('FBR unreachable')->and($submission->fbr_invoice_number)->toBeNull();
});

test('switching FBR on without full credentials warns that it is only stub mode, and it is never reported as live', function () {
    $scope = seedSellScope();
    Company::query()->whereKey($scope['company_id'])->update(['ntn_no' => '1234567-8']);
    $payload = ['company_id' => $scope['company_id'], 'enabled' => true, 'environment' => 'sandbox'];

    $response = $this->putJson('/api/fbr-settings', $payload)->assertSuccessful();
    expect($response->json('warning'))->toContain('STUB mode')->toContain('FBR-STUB')
        ->and($response->json('data.stub_mode'))->toBeTrue()
        ->and($response->json('data.credentials_complete'))->toBeFalse()
        ->and($response->json('data.live_integration'))->toBeFalse();

    // Even with every field filled the integration stays a stub: the flags never say live.
    $full = $this->putJson('/api/fbr-settings', $payload + ['username' => 'u', 'password' => 'p', 'api_token' => 't', 'pos_id' => '1'])->assertSuccessful();
    expect($full->json('warning'))->toBeNull()
        ->and($full->json('data.credentials_complete'))->toBeTrue()
        ->and($full->json('data.stub_mode'))->toBeTrue()
        ->and($full->json('data.live_integration'))->toBeFalse();

    $off = $this->putJson('/api/fbr-settings', ['company_id' => $scope['company_id'], 'enabled' => false, 'environment' => 'sandbox'])->assertSuccessful();
    expect($off->json('warning'))->toBeNull();
});
