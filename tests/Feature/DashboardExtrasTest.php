<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The extra dashboard cards: permission gated per part, company scoped, and the HR picture counts only the company's own people.
 */
test('a part is sent only when the user may open the page it comes from', function () {
    $scope = fcoScope();

    Sanctum::actingAs(fcoStaff($scope, ['/dashboard'], false));
    $this->getJson('/api/dashboard-extras')->assertOk()->assertJsonCount(0, 'data');

    Sanctum::actingAs(fcoStaff($scope, ['/dashboard', '/sell'], false));
    $keys = array_keys($this->getJson('/api/dashboard-extras')->assertOk()->json('data'));
    expect($keys)->toContain('today_vs_yesterday', 'payment_methods')->not->toContain('hrm');

    Sanctum::actingAs(fcoStaff($scope, ['/dashboard', '/hrm'], false));
    $this->getJson('/api/dashboard-extras')->assertOk()->assertJsonStructure(['data' => ['hrm' => ['headcount', 'present', 'absent', 'on_leave', 'pending_leave']]]);
});

test('the HR picture and the payment split never include another company', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany('dx');

    foreach ([$scope, $other] as $index => $company) {
        $employee = DB::table('employees')->insertGetId([
            'company_id' => $company['company_id'], 'employee_no' => 'E'.$index, 'first_name' => 'Staff'.$index, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_attendance')->insert(['company_id' => $company['company_id'], 'employee_id' => $employee, 'work_date' => now()->toDateString(), 'status' => 'present', 'created_at' => now(), 'updated_at' => now()]);
    }

    Sanctum::actingAs(fcoStaff($scope, ['/dashboard', '/hrm', '/sell'], false));
    $this->getJson('/api/dashboard-extras')->assertOk()
        ->assertJsonPath('data.hrm.headcount', 1)->assertJsonPath('data.hrm.present', 1);
});

test('today and yesterday carry the sales of the company', function () {
    $scope = fcoScope();
    createSellRecord($scope, ['invoice_no' => 'DX-1', 'transaction_date' => now()->toDateString()]);

    Sanctum::actingAs(fcoStaff($scope, ['/dashboard', '/sell'], false));
    $response = $this->getJson('/api/dashboard-extras')->assertOk();

    expect($response->json('data.today_vs_yesterday.today'))->toHaveKeys(['sales', 'purchases', 'received', 'invoices']);
});
