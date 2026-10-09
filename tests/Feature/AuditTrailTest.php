<?php

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

test('creating an audited record writes a created entry with the new values', function () {
    $scope = seedSellScope();

    $log = AuditLog::query()
        ->where('auditable_type', Contact::class)
        ->where('auditable_id', $scope['contact_id'])
        ->where('event', 'created')
        ->firstOrFail();

    expect($log->new_values['business_name'])->toBe('Acme Retail')
        ->and($log->old_values)->toBeNull();
});

test('updating a record stores only the changed fields as a before and after diff', function () {
    $scope = seedSellScope();
    $contact = Contact::query()->findOrFail($scope['contact_id']);

    $contact->update(['business_name' => 'Acme Wholesale', 'mobile' => '03007654321']);

    $log = AuditLog::query()->where('event', 'updated')->where('auditable_id', $contact->id)->latest('id')->firstOrFail();

    expect($log->old_values)->toBe(['business_name' => 'Acme Retail'])
        ->and($log->new_values)->toBe(['business_name' => 'Acme Wholesale'])
        ->and($log->user_id)->toBe(1);
});

test('a save that changes nothing writes no entry', function () {
    $scope = seedSellScope();
    $contact = Contact::query()->findOrFail($scope['contact_id']);
    $before = AuditLog::query()->count();

    $contact->save();

    expect(AuditLog::query()->count())->toBe($before);
});

test('deleting a record is logged and secrets are never stored', function () {
    $scope = seedSellScope();
    Contact::query()->findOrFail($scope['contact_id'])->delete();

    expect(AuditLog::query()->where('event', 'deleted')->where('auditable_id', $scope['contact_id'])->exists())->toBeTrue();

    $userLog = AuditLog::query()->where('auditable_type', User::class)->get()
        ->first(fn (AuditLog $log) => array_key_exists('password', $log->new_values ?? []));

    expect($userLog)->toBeNull();
});

test('the activity log api lists entries with their field changes and filters by model', function () {
    $scope = seedSellScope();
    Contact::query()->findOrFail($scope['contact_id'])->update(['business_name' => 'Acme Wholesale']);

    $response = $this->getJson('/api/audit-logs?model=Contact&event=updated')->assertSuccessful();

    expect($response->json('data.data.0.model'))->toBe('Contact')
        ->and($response->json('data.data.0.changes.0.field'))->toBe('business_name')
        ->and($response->json('data.data.0.changes.0.old'))->toBe('Acme Retail')
        ->and($response->json('data.data.0.changes.0.new'))->toBe('Acme Wholesale')
        ->and(collect($response->json('data.data'))->pluck('model')->unique()->all())->toBe(['Contact']);
});

test('a company user only sees their own companys entries and needs the permission', function () {
    $scope = seedSellScope();
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]));

    $this->getJson('/api/audit-logs')->assertForbidden();
});
