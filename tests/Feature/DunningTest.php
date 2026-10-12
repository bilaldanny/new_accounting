<?php

use App\Mail\DunningMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Task C, item 6: invoice dunning with three levels, editable templates, due-days rules, email send, log and collection status.
 */
const DN_PERMS = ['/dunning', '/dunning/levels', '/dunning/send', '/dunning/case'];

function dnInvoice(array $scope, string $date = '2026-09-01', float $amount = 1000): int
{
    DB::table('contacts')->where('id', $scope['contact_id'])->update(['email' => 'customer@example.test']);

    return createSellRecord($scope, ['final_amount' => $amount, 'invoice_no' => 'INV-DN', 'transaction_date' => $date])->id;
}

test('an invoice reaches the level its days past due allow, is sent once through email and is logged', function () {
    Mail::fake();
    $scope = fcoScope();
    $invoice = dnInvoice($scope);
    Sanctum::actingAs(fcoStaff($scope, DN_PERMS));
    $vouchers = DB::table('t_accounts')->count();

    $this->getJson('/api/dunning/candidates?as_of=2026-09-10')->assertOk()->assertJsonCount(0, 'data');

    $early = $this->getJson('/api/dunning/candidates?as_of=2026-09-20')->assertOk()->json('data');
    expect($early)->toHaveCount(1)->and($early[0]['level'])->toBe(1)->and($early[0]['days_past_due'])->toBe(9);

    $late = $this->getJson('/api/dunning/candidates?as_of=2026-10-12')->assertOk()->json('data');
    expect($late[0]['level'])->toBe(2);

    $sent = $this->postJson('/api/dunning/send', ['channel' => 'email', 'as_of' => '2026-10-12', 'items' => [['transaction_id' => $invoice, 'level' => 2]]])->assertOk();
    expect($sent->json('data.sent'))->toBe(1);
    Mail::assertQueued(DunningMail::class, 1);

    $event = DB::table('dunning_events')->first();
    expect($event->status)->toBe('sent')->and($event->subject)->toContain('INV-DN')->and($event->body)->toContain('31 days')
        ->and((float) $event->amount)->toBe(1000.0);

    // not sent twice, and it is no longer a candidate for that level
    $again = $this->postJson('/api/dunning/send', ['channel' => 'email', 'as_of' => '2026-10-12', 'items' => [['transaction_id' => $invoice, 'level' => 2]]])->assertOk();
    expect($again->json('data.sent'))->toBe(0)->and($again->json('data.skipped'))->toBe(1);
    $this->getJson('/api/dunning/candidates?as_of=2026-10-12')->assertOk()->assertJsonCount(0, 'data');

    // dunning never posts
    expect(DB::table('t_accounts')->count())->toBe($vouchers);
});

test('a customer with no email address is recorded and can be reminded again, and a manual follow-up is logged', function () {
    Mail::fake();
    $scope = fcoScope();
    $invoice = createSellRecord($scope, ['final_amount' => 500, 'invoice_no' => 'INV-NOMAIL', 'transaction_date' => '2026-09-01'])->id;
    DB::table('contacts')->where('id', $scope['contact_id'])->update(['email' => null]);
    Sanctum::actingAs(fcoStaff($scope, DN_PERMS));

    $first = $this->postJson('/api/dunning/send', ['channel' => 'email', 'as_of' => '2026-09-20', 'items' => [['transaction_id' => $invoice, 'level' => 1]]])->assertOk();
    expect($first->json('data.sent'))->toBe(0)->and(DB::table('dunning_events')->first()->status)->toBe('no_email');
    Mail::assertNothingQueued();

    // not counted as sent: still a candidate, and a phone call can be logged for it
    $this->getJson('/api/dunning/candidates?as_of=2026-09-20')->assertOk()->assertJsonCount(1, 'data');
    $log = $this->postJson('/api/dunning/send', ['channel' => 'log', 'as_of' => '2026-09-20', 'items' => [['transaction_id' => $invoice, 'level' => 1]]])->assertOk();
    expect($log->json('data.logged'))->toBe(1);
    $this->getJson('/api/dunning/candidates?as_of=2026-09-20')->assertOk()->assertJsonCount(0, 'data');
});

test('templates are editable, ordered by days, and a disputed or promised customer is left out', function () {
    Mail::fake();
    $scope = fcoScope();
    dnInvoice($scope);
    Sanctum::actingAs(fcoStaff($scope, DN_PERMS));

    $levels = $this->getJson('/api/dunning/levels')->assertOk()->json('data');
    expect(array_column($levels, 'days_past_due'))->toBe([7, 21, 45]);

    $this->putJson('/api/dunning/levels/1', ['days_past_due' => 30])->assertUnprocessable();
    $this->putJson('/api/dunning/levels/1', ['days_past_due' => 3, 'subject' => 'Pay up: {invoice}'])->assertOk();
    $candidates = $this->getJson('/api/dunning/candidates?as_of=2026-09-15')->assertOk()->json('data');
    expect($candidates)->toHaveCount(1)->and($candidates[0]['level'])->toBe(1);

    $this->postJson('/api/dunning/cases', ['contact_id' => $scope['contact_id'], 'status' => 'promised'])->assertUnprocessable();
    $this->postJson('/api/dunning/cases', ['contact_id' => $scope['contact_id'], 'status' => 'promised', 'promised_on' => '2026-09-20'])->assertOk();
    $this->getJson('/api/dunning/candidates?as_of=2026-09-15')->assertOk()->assertJsonCount(0, 'data');
    // the promise has passed
    $this->getJson('/api/dunning/candidates?as_of=2026-09-25')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson('/api/dunning/cases', ['contact_id' => $scope['contact_id'], 'status' => 'disputed', 'note' => 'Wrong price'])->assertOk();
    $this->getJson('/api/dunning/candidates?as_of=2026-09-25')->assertOk()->assertJsonCount(0, 'data');
});

test('dunning is company scoped and needs its permissions', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $foreignContact = DB::table('contacts')->insertGetId([
        'company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'business_name' => 'Foreign', 'first_name' => 'F', 'mobile' => '0300',
        'address' => 'x', 'code' => 'CU-F', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs(fcoStaff($scope, ['/dunning']));

    $this->putJson('/api/dunning/levels/1', ['days_past_due' => 3])->assertForbidden();
    $this->postJson('/api/dunning/send', ['channel' => 'log', 'items' => [['transaction_id' => 1, 'level' => 1]]])->assertForbidden();

    Sanctum::actingAs(fcoStaff($scope, DN_PERMS));
    $this->postJson('/api/dunning/cases', ['contact_id' => $foreignContact, 'status' => 'closed'])->assertUnprocessable();
    $this->postJson('/api/dunning/cases', ['company_id' => $other['company_id'], 'contact_id' => $scope['contact_id'], 'status' => 'closed'])->assertOk();

    expect(DB::table('collection_cases')->where('company_id', $other['company_id'])->count())->toBe(0)
        ->and(DB::table('collection_cases')->where('company_id', $scope['company_id'])->count())->toBe(1);
});
