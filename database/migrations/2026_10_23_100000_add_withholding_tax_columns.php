<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Withholding tax on a sale or purchase: the tax picked (a `taxes` row of kind `withholding`) and the amount it came to, and a
 * flag on the payment row that books the withholding as a settlement of the invoice. Nothing is back-filled. Every company
 * branch that already has account mappings gets two empty ones to fill in: Withholding Tax Receivable (an asset: tax a customer
 * withheld from us) and Withholding Tax Payable (a liability: tax we withheld from a supplier).
 */
return new class extends Migration
{
    private const MAPPINGS = [
        ['Withholding Tax Receivable', 'withholdingreceivable'],
        ['Withholding Tax Payable', 'withholdingpayable'],
    ];

    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('withholding_tax_id')->nullable()->index()->after('tax_inclusive');
            $table->decimal('withholding_amount', 15, 2)->default(0)->after('withholding_tax_id');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->boolean('is_withholding')->default(false)->after('is_return');
        });

        $now = now();

        foreach (DB::table('chart_of_account_mappings')->whereNull('deleted_at')->select('company_id', 'branch_id')->distinct()->get() as $scope) {
            foreach (self::MAPPINGS as [$name, $key]) {
                $exists = DB::table('chart_of_account_mappings')->where('company_id', $scope->company_id)->where('branch_id', $scope->branch_id)->where('key', $key)->exists();

                if (! $exists) {
                    DB::table('chart_of_account_mappings')->insert([
                        'company_id' => $scope->company_id, 'branch_id' => $scope->branch_id, 'name' => $name, 'key' => $key,
                        'value' => null, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('chart_of_account_mappings')->whereIn('key', array_column(self::MAPPINGS, 1))->whereNull('value')->delete();

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('is_withholding');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_withholding_tax_id_index');
            $table->dropColumn(['withholding_tax_id', 'withholding_amount']);
        });
    }
};
