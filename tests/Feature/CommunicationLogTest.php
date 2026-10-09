<?php

use App\Models\Activity;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Step 5, "Customer Communication Log": reuses the same `activities` table Step 4 built (type =
 * note/email is a communication-log entry, not a task), surfaced via `ActivityController::timeline()`
 * filtered by `contact_id` and rendered by the shared `ActivityTimeline.vue` component — now wired
 * into the previously-stub "Activities" tab on both `contact/customer/view.vue` and
 * `contact/supplier/view.vue`. These tests cover the contact-specific angle that
 * `ActivityApiTest.php`'s lead/opportunity-focused tests don't.
 */
function clCompany(string $code = 'CLOG001'): int
{
    return DB::table('companies')->insertGetId([
        'code' => $code,
        'name' => 'Company '.$code,
        'address' => '1 Test Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function clCustomer(int $companyId, array $attributes = []): Contact
{
    return Contact::query()->create(array_merge([
        'company_id' => $companyId,
        'business_name' => 'Walk-in Customer Co',
        'first_name' => 'Walk-in',
        'mobile' => '03001234567',
        'address' => 'Test address',
        'code' => 'CUST-'.random_int(10000, 99999),
        'user_type' => 'customer',
        'type' => 'local',
        'ntn_number' => '',
    ], $attributes));
}

test('a note logged against a customer contact is a communication-log entry, not a task', function () {
    $companyId = clCompany();
    $contact = clCustomer($companyId, ['business_name' => 'Raza Traders']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/activities', [
        'company_id' => $companyId,
        'contact_id' => $contact->id,
        'type' => 'note',
        'subject' => 'Called about overdue invoice',
        'description' => 'Promised to pay by Friday.',
    ])->assertSuccessful();

    $entry = Activity::query()->where('contact_id', $contact->id)->firstOrFail();

    expect($entry->type)->toBe('note')
        ->and($entry->lead_id)->toBeNull()
        ->and($entry->opportunity_id)->toBeNull();
});

test('the timeline for a contact returns only that contact\'s communication log, newest first', function () {
    $companyId = clCompany();
    $contact = clCustomer($companyId, ['business_name' => 'Raza Traders']);
    $otherContact = clCustomer($companyId, ['business_name' => 'Other Traders']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    Activity::query()->create(['company_id' => $companyId, 'contact_id' => $contact->id, 'type' => 'note', 'subject' => 'First contact']);
    Activity::query()->create(['company_id' => $companyId, 'contact_id' => $contact->id, 'type' => 'email', 'subject' => 'Sent statement']);
    Activity::query()->create(['company_id' => $companyId, 'contact_id' => $otherContact->id, 'type' => 'note', 'subject' => 'Unrelated note']);

    $response = $this->getJson('/api/activities/timeline?contact_id='.$contact->id)->assertSuccessful();

    expect(collect($response->json('data'))->pluck('subject')->all())->toBe(['Sent statement', 'First contact']);
});

test('email and note are both valid communication-log types, distinct from task-like types', function () {
    expect(Activity::TYPES)->toContain('note')
        ->and(Activity::TYPES)->toContain('email')
        ->and(Activity::TYPES)->toContain('call')
        ->and(Activity::TYPES)->toContain('meeting')
        ->and(Activity::TYPES)->toContain('task')
        ->and(Activity::TYPES)->toContain('follow_up');
});

test('a communication-log entry can also be completed/reopened like any other activity', function () {
    $companyId = clCompany();
    $contact = clCustomer($companyId);
    $entry = Activity::query()->create(['company_id' => $companyId, 'contact_id' => $contact->id, 'type' => 'email', 'subject' => 'Sent quote']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson("/api/activities/{$entry->id}/complete")->assertSuccessful();

    expect($entry->refresh()->completed_at)->not->toBeNull();
});
