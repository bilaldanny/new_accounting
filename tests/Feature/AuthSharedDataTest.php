<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('shared auth user includes company name', function () {
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CMP001',
        'name' => 'Acme Corporation',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $roleId = DB::table('roles')->insertGetId([
        'name' => 'companyadmin',
        'is_active' => 1,
        'is_admin' => 0,
        'company_id' => $companyId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $userId = DB::table('users')->insertGetId([
        'role_id' => $roleId,
        'company_id' => $companyId,
        'first_name' => 'Company',
        'last_name' => 'Admin',
        'username' => 'companyadmin',
        'email' => 'companyadmin@example.com',
        'password' => Hash::make('password'),
        'pass' => 'password',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = User::query()->findOrFail($userId);

    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.company_id', $companyId)
            ->where('auth.user.company_name', 'Acme Corporation')
        );
});
