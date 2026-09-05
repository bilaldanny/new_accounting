<?php

use Illuminate\Support\Facades\DB;

/**
 * @return array{company_id: int, branch_one_id: int, branch_two_id: int}
 */
function seedCompanyAndBranches(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CMP001',
        'name' => 'Test Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchOneId = DB::table('branches')->insertGetId([
        'code' => 'BR001',
        'company_id' => $companyId,
        'name' => 'Branch One',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchTwoId = DB::table('branches')->insertGetId([
        'code' => 'BR002',
        'company_id' => $companyId,
        'name' => 'Branch Two',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
        'branch_one_id' => $branchOneId,
        'branch_two_id' => $branchTwoId,
    ];
}
