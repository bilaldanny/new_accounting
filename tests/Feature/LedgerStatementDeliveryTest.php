<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The ledger tab of a customer or supplier: the statement as a PDF, by email with the PDF attached, and by WhatsApp or SMS as a link
 * that works without signing in. The figures are the ones the ledger shows (ContactStatement uses ContactLedger).
 */
function lsdScope(): array
{
    $scope = seedSellScope();
    DB::table('contacts')->where('id', $scope['contact_id'])->update(['email' => 'sara@example.com', 'mobile' => '03007654321', 'business_name' => 'Acme Retail']);
    createSellRecord($scope, ['invoice_no' => 'LSD-1', 'final_amount' => 1500, 'transaction_date' => now()->toDateString()]);
    createSellRecord($scope, ['invoice_no' => 'LSD-2', 'final_amount' => 700, 'transaction_date' => now()->toDateString()]);

    return $scope;
}

function lsdQuery(array $scope, array $extra = []): string
{
    return http_build_query(['contact_id' => $scope['contact_id'], 'company_id' => $scope['company_id']] + $extra);
}

test('the statement downloads as a PDF with the party, the rows and the closing balance', function () {
    $scope = lsdScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->get('/api/fetchledger/pdf?'.lsdQuery($scope))->assertOk();
    $body = $response->getContent();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and(substr($body, 0, 5))->toBe('%PDF-')
        ->and($body)->toContain('Account statement')->and($body)->toContain('Acme Retail')
        ->and($body)->toContain('LSD-1')->and($body)->toContain('LSD-2')->and($body)->toContain('2,200.00');
});

test('the statement is only for a user who may open the party ledger, and only for the own company', function () {
    $scope = lsdScope();

    // no customer / supplier / ledger menu: refused
    $role = Role::query()->create(['name' => 'nobody'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id']]));
    $this->get('/api/fetchledger/pdf?'.lsdQuery($scope))->assertForbidden();
    $this->postJson('/api/contact-ledger/send', ['contact_id' => $scope['contact_id'], 'channel' => 'email'])->assertForbidden();

    // another company's user with the menu: the party is not theirs
    $otherCompany = DB::table('companies')->insertGetId(['code' => 'LSD-OTHER', 'name' => 'Other', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $otherRole = Role::query()->create(['name' => 'other'.uniqid(), 'company_id' => $otherCompany, 'is_active' => true]);
    grantMenuPermission($otherRole->id, '/customer', 'customer'.uniqid());
    Sanctum::actingAs(createStaffUserForRole($otherRole, ['company_id' => $otherCompany]));

    $this->get('/api/fetchledger/pdf?'.lsdQuery($scope))->assertNotFound();
    $this->postJson('/api/contact-ledger/send', ['contact_id' => $scope['contact_id'], 'channel' => 'email'])->assertNotFound();
});

test('email goes to the party with the PDF attached, to a typed address when one is given, and says when there is none', function () {
    config(['mail.default' => 'array']);
    $scope = lsdScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $messages = fn () => app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();

    $sent = $this->postJson('/api/contact-ledger/send', ['contact_id' => $scope['contact_id'], 'channel' => 'email', 'company_id' => $scope['company_id'], 'message' => 'Please clear the balance.'])->assertOk()->json();

    expect($sent['status'])->toBe('sent')->and($sent['recipient'])->toBe('sara@example.com')->and($sent['url'])->toContain('/ledger-statement');

    $mail = $messages()->last()->getOriginalMessage();
    $attachment = $mail->getAttachments()[0];

    expect($mail->getTo()[0]->getAddress())->toBe('sara@example.com')
        ->and($mail->getSubject())->toContain('Account statement')
        ->and($mail->getTextBody())->toContain('Please clear the balance.')
        ->and($attachment->getMediaType().'/'.$attachment->getMediaSubtype())->toBe('application/pdf')
        ->and(substr($attachment->getBody(), 0, 5))->toBe('%PDF-')->and($attachment->getBody())->toContain('LSD-1');

    $other = $this->postJson('/api/contact-ledger/send', ['contact_id' => $scope['contact_id'], 'channel' => 'email', 'recipient' => 'accounts@acme.example', 'company_id' => $scope['company_id']])->assertOk()->json();
    expect($other['recipient'])->toBe('accounts@acme.example')->and($messages()->last()->getOriginalMessage()->getTo()[0]->getAddress())->toBe('accounts@acme.example');

    DB::table('contacts')->where('id', $scope['contact_id'])->update(['email' => null]);
    $count = $messages()->count();
    $this->postJson('/api/contact-ledger/send', ['contact_id' => $scope['contact_id'], 'channel' => 'email', 'company_id' => $scope['company_id']])->assertUnprocessable()
        ->assertJsonPath('status', 'failed');
    expect($messages()->count())->toBe($count);
});

test('whatsapp and sms send a text with a signed link, and say when no provider is set up', function () {
    Http::fake(['hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $scope = lsdScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $payload = ['contact_id' => $scope['contact_id'], 'channel' => 'whatsapp', 'company_id' => $scope['company_id']];

    $none = $this->postJson('/api/contact-ledger/send', $payload)->assertStatus(502)->json();
    expect($none['status'])->toBe('not_configured')->and($none['error'])->toContain('provider');

    DB::table('messaging_settings')->insert(['company_id' => $scope['company_id'], 'channel' => 'whatsapp', 'provider' => 'webhook', 'endpoint' => 'https://hooks.example.test/wa', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $ok = $this->postJson('/api/contact-ledger/send', $payload)->assertOk()->json();
    expect($ok['status'])->toBe('sent')->and($ok['recipient'])->toBe('03007654321');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.example.test') && str_contains((string) json_encode($request->data()), 'ledger-statement'));

    // a number that is not a number is refused before anything is sent
    $this->postJson('/api/contact-ledger/send', $payload + ['recipient' => 'abc'])->assertUnprocessable();
});

test('the link in a message opens the PDF without signing in, only with its signature, and only for that party and period', function () {
    $scope = lsdScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $url = $this->postJson('/api/contact-ledger/send', ['contact_id' => $scope['contact_id'], 'channel' => 'email', 'company_id' => $scope['company_id']])->assertOk()->json('url');

    app('auth')->forgetGuards();
    $this->app['auth']->guard('sanctum')->forgetUser();

    $path = substr($url, strpos($url, '/ledger-statement'));
    $page = $this->get($path)->assertOk();
    expect($page->headers->get('Content-Type'))->toBe('application/pdf')->and(substr($page->getContent(), 0, 5))->toBe('%PDF-')->and($page->getContent())->toContain('Acme Retail');

    // a changed party or an unsigned link is refused
    $this->get(preg_replace('/contact=\d+/', 'contact=999', $path))->assertForbidden();
    $this->get('/ledger-statement?contact='.$scope['contact_id'].'&from=2026-01-01&to=2026-12-31')->assertForbidden();
});
