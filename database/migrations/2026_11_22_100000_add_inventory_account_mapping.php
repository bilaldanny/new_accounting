<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The balance sheet adds the value of the stock as an asset line unless the company already journals its stock to an account. That
 * account is the one mapped to the `inventory` key, so every company branch gets the key (empty). An empty key changes nothing: a
 * company that maps it and posts to it is the one that no longer gets the stock line.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('chart_of_account_mappings')->select('company_id', 'branch_id')->distinct()->get() as $scope) {
            $exists = DB::table('chart_of_account_mappings')->where('company_id', $scope->company_id)->where('branch_id', $scope->branch_id)->where('key', 'inventory')->exists();

            if (! $exists) {
                DB::table('chart_of_account_mappings')->insert([
                    'company_id' => $scope->company_id, 'branch_id' => $scope->branch_id, 'name' => 'Inventory', 'key' => 'inventory', 'value' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('chart_of_account_mappings')->where('key', 'inventory')->whereNull('value')->delete();
    }
};
