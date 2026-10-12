<?php

use App\Models\Activity;
use App\Models\Contact;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionInvoice;
use App\Models\CustomerSubscriptionPlan;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Rules\CompanyPasswordPolicy;
use App\Services\AccountsVoucher;
use App\Services\AppNotifier;
use App\Services\FollowUpReminders;
use App\Services\Security\IpRules;
use App\Services\Security\SessionRegistry;
use App\Services\SubscriptionBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 1: CRM (lead allocation, follow-up reminders), customer subscription billing (auto invoice, reminders, dunning, pro-rata
 * refund), User Management security (IP rules, login history, sessions, password policy, maker-checker, personal data) and the
 * dashboard insights. Each is inert until configured; the guarantee tests say so.
 */
function w1Users(array $scope, int $count): array
{
    return collect(range(1, $count))->map(fn (int $n): User => createStaffUserForRole(
        Role::query()->create(['name' => 'w1_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]),
        ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now(), 'email' => 'w1-'.uniqid().'@example.test'],
    ))->all();
}

// ---------------------------------------------------------------------------------------------------------------------
// CRM

test('a lead with no owner is allocated in turn from the rule pool, and nothing changes with no rule or with an owner', function () {
    $scope = fcoScope();
    [$a, $b] = w1Users($scope, 2);

    $plain = Lead::query()->create(['company_id' => $scope['company_id'], 'name' => 'No rule']);
    expect($plain->fresh()->assigned_to)->toBeNull();

    DB::table('lead_allocation_rules')->insert([
        'company_id' => $scope['company_id'], 'name' => 'Pool', 'strategy' => 'round_robin', 'user_ids' => json_encode([$a->id, $b->id]),
        'priority' => 10, 'is_active' => 1, 'cursor' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $owners = collect(['One', 'Two', 'Three'])->map(fn (string $name) => Lead::query()->create(['company_id' => $scope['company_id'], 'name' => $name])->fresh()->assigned_to)->all();
    $kept = Lead::query()->create(['company_id' => $scope['company_id'], 'name' => 'Owned', 'assigned_to' => $b->id]);

    expect($owners)->toBe([$a->id, $b->id, $a->id])->and($kept->fresh()->assigned_to)->toBe($b->id);
});

test('allocation rules by source apply only to that source and are company scoped through the API', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    [$a, $b] = w1Users($scope, 2);
    $source = DB::table('lead_sources')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Web', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(fcoStaff($scope, ['/leads', '/leads/:id/edit']));

    $this->postJson('/api/crm/allocation-rules', ['name' => 'Web leads', 'strategy' => 'by_source', 'lead_source_id' => $source, 'user_ids' => [$a->id], 'company_id' => $other['company_id']])->assertCreated();
    expect((int) DB::table('lead_allocation_rules')->value('company_id'))->toBe((int) $scope['company_id']);

    // a user of another company cannot join a pool
    $foreign = createStaffUserForRole(Role::query()->create(['name' => 'w1x', 'company_id' => $other['company_id'], 'is_active' => true]), ['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'email_verified_at' => now()]);
    $this->postJson('/api/crm/allocation-rules', ['name' => 'Bad', 'strategy' => 'round_robin', 'user_ids' => [$foreign->id]])->assertUnprocessable();

    $web = Lead::query()->create(['company_id' => $scope['company_id'], 'name' => 'From web', 'lead_source_id' => $source]);
    $walkIn = Lead::query()->create(['company_id' => $scope['company_id'], 'name' => 'Walk in']);
    expect($web->fresh()->assigned_to)->toBe($a->id)->and($walkIn->fresh()->assigned_to)->toBeNull();

    // unassigned leads can be allocated afterwards
    DB::table('lead_allocation_rules')->insert(['company_id' => $scope['company_id'], 'name' => 'All', 'strategy' => 'round_robin', 'user_ids' => json_encode([$b->id]), 'priority' => 200, 'is_active' => 1, 'cursor' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson('/api/crm/allocate-unassigned')->assertOk()->assertJsonPath('allocated', 1);
    expect($walkIn->fresh()->assigned_to)->toBe($b->id);
});

test('a follow-up due soon or overdue reminds its assignee once, in the app and by email, and a done one never', function () {
    Mail::fake();
    $scope = fcoScope();
    [$user] = w1Users($scope, 1);
    $make = fn (array $extra) => Activity::query()->create($extra + ['company_id' => $scope['company_id'], 'type' => 'call', 'subject' => 'Call back', 'assigned_to' => $user->id, 'created_by' => $user->id]);
    $soon = $make(['subject' => 'Soon', 'due_at' => now()->addHours(2)]);
    $late = $make(['subject' => 'Late', 'due_at' => now()->subDay()]);
    $far = $make(['subject' => 'Far', 'due_at' => now()->addDays(5)]);
    $done = $make(['subject' => 'Done', 'due_at' => now()->subDay()]);
    $done->forceFill(['completed_at' => now()])->save();

    $first = app(FollowUpReminders::class)->run(24);
    $again = app(FollowUpReminders::class)->run(24);

    expect($first['reminded'])->toBe(2)->and($again['reminded'])->toBe(0)
        ->and(DB::table('app_notifications')->where('user_id', $user->id)->count())->toBe(2)
        ->and($far->fresh()->reminded_at)->toBeNull()->and($done->fresh()->reminded_at)->toBeNull()
        ->and($soon->fresh()->reminded_at)->not->toBeNull() && expect($late->fresh()->reminded_at)->not->toBeNull();

    // the user reads only their own notifications
    Sanctum::actingAs($user);
    $this->getJson('/api/my-notifications')->assertOk()->assertJsonPath('unread', 2);
    $id = DB::table('app_notifications')->value('id');
    $this->postJson("/api/my-notifications/{$id}/read")->assertOk();
    [$stranger] = w1Users($scope, 1);
    Sanctum::actingAs($stranger);
    $this->postJson("/api/my-notifications/{$id}/read")->assertNotFound();
    expect(AppNotifier::available())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------------------------------
// Customer subscriptions

function w1Subscription(array $scope, string $periodStart = '2026-09-01', string $periodEnd = '2026-10-01'): CustomerSubscription
{
    $plan = CustomerSubscriptionPlan::query()->create(['company_id' => $scope['company_id'], 'name' => 'Gold', 'code' => 'GOLD'.uniqid(), 'billing_cycle' => 'monthly', 'price' => 300, 'setup_fee' => 0, 'trial_days' => 0]);
    DB::table('contacts')->where('id', $scope['customer_id'])->update(['email' => 'sub@example.test']);
    $subscription = CustomerSubscription::subscribe((int) $scope['company_id'], (int) $scope['customer_id'], $plan);
    $subscription->forceFill(['current_period_starts_at' => $periodStart, 'current_period_ends_at' => $periodEnd])->save();

    return $subscription;
}

test('billing generates the next invoice when a period ends, reminds before the due date and applies the dunning levels once', function () {
    Mail::fake();
    $scope = fcoScope();
    $subscription = w1Subscription($scope, '2026-09-01', now()->toDateString());
    $billing = app(SubscriptionBilling::class);

    $first = $billing->run((int) $scope['company_id']);
    expect($first['generated'])->toBe(1)->and(CustomerSubscriptionInvoice::query()->count())->toBe(1)
        ->and($billing->run((int) $scope['company_id'])['generated'])->toBe(0);

    // the new invoice is due at the end of its period (30 days on): move time instead of waiting
    $invoice = CustomerSubscriptionInvoice::query()->firstOrFail();
    $invoice->forceFill(['due_date' => now()->addDays(2)->toDateString()])->save();
    $soon = $billing->run((int) $scope['company_id']);
    expect($soon['upcoming'])->toBe(1);

    $invoice->forceFill(['due_date' => now()->subDays(10)->toDateString(), 'status' => 'overdue'])->save();
    $late = $billing->run((int) $scope['company_id']);
    $laterStill = $billing->run((int) $scope['company_id']);

    expect($late['dunning'])->toBe(1)->and($laterStill['dunning'])->toBe(0)
        ->and(DB::table('subscription_reminders')->pluck('kind')->sort()->values()->all())->toBe(['dunning_1', 'upcoming'])
        ->and($subscription->fresh()->status)->toBe('active');
});

test('cancelling refunds the unused part of the paid period pro rata, and only when asked', function () {
    $scope = fcoScope();
    $subscription = w1Subscription($scope, now()->subDays(10)->toDateString(), now()->addDays(20)->toDateString());
    $invoice = CustomerSubscriptionInvoice::generateForSubscription($subscription);
    $invoice->markPaid('cash', null, 300.0, null, null);
    Sanctum::actingAs(fcoStaff($scope, ['/customersubscriptions', '/customersubscriptions/:id/edit']));

    $quote = $this->getJson("/api/customer-subscriptions/{$subscription->id}/refund-quote")->assertOk();
    expect($quote->json('data.refund'))->toEqual(200)->and($quote->json('data.unused_days'))->toBe(20)->and($quote->json('data.period_days'))->toBe(30);

    $this->postJson("/api/customer-subscriptions/{$subscription->id}/cancel-prorated", ['refund' => true, 'reason' => 'Moving'])->assertOk()->assertJsonPath('data.refund', 200);
    expect($subscription->fresh()->status)->toBe('cancelled')->and((float) $invoice->fresh()->refunded_amount)->toBe(200.0);
    $this->postJson("/api/customer-subscriptions/{$subscription->id}/cancel-prorated", [])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// User Management

test('an IP allow list refuses other addresses, a deny rule refuses its own, and a rule cannot lock its author out', function () {
    $scope = fcoScope();
    $admin = fcoStaff($scope, ['/security', '/security/manage', '/dashboard']);
    Sanctum::actingAs($admin);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/security/ip-rules', ['type' => 'allow', 'address' => '198.51.100.0/24'])->assertUnprocessable();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/security/ip-rules', ['type' => 'allow', 'address' => '203.0.113.0/24'])->assertCreated();
    $this->postJson('/api/security/ip-rules', ['type' => 'allow', 'address' => 'not-an-ip'])->assertUnprocessable();

    $ips = app(IpRules::class);
    expect($ips->allows((int) $scope['company_id'], '203.0.113.77'))->toBeTrue()
        ->and($ips->allows((int) $scope['company_id'], '198.51.100.5'))->toBeFalse()
        ->and($ips->allows(null, '198.51.100.5'))->toBeTrue();

    $blocked = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])->getJson('/api/security/settings');
    expect($blocked->status())->toBe(403)->and($blocked->json('ip_blocked'))->toBeTrue()
        ->and(DB::table('login_events')->where('event', 'ip_blocked')->count())->toBe(1);

    // with no rules at all, every address passes (the guarantee)
    DB::table('ip_rules')->delete();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.5'])->getJson('/api/security/settings')->assertOk();
});

test('sign-ins and failures are logged, exported and company scoped, and sessions are listed and ended', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    [$user] = w1Users($scope, 1);
    $user->forceFill(['password' => Hash::make('Right-pass-1')])->save();

    $this->postJson('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertStatus(422);
    $this->postJson('/login', ['email' => $user->email, 'password' => 'Right-pass-1']);

    $events = DB::table('login_events')->pluck('event')->all();
    expect($events)->toContain('failed')->and($events)->toContain('login');

    $admin = fcoStaff($scope, ['/security', '/security/manage']);
    Sanctum::actingAs($admin);
    $list = $this->getJson('/api/security/login-events')->assertOk()->json('data');
    expect(collect($list)->pluck('event')->all())->toContain('login');
    expect($this->get('/api/security/login-events?export=csv')->assertOk()->streamedContent())->toContain('event');
    $this->getJson('/api/security/devices')->assertOk();

    // sessions: tracked per hashed id, ended by an admin, and another company cannot touch them
    DB::table('user_sessions')->insert(['company_id' => $scope['company_id'], 'user_id' => $user->id, 'session_hash' => hash('sha256', 'abc'), 'ip' => '1.1.1.1', 'device' => 'Chrome on Windows', 'last_activity' => now(), 'created_at' => now()]);
    $sessionId = (int) DB::table('user_sessions')->value('id');
    $this->getJson('/api/security/sessions')->assertOk()->assertJsonCount(1, 'data');
    Sanctum::actingAs(fcoStaff($other, ['/security', '/security/manage']));
    $this->postJson("/api/security/sessions/{$sessionId}/revoke")->assertNotFound();
    Sanctum::actingAs($admin);
    $this->postJson("/api/security/sessions/{$sessionId}/revoke")->assertOk();
    expect(DB::table('user_sessions')->where('id', $sessionId)->value('revoked_at'))->not->toBeNull();
    expect(SessionRegistry::device('Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537.36'))->toBe('Chrome on Windows');
});

test('the password policy enforces length, character classes and history once switched on, and stays out of the way until then', function () {
    $scope = fcoScope();
    [$user] = w1Users($scope, 1);
    $check = fn (string $password) => Validator::make(['password' => $password], ['password' => [new CompanyPasswordPolicy((int) $scope['company_id'], $user)]])->passes();

    expect($check('a'))->toBeTrue();

    DB::table('security_settings')->insert(['company_id' => $scope['company_id'], 'policy_enabled' => 1, 'min_length' => 10, 'require_number' => 1, 'require_mixed_case' => 1, 'require_symbol' => 1, 'expiry_days' => 30, 'history_count' => 2, 'created_at' => now(), 'updated_at' => now()]);

    expect($check('short1A!'))->toBeFalse()->and($check('longenoughbutplain'))->toBeFalse()->and($check('Long-enough-1A!'))->toBeTrue();

    // saving a password records it, and a recent one is refused
    $user->forceFill(['password' => Hash::make('Long-enough-1A!')])->save();
    expect(DB::table('password_histories')->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->password_changed_at)->not->toBeNull()
        ->and($check('Long-enough-1A!'))->toBeFalse()->and($check('Another-one-2B?'))->toBeTrue();
});

test('an expired password is sent to change it, and maker-checker stops a creator approving their own voucher', function () {
    $scope = fcoScope(autoApprove: false);
    $admin = fcoStaff($scope, ['/journalentry/:id/approve', '/journalentry/add', '/journalentry']);
    DB::table('security_settings')->insert(['company_id' => $scope['company_id'], 'maker_checker' => 1, 'policy_enabled' => 1, 'expiry_days' => 30, 'created_at' => now(), 'updated_at' => now()]);

    $voucher = app(AccountsVoucher::class);
    Sanctum::actingAs($admin);
    $entry = $voucher->post((int) $scope['company_id'], (int) $scope['branch_id'], '2026-10-01', [['account_id' => $scope['cash_id'], 'debit' => 10], ['account_id' => $scope['rent_id'], 'credit' => 10]], 'Mine');
    DB::table('t_accounts')->where('id', $entry->id)->update(['created_by' => $admin->id]);

    $this->postJson("/api/journal-entry-approvals/{$entry->id}/approve")->assertUnprocessable();
    expect(DB::table('t_accounts')->where('id', $entry->id)->value('status'))->toBe('pending');

    // another user approves it
    $checker = fcoStaff($scope, ['/journalentry/:id/approve', '/journalentry']);
    Sanctum::actingAs($checker);
    $this->postJson("/api/journal-entry-approvals/{$entry->id}/approve")->assertOk();

    // maker-checker off: the creator may (the old behaviour)
    DB::table('security_settings')->update(['maker_checker' => 0]);
    $second = $voucher->post((int) $scope['company_id'], (int) $scope['branch_id'], '2026-10-02', [['account_id' => $scope['cash_id'], 'debit' => 5], ['account_id' => $scope['rent_id'], 'credit' => 5]], 'Mine too');
    DB::table('t_accounts')->where('id', $second->id)->update(['created_by' => $admin->id]);
    Sanctum::actingAs($admin);
    $this->postJson("/api/journal-entry-approvals/{$second->id}/approve")->assertOk();

    // password expiry: older than the limit, everything but the password page is closed
    DB::table('users')->where('id', $admin->id)->update(['password_changed_at' => now()->subDays(45)]);
    Sanctum::actingAs($admin->fresh());
    $this->getJson('/api/journal-entry-approvals')->assertStatus(403)->assertJsonPath('password_expired', true);
});

test('personal data of a contact is exported and anonymised for good while the books stay, and a user can be anonymised', function () {
    $scope = fcoScope();
    $invoice = createSellRecord($scope, ['final_amount' => 100, 'invoice_no' => 'INV-PRIV']);
    DB::table('contacts')->where('id', $scope['customer_id'])->update(['email' => 'person@example.test', 'mobile' => '03001112223']);
    Sanctum::actingAs(fcoStaff($scope, ['/security', '/security/privacy']));
    $subject = ['subject_type' => 'contact', 'subject_id' => $scope['customer_id']];

    $export = $this->postJson('/api/security/privacy/export', $subject)->assertOk()->json('data');
    expect($export['profile']['email'])->toBe('person@example.test')->and(collect($export['documents'])->pluck('invoice_no')->all())->toContain('INV-PRIV');

    $this->postJson('/api/security/privacy/anonymize', $subject)->assertUnprocessable();
    $this->postJson('/api/security/privacy/anonymize', $subject + ['confirm' => true])->assertOk();
    $contact = Contact::query()->findOrFail($scope['customer_id']);
    expect($contact->email)->toBeNull()->and($contact->business_name)->toStartWith('Anonymised contact')
        ->and($invoice->fresh()->contact_id)->toBe((int) $scope['customer_id'])
        ->and(DB::table('privacy_requests')->pluck('kind')->all())->toBe(['export', 'anonymize']);
    $this->postJson('/api/security/privacy/anonymize', $subject + ['confirm' => true])->assertUnprocessable();

    [$user] = w1Users($scope, 1);
    $this->postJson('/api/security/privacy/anonymize', ['subject_type' => 'user', 'subject_id' => $user->id, 'confirm' => true])->assertOk();
    expect($user->fresh()->email)->toContain('@anonymised.invalid')->and((bool) $user->fresh()->is_active)->toBeFalse();

    // another company's contact cannot be reached
    $other = fcoOtherCompany();
    Sanctum::actingAs(fcoStaff($other, ['/security', '/security/privacy']));
    $this->postJson('/api/security/privacy/export', $subject)->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// Dashboard

test('the dashboard insights show the top customers and products, expenses and cash, export as CSV and keep a personal layout', function () {
    $scope = fcoScope();
    createSellRecord($scope, ['final_amount' => 500, 'invoice_no' => 'INV-D1', 'transaction_date' => now()->toDateString()]);
    app(AccountsVoucher::class)->post((int) $scope['company_id'], (int) $scope['branch_id'], now()->toDateString(), [['account_id' => $scope['rent_id'], 'debit' => 70], ['account_id' => $scope['cash_id'], 'credit' => 70]], 'Rent');
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $scope['cash_id']);
    Sanctum::actingAs(fcoStaff($scope, ['/sell', '/report/expense', '/chart-of-account']));

    $insights = $this->getJson('/api/dashboard-insights')->assertOk()->json('data');
    expect($insights['top_customers'][0]['amount'])->toEqual(500)->and($insights['expenses'][0]['amount'])->toEqual(70)
        ->and($insights['cash_flow']['out'])->toEqual(70);
    expect($this->get('/api/dashboard-insights?export=csv')->assertOk()->streamedContent())->toContain('top_customers');

    // a user without the sales menu gets no sales figures at all
    Sanctum::actingAs(fcoStaff($scope, ['/report/expense']));
    $this->getJson('/api/dashboard-insights')->assertOk()->assertJsonPath('data.top_customers', []);

    $this->putJson('/api/dashboard-preferences', ['order' => ['stats', 'sales'], 'hidden' => ['sales']])->assertOk();
    $this->putJson('/api/dashboard-preferences', ['order' => ['not-a-widget']])->assertUnprocessable();
    $this->getJson('/api/dashboard-preferences')->assertJsonPath('data.order', ['stats', 'sales']);
});
