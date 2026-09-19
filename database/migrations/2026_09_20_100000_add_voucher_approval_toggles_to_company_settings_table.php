<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One auto-approval toggle per manual voucher type, next to purchase_approval, sell_approval and
 * journal_entry (Settings > Approval): with the toggle on a new voucher is approved on save, with
 * it off it waits in the approval list.
 *
 * Until now these four vouchers were approved on save unless the user was the superadmin, so the
 * toggles start ON for the companies that already exist: nothing changes for them until someone
 * switches a toggle off. New companies get the column default, off (approval required).
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const COLUMNS = [
        'payment_voucher_approval',
        'expense_approval',
        'deposit_approval',
        'fund_transfer_approval',
    ];

    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->boolean($column)->default(0)->after('journal_entry');
            }
        });

        DB::table('company_settings')->update(array_fill_keys(self::COLUMNS, 1));
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
