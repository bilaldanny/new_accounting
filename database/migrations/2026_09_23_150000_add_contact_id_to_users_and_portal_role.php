<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A portal user is a user tied to one customer or supplier (`users.contact_id`) who can only see that
     * contact's own balance and recent documents. They get the global `portal` role, which holds no permissions
     * and is marked `is_admin` so it is not offered when an admin picks a role for an ordinary user. Additive:
     * a nullable column and one role row.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'contact_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->bigInteger('contact_id')->unsigned()->nullable()->after('department_id')->index();
            });
        }

        $exists = DB::table('roles')->where('name', 'portal')->whereNull('company_id')->exists();

        if (! $exists) {
            DB::table('roles')->insert([
                'name' => 'portal',
                'company_id' => null,
                'branch_id' => null,
                'is_active' => 1,
                'is_admin' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('roles')->where('name', 'portal')->whereNull('company_id')->delete();

        if (Schema::hasColumn('users', 'contact_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('contact_id');
            });
        }
    }
};
