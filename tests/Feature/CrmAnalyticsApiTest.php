<?php

use App\Models\Activity;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function craCompany(string $code = 'CRA001'): int
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

/**
 * @param  list<string>  $paths  the menu permissions the user's role is given
 */
function craStaff(int $companyId, array $paths = []): User
{
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $companyId, 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $companyId]);
}

test('conversion reports lead counts per status and the conversion rate', function () {
    $companyId = craCompany();
    Lead::query()->create(['company_id' => $companyId, 'name' => 'A', 'status' => 'new']);
    Lead::query()->create(['company_id' => $companyId, 'name' => 'B', 'status' => 'new']);
    Lead::query()->create(['company_id' => $companyId, 'name' => 'C', 'status' => 'qualified']);
    Lead::query()->create(['company_id' => $companyId, 'name' => 'D', 'status' => 'converted']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/crmanalytics/conversion?company_id='.$companyId)->assertSuccessful();

    $stages = collect($response->json('stages'))->keyBy('status');

    expect($stages['new']['count'])->toBe(2)
        ->and($stages['qualified']['count'])->toBe(1)
        ->and($stages['converted']['count'])->toBe(1)
        ->and($response->json('total_leads'))->toBe(4)
        ->and($response->json('converted'))->toBe(1)
        ->and((float) $response->json('conversion_rate'))->toBe(25.0);
});

test('pipeline reports open opportunity count and value per active stage, in board order', function () {
    $companyId = craCompany();
    $new = PipelineStage::query()->create(['company_id' => $companyId, 'name' => 'New', 'sort_order' => 1]);
    $won = PipelineStage::query()->create(['company_id' => $companyId, 'name' => 'Won', 'sort_order' => 2, 'is_won' => true]);
    PipelineStage::query()->create(['company_id' => $companyId, 'name' => 'Retired', 'sort_order' => 0, 'is_active' => false]);

    Opportunity::query()->create(['company_id' => $companyId, 'name' => 'Deal A', 'pipeline_stage_id' => $new->id, 'status' => 'open', 'deal_value' => 1000]);
    Opportunity::query()->create(['company_id' => $companyId, 'name' => 'Deal B', 'pipeline_stage_id' => $new->id, 'status' => 'open', 'deal_value' => 2000]);
    Opportunity::query()->create(['company_id' => $companyId, 'name' => 'Deal C', 'pipeline_stage_id' => $won->id, 'status' => 'won', 'deal_value' => 5000]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/crmanalytics/pipeline?company_id='.$companyId)->assertSuccessful();

    $stages = $response->json('stages');

    expect($stages)->toHaveCount(2)
        ->and($stages[0]['stage'])->toBe('New')
        ->and($stages[0]['count'])->toBe(2)
        ->and((float) $stages[0]['value'])->toBe(3000.0)
        ->and($stages[1]['stage'])->toBe('Won')
        ->and($stages[1]['is_won'])->toBeTrue()
        ->and((float) $response->json('open_value'))->toBe(3000.0);
});

test('sales activity reports counts by type within a date range', function () {
    $companyId = craCompany();
    Activity::query()->create(['company_id' => $companyId, 'type' => 'call', 'subject' => 'A']);
    Activity::query()->create(['company_id' => $companyId, 'type' => 'call', 'subject' => 'B']);
    $email = Activity::query()->create(['company_id' => $companyId, 'type' => 'email', 'subject' => 'C']);
    $email->toggleComplete();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/crmanalytics/sales-activity?company_id='.$companyId)->assertSuccessful();

    $types = collect($response->json('types'))->keyBy('type');

    expect($types['call']['count'])->toBe(2)
        ->and($types['email']['count'])->toBe(1)
        ->and($response->json('total'))->toBe(3)
        ->and($response->json('completed'))->toBe(1)
        ->and($response->json('pending'))->toBe(2);
});

test('sales activity excludes rows outside the given date range', function () {
    $companyId = craCompany();
    $old = Activity::query()->create(['company_id' => $companyId, 'type' => 'call', 'subject' => 'Old']);
    DB::table('activities')->where('id', $old->id)->update(['created_at' => now()->subDays(60)]);
    Activity::query()->create(['company_id' => $companyId, 'type' => 'call', 'subject' => 'Recent']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/crmanalytics/sales-activity?company_id='.$companyId.'&start_date='.now()->subDays(7)->toDateString().'&end_date='.now()->toDateString())
        ->assertSuccessful();

    expect($response->json('total'))->toBe(1);
});

test('salesperson performance ranks by won value and includes leads, open deals and activities', function () {
    $companyId = craCompany();
    $rep = User::factory()->create(['company_id' => $companyId, 'first_name' => 'Sara', 'last_name' => 'Khan']);
    $otherRep = User::factory()->create(['company_id' => $companyId, 'first_name' => 'Ali', 'last_name' => 'Raza']);

    Lead::query()->create(['company_id' => $companyId, 'name' => 'Lead A', 'status' => 'new', 'assigned_to' => $rep->id]);
    Opportunity::query()->create(['company_id' => $companyId, 'name' => 'Open Deal', 'status' => 'open', 'assigned_to' => $rep->id]);
    Opportunity::query()->create(['company_id' => $companyId, 'name' => 'Won Deal', 'status' => 'won', 'deal_value' => 9000, 'assigned_to' => $rep->id]);
    Opportunity::query()->create(['company_id' => $companyId, 'name' => 'Small Win', 'status' => 'won', 'deal_value' => 100, 'assigned_to' => $otherRep->id]);
    Activity::query()->create(['company_id' => $companyId, 'type' => 'call', 'subject' => 'Call', 'assigned_to' => $rep->id]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/crmanalytics/salesperson-performance?company_id='.$companyId)->assertSuccessful();

    $rows = $response->json('data');

    expect($rows[0]['name'])->toBe('Sara Khan')
        ->and($rows[0]['leads_assigned'])->toBe(1)
        ->and($rows[0]['open_opportunities'])->toBe(1)
        ->and($rows[0]['won_opportunities'])->toBe(1)
        ->and((float) $rows[0]['won_value'])->toBe(9000.0)
        ->and($rows[0]['activities_logged'])->toBe(1)
        ->and($rows[1]['name'])->toBe('Ali Raza');
});

test('a company user cannot see another company\'s CRM analytics', function () {
    $ownCompany = craCompany('CRA001');
    $otherCompany = craCompany('CRA002');
    Lead::query()->create(['company_id' => $ownCompany, 'name' => 'Mine', 'status' => 'new']);
    Lead::query()->create(['company_id' => $otherCompany, 'name' => 'Theirs', 'status' => 'new']);
    Sanctum::actingAs(craStaff($ownCompany, ['/crmanalytics']));

    $response = $this->getJson('/api/crmanalytics/conversion')->assertSuccessful();

    expect($response->json('total_leads'))->toBe(1);
});

test('crm analytics endpoints are forbidden without the menu permission', function () {
    $companyId = craCompany();
    Sanctum::actingAs(craStaff($companyId));

    $this->getJson('/api/crmanalytics/conversion')->assertForbidden();
    $this->getJson('/api/crmanalytics/pipeline')->assertForbidden();
    $this->getJson('/api/crmanalytics/sales-activity')->assertForbidden();
    $this->getJson('/api/crmanalytics/salesperson-performance')->assertForbidden();
});

test('crm analytics endpoints require authentication', function () {
    $this->getJson('/api/crmanalytics/conversion')->assertUnauthorized();
    $this->getJson('/api/crmanalytics/pipeline')->assertUnauthorized();
    $this->getJson('/api/crmanalytics/sales-activity')->assertUnauthorized();
    $this->getJson('/api/crmanalytics/salesperson-performance')->assertUnauthorized();
});
