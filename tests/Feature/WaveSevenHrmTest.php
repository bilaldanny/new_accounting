<?php

use App\Models\CommissionAgent;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrm\AttendanceCalculator;
use App\Services\Hrm\HrSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 7, HR and payroll: the employee master, documents, shifts and attendance (manual, CSV, device), leave, salary structures,
 * admin-entered statutory rates and tax slabs, bonuses, loans, payroll runs, payslips, the bank file, the guarded ledger posting
 * and the payroll reports. Posting is off by default and the guarantee test says the books do not move without it.
 */
const W7_PATHS = [
    '/hrm', '/hrm/manage', '/hrm/attendance', '/hrm/leave/approve', '/hrm/payroll', '/hrm/payroll/approve',
    '/report/payroll-register', '/report/statutory-liability', '/report/attendance-summary', '/report/loan-balances',
];

function w7Hr(array $scope, array $paths = W7_PATHS, bool $branchBound = false): User
{
    return fcoStaff($scope, $paths, $branchBound);
}

function w7Employee(array $scope, string $no = 'E-1', float $basic = 30000, array $extra = []): int
{
    $id = (int) DB::table('employees')->insertGetId($extra + [
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'employee_no' => $no, 'first_name' => 'Ali '.$no, 'last_name' => 'Khan', 'status' => 'active',
        'employment_type' => 'permanent', 'join_date' => '2026-01-01', 'bank_name' => 'Test Bank', 'bank_account' => 'PK00'.$no, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('employee_salaries')->insert(['company_id' => $scope['company_id'], 'employee_id' => $id, 'effective_from' => '2026-01-01', 'basic' => $basic, 'components' => '[]', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function w7Rate(array $scope, string $code, string $side, float $percent, string $from, string $basis = 'basic'): void
{
    DB::table('statutory_rates')->insert([
        'company_id' => $scope['company_id'], 'code' => $code, 'name' => $code.' contribution', 'side' => $side, 'basis' => $basis, 'rate_percent' => $percent, 'fixed_amount' => 0,
        'effective_from' => $from, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function w7Absent(int $employeeId, int $companyId, array $dates): void
{
    foreach ($dates as $date) {
        DB::table('hr_attendance')->insert(['company_id' => $companyId, 'employee_id' => $employeeId, 'work_date' => $date, 'status' => 'absent', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    }
}

function w7Map(array $scope): void
{
    DB::table('hr_settings')->updateOrInsert(['company_id' => $scope['company_id']], ['settings' => json_encode(array_merge(HrSettings::DEFAULTS, [
        'posting_enabled' => true,
        'accounts' => [
            'salary_expense' => $scope['rent_id'], 'salary_payable' => $scope['accrued_id'], 'tax_payable' => $scope['deferred_id'], 'statutory_payable' => $scope['allowance_id'],
            'loan_receivable' => $scope['prepaid_id'], 'accrual_expense' => $scope['insurance_id'], 'accrual_liability' => $scope['bank_id'],
        ],
    ])), 'created_at' => now(), 'updated_at' => now()]);
}

function w7Counts(int $companyId): array
{
    return [
        DB::table('t_accounts')->where('company_id', $companyId)->count(), DB::table('t_account_details')->count(), fcoTrialBalance($companyId),
        DB::table('transactions')->where('company_id', $companyId)->count(),
    ];
}

// ---------------------------------------------------------------------------------------------------------------------
// master data and isolation

test('employees are the company own, separate from users, and another company cannot read or change them', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $foreign = w7Employee($other, 'F-1');
    $hr = w7Hr($scope);
    Sanctum::actingAs($hr);

    $created = $this->postJson('/api/hrm/employees', [
        'employee_no' => 'E-100', 'first_name' => 'Sara', 'last_name' => 'Ahmed', 'designation' => 'Accountant', 'join_date' => '2026-02-01', 'company_id' => $other['company_id'], 'branch_id' => $scope['branch_id'],
    ])->assertCreated();
    $id = (int) $created->json('data.id');

    expect((int) DB::table('employees')->where('id', $id)->value('company_id'))->toBe($scope['company_id'])->and(DB::table('employees')->where('id', $id)->value('user_id'))->toBeNull();

    $this->getJson("/api/hrm/employees/{$foreign}")->assertNotFound();
    $this->putJson("/api/hrm/employees/{$foreign}", ['employee_no' => 'F-1', 'first_name' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/api/hrm/employees/{$foreign}")->assertNotFound();
    $this->postJson("/api/hrm/employees/{$foreign}/salary", ['effective_from' => '2026-09-01', 'basic' => 1])->assertNotFound();
    $this->postJson('/api/hrm/attendance', ['employee_id' => $foreign, 'work_date' => '2026-09-01', 'status' => 'absent'])->assertNotFound();
    $this->postJson('/api/hrm/loans', ['employee_id' => $foreign, 'kind' => 'loan', 'amount' => 100, 'installment' => 10, 'start_period' => '2026-09'])->assertNotFound();
    $this->postJson('/api/hrm/bonuses', ['employee_id' => $foreign, 'period' => '2026-09', 'title' => 'x', 'amount' => 5])->assertNotFound();

    $list = collect($this->getJson('/api/hrm/employees')->assertOk()->json('data'))->pluck('employee_no')->all();
    expect($list)->toContain('E-100')->not->toContain('F-1');

    // a user, a shift and a manager of another company cannot be attached
    $foreignUser = fcoStaff($other, ['/hrm']);
    $this->putJson("/api/hrm/employees/{$id}", ['employee_no' => 'E-100', 'first_name' => 'Sara', 'user_id' => $foreignUser->id])->assertUnprocessable();
    $this->putJson("/api/hrm/employees/{$id}", ['employee_no' => 'E-100', 'first_name' => 'Sara', 'manager_id' => $foreign])->assertUnprocessable();

    // the employee number is unique inside the company only
    $this->postJson('/api/hrm/employees', ['employee_no' => 'E-100', 'first_name' => 'Dup'])->assertUnprocessable();
    Sanctum::actingAs(fcoStaff($other, ['/hrm', '/hrm/manage']));
    $this->postJson('/api/hrm/employees', ['employee_no' => 'E-100', 'first_name' => 'Same number elsewhere'])->assertCreated();
});

test('every hrm endpoint needs its own menu permission', function () {
    $scope = fcoScope();
    $id = w7Employee($scope);
    Sanctum::actingAs(fcoStaff($scope, ['/hrm']));

    $this->getJson('/api/hrm/employees')->assertOk();
    $this->postJson('/api/hrm/employees', ['employee_no' => 'X', 'first_name' => 'X'])->assertForbidden();
    $this->postJson('/api/hrm/attendance', ['employee_id' => $id, 'work_date' => '2026-09-01', 'status' => 'absent'])->assertForbidden();
    $this->postJson('/api/hrm/payroll', ['period' => '2026-09'])->assertForbidden();
    $this->postJson('/api/payroll-runs/1/approve')->assertForbidden();
    $this->postJson('/api/leave-requests/1/approve')->assertForbidden();
    $this->getJson('/api/reports/payroll-register')->assertForbidden();

    Sanctum::actingAs(fcoStaff($scope, []));
    $this->getJson('/api/hrm/employees')->assertForbidden();
});

test('a branch user sees and writes only the employees of their branch', function () {
    $scope = fcoScope();
    $otherBranch = DB::table('branches')->insertGetId(['code' => 'W7B2', 'company_id' => $scope['company_id'], 'name' => 'Second', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $mine = w7Employee($scope, 'B-1');
    $theirs = w7Employee($scope, 'B-2', 1000, ['branch_id' => $otherBranch]);
    Sanctum::actingAs(w7Hr($scope, W7_PATHS, true));

    $list = collect($this->getJson('/api/hrm/employees')->assertOk()->json('data'))->pluck('id')->all();
    expect($list)->toContain($mine)->not->toContain($theirs);
    $this->getJson("/api/hrm/employees/{$theirs}")->assertNotFound();
    $this->postJson('/api/hrm/attendance', ['employee_id' => $theirs, 'work_date' => '2026-09-01', 'status' => 'absent'])->assertNotFound();
});

test('documents are stored per employee with an expiry, found only inside the company, and expiring ones are listed', function () {
    Storage::fake('local');
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $id = w7Employee($scope);
    $foreign = w7Employee($other, 'F-9');
    Sanctum::actingAs(w7Hr($scope));

    $response = $this->post("/api/hrm/employees/{$id}/documents", [
        'file' => UploadedFile::fake()->create('cnic.pdf', 20, 'application/pdf'), 'doc_type' => 'cnic', 'title' => 'CNIC copy', 'expires_on' => now()->addDays(10)->toDateString(),
    ], ['Accept' => 'application/json'])->assertCreated();
    $documentId = (int) $response->json('data.id');

    $this->post("/api/hrm/employees/{$foreign}/documents", ['file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf'), 'doc_type' => 'cnic', 'title' => 'x'], ['Accept' => 'application/json'])->assertNotFound();
    $this->post("/api/hrm/employees/{$id}/documents", ['file' => UploadedFile::fake()->create('run.exe', 5, 'application/x-msdownload'), 'doc_type' => 'cnic', 'title' => 'bad'], ['Accept' => 'application/json'])->assertUnprocessable();

    $this->getJson('/api/hrm/documents/expiring')->assertOk()->assertJsonPath('data.0.title', 'CNIC copy');
    $this->get("/api/hrm/documents/{$documentId}/download")->assertOk();

    Sanctum::actingAs(w7Hr($other));
    $this->get("/api/hrm/documents/{$documentId}/download")->assertNotFound();
    $this->deleteJson("/api/hrm/documents/{$documentId}")->assertNotFound();
});

test('the expiry command alerts the company admins once per document', function () {
    $scope = fcoScope();
    $id = w7Employee($scope);
    $admin = createStaffUserForRole(Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => $scope['company_id']], ['is_active' => true]), ['company_id' => $scope['company_id'], 'branch_id' => null, 'email_verified_at' => now()]);
    DB::table('employee_documents')->insert(['company_id' => $scope['company_id'], 'employee_id' => $id, 'doc_type' => 'contract', 'title' => 'Contract', 'expires_on' => now()->addDays(5)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('employee_documents')->insert(['company_id' => $scope['company_id'], 'employee_id' => $id, 'doc_type' => 'visa', 'title' => 'Far visa', 'expires_on' => now()->addDays(300)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

    Artisan::call('hrm:document-alerts');
    Artisan::call('hrm:document-alerts');

    expect(DB::table('employee_documents')->whereNotNull('alerted_at')->count())->toBe(1)
        ->and(DB::table('app_notifications')->where('user_id', $admin->id)->where('kind', 'hr_document')->count())->toBe(1);
});

test('the organisation tree groups employees by department under their managers', function () {
    $scope = fcoScope();
    $department = DB::table('departments')->insertGetId(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => 'Sales', 'created_at' => now(), 'updated_at' => now()]);
    $boss = w7Employee($scope, 'M-1', 50000, ['department_id' => $department]);
    w7Employee($scope, 'M-2', 20000, ['department_id' => $department, 'manager_id' => $boss]);
    Sanctum::actingAs(w7Hr($scope));

    $tree = $this->getJson('/api/hrm/org-tree')->assertOk()->json('data');
    $sales = collect($tree)->firstWhere('name', 'Sales');

    expect($sales['people'])->toHaveCount(1)->and($sales['people'][0]['employee_no'])->toBe('M-1')->and($sales['people'][0]['reports'][0]['employee_no'])->toBe('M-2');
});

// ---------------------------------------------------------------------------------------------------------------------
// attendance

test('late, early and overtime minutes come from the shift, its grace and the minimum overtime', function () {
    $shift = ['start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 10];
    $settings = ['overtime_min_minutes' => 30];

    expect(AttendanceCalculator::compute($shift, '09:05', '18:00', $settings))->toMatchArray(['late' => 0, 'early' => 0, 'overtime' => 0, 'worked' => 535])
        ->and(AttendanceCalculator::compute($shift, '09:30', '17:30', $settings))->toMatchArray(['late' => 20, 'early' => 30, 'overtime' => 0])
        ->and(AttendanceCalculator::compute($shift, '09:00', '18:20', $settings)['overtime'])->toBe(0)
        ->and(AttendanceCalculator::compute($shift, '09:00', '19:00', $settings)['overtime'])->toBe(60)
        ->and(AttendanceCalculator::compute(['start_time' => '22:00', 'end_time' => '06:00', 'grace_minutes' => 0], '22:00', '06:00', $settings))->toMatchArray(['late' => 0, 'early' => 0, 'worked' => 480]);
});

test('a manual entry is never overwritten by a file or a device, and a bad CSV row is reported while the rest are saved', function () {
    $scope = fcoScope();
    $shift = DB::table('hr_shifts')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 10, 'weekly_off' => '0', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $id = w7Employee($scope, 'A-1', 30000, ['shift_id' => $shift]);
    Sanctum::actingAs(w7Hr($scope));

    $this->postJson('/api/hrm/attendance', ['employee_id' => $id, 'work_date' => '2026-09-10', 'check_in' => '09:00', 'check_out' => '18:00'])->assertCreated()->assertJsonPath('data.late_minutes', 0);

    $csv = "employee_no,date,check_in,check_out\nA-1,2026-09-10,11:00,18:00\nA-1,2026-09-11,09:40,19:00\nNOPE,2026-09-11,09:00,18:00\nA-1,not-a-date,09:00,18:00\nA-1,2026-09-12,25:99,18:00";
    $result = $this->postJson('/api/hrm/attendance/import', ['csv' => $csv])->assertOk()->json('data');

    expect($result['saved'])->toBe(2)->and($result['errors'])->toHaveCount(3);

    $manual = DB::table('hr_attendance')->where('employee_id', $id)->where('work_date', '2026-09-10')->first();
    $imported = DB::table('hr_attendance')->where('employee_id', $id)->where('work_date', '2026-09-11')->first();

    expect($manual->source)->toBe('manual')->and($manual->check_in)->toBe('09:00')->and((int) $imported->late_minutes)->toBe(30)->and((int) $imported->overtime_minutes)->toBe(60);
});

test('a device punches with its own token, the company is the token company, a replay is ignored and a wrong token is refused', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $shift = DB::table('hr_shifts')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 10, 'weekly_off' => '0', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $id = w7Employee($scope, 'D-1', 30000, ['shift_id' => $shift]);
    w7Employee($other, 'D-1');
    Sanctum::actingAs(w7Hr($scope));

    $token = $this->postJson('/api/hrm/devices', ['name' => 'Gate'])->assertCreated()->json('data.token');
    expect(DB::table('hr_device_tokens')->where('token_hash', $token)->exists())->toBeFalse()
        ->and(DB::table('hr_device_tokens')->where('token_hash', hash('sha256', $token))->exists())->toBeTrue();

    app('auth')->forgetGuards();
    $payload = ['punches' => [['employee_no' => 'D-1', 'punched_at' => '2026-09-14 09:25:00'], ['employee_no' => 'D-1', 'punched_at' => '2026-09-14 18:45:00'], ['employee_no' => 'GHOST', 'punched_at' => '2026-09-14 09:00:00']]];

    $this->withHeaders(['X-Device-Token' => 'wrong'])->postJson('/api/hrm/device/punch', $payload)->assertUnauthorized();
    $first = $this->withHeaders(['X-Device-Token' => $token])->postJson('/api/hrm/device/punch', $payload + ['company_id' => $other['company_id']])->assertOk();
    $again = $this->withHeaders(['X-Device-Token' => $token])->postJson('/api/hrm/device/punch', $payload)->assertOk();

    expect($first->json('data.new'))->toBe(3)->and($again->json('data.new'))->toBe(0)
        ->and(DB::table('hr_device_punches')->where('company_id', $other['company_id'])->count())->toBe(0);

    $row = DB::table('hr_attendance')->where('employee_id', $id)->where('work_date', '2026-09-14')->first();
    expect($row->check_in)->toBe('09:25')->and($row->check_out)->toBe('18:45')->and((int) $row->late_minutes)->toBe(15)->and((int) $row->overtime_minutes)->toBe(45)->and($row->source)->toBe('device');
});

// ---------------------------------------------------------------------------------------------------------------------
// leave

test('leave skips the weekly off, is limited by the balance, writes attendance when approved and cannot be decided by its own employee', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $hr = w7Hr($scope);
    $id = w7Employee($scope, 'L-1', 30000, ['user_id' => $hr->id]);
    $colleague = w7Employee($scope, 'L-2');
    $type = DB::table('leave_types')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Annual', 'days_per_year' => 6, 'is_paid' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $foreignType = DB::table('leave_types')->insertGetId(['company_id' => $other['company_id'], 'name' => 'Annual', 'days_per_year' => 6, 'is_paid' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($hr);

    $this->postJson('/api/hrm/leave-requests', ['employee_id' => $colleague, 'leave_type_id' => $foreignType, 'from_date' => '2026-09-07', 'to_date' => '2026-09-08'])->assertUnprocessable();

    // Monday 7 to Sunday 13: six working days, the Sunday is the weekly off
    $request = (int) $this->postJson('/api/hrm/leave-requests', ['employee_id' => $colleague, 'leave_type_id' => $type, 'from_date' => '2026-09-07', 'to_date' => '2026-09-13'])->assertCreated()->json('data.id');
    expect((float) DB::table('leave_requests')->where('id', $request)->value('days'))->toBe(6.0);

    $this->postJson('/api/hrm/leave-requests', ['employee_id' => $colleague, 'leave_type_id' => $type, 'from_date' => '2026-09-09', 'to_date' => '2026-09-09'])->assertUnprocessable();
    $this->postJson('/api/hrm/leave-requests', ['employee_id' => $colleague, 'leave_type_id' => $type, 'from_date' => '2026-10-05', 'to_date' => '2026-10-05'])->assertUnprocessable();

    $this->postJson("/api/leave-requests/{$request}/approve")->assertOk();
    expect(DB::table('hr_attendance')->where('employee_id', $colleague)->where('status', 'leave')->count())->toBe(6)
        ->and($this->getJson("/api/hrm/leave-balance?employee_id={$colleague}&year=2026")->json('data.0.balance'))->toEqual(0);

    // the employee's own login cannot decide their own leave
    $own = (int) $this->postJson('/api/hrm/leave-requests', ['employee_id' => $id, 'leave_type_id' => $type, 'from_date' => '2026-11-02', 'to_date' => '2026-11-02'])->assertCreated()->json('data.id');
    $this->postJson("/api/leave-requests/{$own}/approve")->assertUnprocessable();
    expect(DB::table('leave_requests')->where('id', $own)->value('status'))->toBe('pending');

    // cancelling an approved leave takes its attendance away and frees the balance
    $this->postJson("/api/hrm/leave-requests/{$request}/cancel")->assertOk();
    expect(DB::table('hr_attendance')->where('employee_id', $colleague)->count())->toBe(0);

    // another company cannot decide it
    Sanctum::actingAs(w7Hr($other));
    $this->postJson("/api/leave-requests/{$own}/reject")->assertNotFound();
});

test('a rejected leave writes nothing and the leave approval can be put behind an approval workflow', function () {
    $scope = fcoScope();
    $employee = w7Employee($scope, 'L-3');
    $type = DB::table('leave_types')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Casual', 'days_per_year' => 0, 'is_paid' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $hr = w7Hr($scope, ['/hrm', '/hrm/attendance', '/hrm/leave/approve', '/approvalcenter', '/approvalcenter/manage']);
    $second = w7Hr($scope, ['/hrm', '/hrm/leave/approve']);
    Sanctum::actingAs($hr);

    $id = (int) $this->postJson('/api/hrm/leave-requests', ['employee_id' => $employee, 'leave_type_id' => $type, 'from_date' => '2026-09-14', 'to_date' => '2026-09-15'])->assertCreated()->json('data.id');
    $this->postJson("/api/leave-requests/{$id}/reject", ['note' => 'Busy week'])->assertOk();

    expect(DB::table('leave_requests')->where('id', $id)->value('status'))->toBe('rejected')->and(DB::table('hr_attendance')->count())->toBe(0);

    $workflow = (int) $this->postJson('/api/approval-center/workflows', [
        'name' => 'Leave', 'document_type' => 'leave', 'levels' => [
            ['label' => 'Supervisor', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $hr->id]]],
            ['label' => 'HR head', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $second->id]]],
        ],
    ])->assertCreated()->json('id');
    expect($workflow)->toBeGreaterThan(0);

    $next = (int) $this->postJson('/api/hrm/leave-requests', ['employee_id' => $employee, 'leave_type_id' => $type, 'from_date' => '2026-09-16', 'to_date' => '2026-09-16'])->assertCreated()->json('data.id');
    $this->postJson("/api/leave-requests/{$next}/approve")->assertOk();
    expect(DB::table('leave_requests')->where('id', $next)->value('status'))->toBe('pending');

    Sanctum::actingAs($second);
    $this->postJson("/api/leave-requests/{$next}/approve")->assertOk();
    expect(DB::table('leave_requests')->where('id', $next)->value('status'))->toBe('approved');
});

// ---------------------------------------------------------------------------------------------------------------------
// payroll

test('with no statutory rate and no tax slab nothing of the kind is deducted, and the application ships none', function () {
    $scope = fcoScope();
    w7Employee($scope, 'P-1', 30000);
    Sanctum::actingAs(w7Hr($scope));

    $line = $this->getJson('/api/hrm/payroll/preview?period=2026-09')->assertOk()->json('data.lines.0');

    expect($line['gross'])->toEqual(30000)->and($line['statutory_employee'])->toEqual(0)->and($line['statutory_employer'])->toEqual(0)->and($line['tax'])->toEqual(0)->and($line['net'])->toEqual(30000);
    expect(DB::table('statutory_rates')->count())->toBe(0)->and(DB::table('tax_slabs')->count())->toBe(0);
});

test('statutory rates and tax slabs are the admin rows in force on the month, absent days and overtime are applied', function () {
    $scope = fcoScope();
    $id = w7Employee($scope, 'P-2', 30000);
    w7Rate($scope, 'PF', 'employee', 10, '2026-01-01');
    w7Rate($scope, 'PF', 'employee', 12, '2026-10-01');
    w7Rate($scope, 'EOBI', 'employer', 5, '2026-01-01');
    DB::table('tax_slabs')->insert(['company_id' => $scope['company_id'], 'effective_from' => '2026-01-01', 'annual_from' => 0, 'annual_to' => 300000, 'fixed_amount' => 0, 'rate_percent' => 0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('tax_slabs')->insert(['company_id' => $scope['company_id'], 'effective_from' => '2026-01-01', 'annual_from' => 300000.01, 'annual_to' => null, 'fixed_amount' => 0, 'rate_percent' => 10, 'created_at' => now(), 'updated_at' => now()]);
    w7Absent($id, $scope['company_id'], ['2026-09-02', '2026-09-03']);
    Sanctum::actingAs(w7Hr($scope));

    $september = $this->getJson('/api/hrm/payroll/preview?period=2026-09')->assertOk()->json('data.lines.0');
    $october = $this->getJson('/api/hrm/payroll/preview?period=2026-10')->assertOk()->json('data.lines.0');

    // 30 000 basic, 30 days: two absent days cost 2 000; PF 10 % of basic = 3 000; tax: 360 000 a year, (360 000 - 300 000.01) x 10 % / 12 = 500.00
    expect($september['attendance_deduction'])->toEqual(2000)->and($september['statutory_employee'])->toEqual(3000)->and($september['statutory_employer'])->toEqual(1500)
        ->and($september['tax'])->toEqual(500)->and($september['net'])->toEqual(24500)
        ->and($october['statutory_employee'])->toEqual(3600);
});

test('a payroll run is one per month, approving applies loans and bonuses once and cancelling gives them back', function () {
    $scope = fcoScope();
    $id = w7Employee($scope, 'P-3', 30000);
    Sanctum::actingAs(w7Hr($scope));

    $loan = (int) $this->postJson('/api/hrm/loans', ['employee_id' => $id, 'kind' => 'advance', 'amount' => 5000, 'installment' => 2000, 'start_period' => '2026-09'])->assertCreated()->json('data.id');
    $this->postJson('/api/hrm/bonuses', ['employee_id' => $id, 'period' => '2026-09', 'title' => 'Eid bonus', 'amount' => 1000])->assertCreated();

    $run = (int) $this->postJson('/api/hrm/payroll', ['period' => '2026-09'])->assertCreated()->json('data.id');
    $this->postJson('/api/hrm/payroll', ['period' => '2026-09'])->assertUnprocessable();

    $line = $this->getJson("/api/hrm/payroll/{$run}")->assertOk()->json('data.lines.0');
    expect($line['gross'])->toEqual(31000)->and($line['loan_deduction'])->toEqual(2000)->and($line['net'])->toEqual(29000);
    expect((float) DB::table('employee_loans')->where('id', $loan)->value('balance'))->toBe(5000.0);

    $this->postJson("/api/payroll-runs/{$run}/approve")->assertOk();
    $this->postJson("/api/payroll-runs/{$run}/approve")->assertUnprocessable();
    expect((float) DB::table('employee_loans')->where('id', $loan)->value('balance'))->toBe(3000.0)
        ->and(DB::table('employee_bonuses')->value('payroll_run_id'))->toBe($run)
        ->and(DB::table('employee_loan_repayments')->count())->toBe(1);

    $this->deleteJson('/api/hrm/bonuses/'.DB::table('employee_bonuses')->value('id'))->assertUnprocessable();
    $this->postJson("/api/hrm/payroll/{$run}/recalculate")->assertUnprocessable();

    $this->postJson("/api/hrm/payroll/{$run}/cancel")->assertOk();
    expect((float) DB::table('employee_loans')->where('id', $loan)->value('balance'))->toBe(5000.0)
        ->and(DB::table('employee_bonuses')->value('payroll_run_id'))->toBeNull()
        ->and(DB::table('employee_loan_repayments')->count())->toBe(0);

    // a new run for the month can be made after cancelling
    $this->postJson('/api/hrm/payroll', ['period' => '2026-09'])->assertCreated();
});

test('the person who prepared a payroll cannot approve it while maker-checker is on', function () {
    $scope = fcoScope();
    w7Employee($scope, 'P-4');
    DB::table('security_settings')->insert(['company_id' => $scope['company_id'], 'maker_checker' => 1, 'policy_enabled' => 0, 'expiry_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
    $maker = w7Hr($scope);
    Sanctum::actingAs($maker);

    $run = (int) $this->postJson('/api/hrm/payroll', ['period' => '2026-09'])->assertCreated()->json('data.id');
    $this->postJson("/api/payroll-runs/{$run}/approve")->assertUnprocessable();

    Sanctum::actingAs(w7Hr($scope));
    $this->postJson("/api/payroll-runs/{$run}/approve")->assertOk();
});

test('payroll runs, lines and payslips of another company are not found and its employees are not in my payroll', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    w7Employee($scope, 'P-5');
    w7Employee($other, 'P-5', 99999);
    Sanctum::actingAs(w7Hr($other));
    $foreignRun = (int) $this->postJson('/api/hrm/payroll', ['period' => '2026-09'])->assertCreated()->json('data.id');
    $foreignLine = (int) DB::table('payroll_lines')->where('payroll_run_id', $foreignRun)->value('id');

    Sanctum::actingAs(w7Hr($scope));
    $mine = $this->getJson('/api/hrm/payroll/preview?period=2026-09')->assertOk()->json('data');
    expect(collect($mine['lines'])->pluck('employee_no')->all())->toBe(['P-5'])->and($mine['lines'][0]['gross'])->toEqual(30000);

    $this->getJson("/api/hrm/payroll/{$foreignRun}")->assertNotFound();
    $this->postJson("/api/hrm/payroll/{$foreignRun}/cancel")->assertNotFound();
    $this->postJson("/api/payroll-runs/{$foreignRun}/approve")->assertNotFound();
    $this->postJson("/api/hrm/payroll/{$foreignRun}/post")->assertNotFound();
    $this->get("/api/hrm/payslips/{$foreignLine}")->assertNotFound();
    $this->get("/api/hrm/payroll/{$foreignRun}/bank-file")->assertNotFound();
    expect(collect($this->getJson('/api/hrm/payroll')->json('data'))->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------------
// ledger posting (guarded)

test('with posting off, preparing, approving and everything else leaves the books exactly as they were and posting is refused', function () {
    $scope = fcoScope();
    $id = w7Employee($scope, 'G-1');
    w7Rate($scope, 'PF', 'employee', 10, '2026-01-01');
    w7Absent($id, $scope['company_id'], ['2026-09-02']);
    $before = w7Counts($scope['company_id']);
    Sanctum::actingAs(w7Hr($scope));

    $run = (int) $this->postJson('/api/hrm/payroll', ['period' => '2026-09'])->assertCreated()->json('data.id');
    $this->postJson("/api/hrm/payroll/{$run}/recalculate")->assertOk();
    $this->postJson("/api/hrm/payroll/{$run}/post")->assertUnprocessable();
    $this->postJson("/api/payroll-runs/{$run}/approve")->assertOk();
    $this->postJson("/api/hrm/payroll/{$run}/post")->assertUnprocessable()->assertJsonValidationErrors('posting');
    $this->getJson('/api/reports/payroll-register?period=2026-09')->assertOk();

    expect(w7Counts($scope['company_id']))->toBe($before)->and(DB::table('payroll_runs')->value('status'))->toBe('approved')->and(DB::table('payroll_runs')->value('voucher_id'))->toBeNull();
});

test('posting needs the switch and the mapped accounts, makes one balanced voucher once, and a locked month refuses it', function () {
    $scope = fcoScope();
    $id = w7Employee($scope, 'G-2', 30000);
    w7Rate($scope, 'PF', 'employee', 10, '2026-01-01');
    w7Rate($scope, 'EOBI', 'employer', 5, '2026-01-01');
    DB::table('tax_slabs')->insert(['company_id' => $scope['company_id'], 'effective_from' => '2026-01-01', 'annual_from' => 0, 'annual_to' => null, 'fixed_amount' => 0, 'rate_percent' => 10, 'created_at' => now(), 'updated_at' => now()]);
    w7Absent($id, $scope['company_id'], ['2026-09-02', '2026-09-03']);
    DB::table('employee_loans')->insert(['company_id' => $scope['company_id'], 'employee_id' => $id, 'kind' => 'advance', 'amount' => 6000, 'installment' => 2000, 'start_period' => '2026-09', 'balance' => 6000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(w7Hr($scope));

    $run = (int) $this->postJson('/api/hrm/payroll', ['period' => '2026-09'])->assertCreated()->json('data.id');
    $this->postJson("/api/payroll-runs/{$run}/approve")->assertOk();

    // switched on but no accounts mapped
    DB::table('hr_settings')->insert(['company_id' => $scope['company_id'], 'settings' => json_encode(array_merge(HrSettings::DEFAULTS, ['posting_enabled' => true])), 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson("/api/hrm/payroll/{$run}/post")->assertUnprocessable()->assertJsonValidationErrors('accounts');

    // a locked month refuses the posting and the run stays approved
    w7Map($scope);
    fcoLockThrough($scope['company_id'], '2026-09-30');
    $vouchersBefore = DB::table('t_accounts')->where('company_id', $scope['company_id'])->count();
    $this->postJson("/api/hrm/payroll/{$run}/post")->assertUnprocessable();
    expect(DB::table('payroll_runs')->value('status'))->toBe('approved')->and(DB::table('t_accounts')->where('company_id', $scope['company_id'])->count())->toBe($vouchersBefore);

    // open the period: posts once
    DB::table('period_locks')->delete();
    $voucher = (int) $this->postJson("/api/hrm/payroll/{$run}/post")->assertOk()->json('data.voucher_id');
    $this->postJson("/api/hrm/payroll/{$run}/post")->assertUnprocessable();

    // gross 30 000 - absent 2 000 + employer 1 500 = 29 500 expense; payable 22 500 net, tax 2 500 / 12 ... read from the voucher itself
    $lines = DB::table('t_account_details')->where('t_account_id', $voucher)->get();
    $run = DB::table('payroll_runs')->first();
    $payroll = DB::table('payroll_lines')->first();

    expect(round((float) $lines->sum('debit'), 2))->toBe(round((float) $lines->sum('credit'), 2))
        ->and($run->status)->toBe('posted')->and((int) $run->voucher_id)->toBe($voucher)
        ->and(fcoBalance($scope['rent_id']))->toBe(round((float) $payroll->gross - (float) $payroll->attendance_deduction + (float) $payroll->statutory_employer, 2))
        ->and(fcoBalance($scope['accrued_id']))->toBe(-round((float) $payroll->net, 2))
        ->and(fcoBalance($scope['prepaid_id']))->toBe(-2000.0)
        ->and(fcoTrialBalance($scope['company_id'])['debit'])->toBe(fcoTrialBalance($scope['company_id'])['credit']);

    // a posted run cannot be cancelled here
    $this->postJson("/api/hrm/payroll/{$run->id}/cancel")->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// payslip, bank file, reports

test('the payslip escapes what it prints and shows the stored figures, and the bank file needs an approved run and neutralises formulas', function () {
    $scope = fcoScope();
    w7Employee($scope, 'S-1', 30000, ['first_name' => '<script>alert(1)</script>', 'last_name' => '=cmd()', 'bank_account' => '']);
    w7Employee($scope, 'S-2', 20000, ['first_name' => '=HYPERLINK("x")', 'bank_account' => 'PK99S2']);
    Sanctum::actingAs(w7Hr($scope));

    $run = (int) $this->postJson('/api/hrm/payroll', ['period' => '2026-09'])->assertCreated()->json('data.id');
    $lineId = (int) DB::table('payroll_lines')->orderBy('id')->value('id');

    $html = $this->get("/api/hrm/payslips/{$lineId}")->assertOk()->getContent();
    expect($html)->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;')->toContain('30,000.00');

    $this->get("/api/hrm/payroll/{$run}/bank-file")->assertUnprocessable();
    $this->postJson("/api/hrm/payslips/{$lineId}/email")->assertUnprocessable();
    $this->postJson("/api/payroll-runs/{$run}/approve")->assertOk();

    $file = $this->get("/api/hrm/payroll/{$run}/bank-file?format=generic")->assertOk();
    $csv = $file->getContent();
    expect($file->headers->get('X-Skipped'))->toBe('1')->and($csv)->toContain('PK99S2')->toContain("'=HYPERLINK")->not->toContain('PK00S-1')->and($csv)->toContain('20000.00');

    $ibft = $this->get("/api/hrm/payroll/{$run}/bank-file?format=ibft")->assertOk()->getContent();
    expect($ibft)->toContain('Beneficiary Account')->toContain('Salary 2026-09');

    Mail::fake();
    DB::table('employees')->where('employee_no', 'S-2')->update(['email' => 'staff@example.test']);
    $secondLine = (int) DB::table('payroll_lines')->orderByDesc('id')->value('id');
    $this->postJson("/api/hrm/payslips/{$secondLine}/email")->assertOk();
});

test('the payroll reports read approved payrolls, loans and attendance of the signed-in company only', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $id = w7Employee($scope, 'R-1', 30000);
    w7Employee($other, 'R-9', 77000);
    w7Rate($scope, 'PF', 'employee', 10, '2026-01-01');
    w7Rate($scope, 'EOBI', 'employer', 5, '2026-01-01');
    w7Absent($id, $scope['company_id'], ['2026-09-02']);
    DB::table('employee_loans')->insert(['company_id' => $scope['company_id'], 'employee_id' => $id, 'kind' => 'loan', 'amount' => 4000, 'installment' => 1000, 'start_period' => '2026-09', 'balance' => 4000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(w7Hr($scope));

    $run = (int) $this->postJson('/api/hrm/payroll', ['period' => '2026-09'])->assertCreated()->json('data.id');

    // a draft payroll is not in the register
    expect($this->getJson('/api/reports/payroll-register?period=2026-09')->assertOk()->json('summary.employees'))->toBe(0);
    $this->postJson("/api/payroll-runs/{$run}/approve")->assertOk();

    $register = $this->getJson('/api/reports/payroll-register?period=2026-09')->assertOk();
    expect($register->json('summary.employees'))->toBe(1)->and($register->json('summary.gross'))->toEqual(30000)->and($register->json('summary.statutory_employee'))->toEqual(3000)
        ->and($register->json('summary.net'))->toEqual(25000);

    $liability = $this->getJson('/api/reports/statutory-liability?start_date=2026-09-01&end_date=2026-09-30')->assertOk();
    expect($liability->json('summary.employee_share'))->toEqual(3000)->and($liability->json('summary.employer_share'))->toEqual(1500);

    $attendance = $this->getJson('/api/reports/attendance-summary?start_date=2026-09-01&end_date=2026-09-30')->assertOk();
    expect($attendance->json('summary.absent'))->toBe(1);

    $loans = $this->getJson('/api/reports/loan-balances')->assertOk();
    expect($loans->json('summary.balance'))->toEqual(3000)->and($loans->json('summary.recovered'))->toEqual(1000);

    Sanctum::actingAs(w7Hr($other));
    expect($this->getJson('/api/reports/payroll-register?period=2026-09')->json('summary.employees'))->toBe(0)
        ->and($this->getJson('/api/reports/loan-balances')->json('summary.loans'))->toBe(0);
});

test('the settings validate their values, keep the accounts inside the company and default to posting off', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $foreignAccount = insertPurchaseChartAccount($other, '201-09999', 'Foreign', 'dr', false);
    Sanctum::actingAs(w7Hr($scope));

    expect($this->getJson('/api/hrm/settings')->assertOk()->json('data.posting_enabled'))->toBeFalse();

    $this->putJson('/api/hrm/settings', ['days_basis' => 'weekly'])->assertUnprocessable();
    $this->putJson('/api/hrm/settings', ['overtime_multiplier' => 9])->assertUnprocessable();
    $this->putJson('/api/hrm/settings', ['accounts' => ['salary_expense' => $foreignAccount]])->assertUnprocessable();

    $this->putJson('/api/hrm/settings', ['days_basis' => 'fixed', 'fixed_days' => 26, 'accounts' => ['salary_expense' => $scope['rent_id']]])->assertOk();
    $saved = $this->getJson('/api/hrm/settings')->json('data');

    expect($saved['days_basis'])->toBe('fixed')->and($saved['fixed_days'])->toBe(26)->and($saved['accounts']['salary_expense'])->toBe($scope['rent_id'])->and($saved['posting_enabled'])->toBeFalse();
});

test('sales commission of the linked agent is added to the month and the gratuity accrual follows the setting', function () {
    $scope = fcoScope();
    $agent = CommissionAgent::query()->create(['company_id' => $scope['company_id'], 'name' => 'Rep One', 'commission_percent' => 2, 'is_active' => true]);
    w7Employee($scope, 'C-1', 30000, ['commission_agent_id' => $agent->id]);
    createSellRecord($scope, ['commission_agent_id' => $agent->id, 'final_amount' => 5000, 'transaction_date' => '2026-09-10 10:00:00', 'invoice_no' => 'INV-W7-1']);
    Sanctum::actingAs(w7Hr($scope));

    $line = $this->getJson('/api/hrm/payroll/preview?period=2026-09')->assertOk()->json('data.lines.0');
    expect($line['gross'])->toEqual(30100)->and($line['accrual'])->toEqual(0);

    // 15 days of basic a year: 30 000 / 30 x 15 / 12 = 1 250 a month
    $this->putJson('/api/hrm/settings', ['gratuity_days_per_year' => 15])->assertOk();
    $line = $this->getJson('/api/hrm/payroll/preview?period=2026-09')->json('data.lines.0');
    expect($line['accrual'])->toEqual(1250)->and($line['gross'])->toEqual(30100);
});
