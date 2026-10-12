<?php

namespace App\Services\System;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\ContactImporter;
use App\Services\Saas\TenantLifecycle;
use App\Services\SelfSignup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A sandbox company for demonstrations and training: always a separate company whose code starts with `DEMO-`, never data inside a
 * real company. It gets the usual starting set (branch, chart of accounts, an admin user) through the self sign-up path, a few
 * customers and suppliers through the contact importer and a short product list. It carries no sales, purchases or vouchers, so
 * a trainee makes those. The password of its admin is shown once to the superadmin who created it. Removing refuses any company
 * whose code does not start with `DEMO-`.
 */
class DemoCompany
{
    public const PREFIX = 'DEMO-';

    /**
     * @return array{company_id: int, code: string, email: string, password: string}
     */
    public function create(string $name = 'Demo Traders'): array
    {
        $token = Str::lower(Str::random(6));
        $password = Str::random(14).'9a';
        $email = "demo-{$token}@demo.invalid";

        $user = app(SelfSignup::class)->register([
            'company_name' => $name.' (sandbox)', 'company_email' => $email, 'email' => $email, 'first_name' => 'Demo', 'last_name' => 'Admin',
            'username' => "demo_{$token}", 'password' => $password,
        ]);

        $companyId = (int) $user->company_id;
        DB::table('companies')->where('id', $companyId)->update(['code' => self::PREFIX.strtoupper($token), 'updated_at' => now()]);
        $branchId = (int) ($user->branch_id ?: Branch::query()->where('company_id', $companyId)->value('id'));

        $importer = app(ContactImporter::class);
        $importer->run('customer', [
            ['business_name' => 'Demo Retail Store', 'mobile' => '03001110001', 'email' => 'retail@demo.invalid', 'address' => 'Main Market'],
            ['business_name' => 'Demo Wholesale Co', 'mobile' => '03001110002', 'email' => 'wholesale@demo.invalid', 'address' => 'Industrial Area'],
            ['business_name' => 'Demo Walk-in Customer', 'mobile' => '03001110003', 'address' => 'Counter'],
        ], $companyId, $branchId, false);
        $importer->run('supplier', [
            ['business_name' => 'Demo Supplies Ltd', 'mobile' => '03002220001', 'email' => 'supplies@demo.invalid', 'address' => 'Wholesale Road'],
            ['business_name' => 'Demo Packaging', 'mobile' => '03002220002', 'address' => 'Port Area'],
        ], $companyId, $branchId, false);

        $unit = DB::table('units')->insertGetId(['company_id' => $companyId, 'name' => 'Piece', 'short_name' => 'pc', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);

        foreach (['Demo Notebook' => 120, 'Demo Pen Box' => 450, 'Demo Folder' => 80] as $productName => $price) {
            $productId = DB::table('products')->insertGetId([
                'company_id' => $companyId, 'unit_id' => $unit, 'name' => $productName, 'sku' => 'DEMO-'.Str::upper(Str::random(5)), 'active' => 1, 'type' => 'single', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('product_details')->insert([
                'product_id' => $productId, 'name' => $productName, 'sku' => 'DEMO-'.Str::upper(Str::random(5)), 'variation_name' => 'Default', 'default_purchase_price' => round($price * 0.7, 2),
                'dpp_unit_price' => round($price * 0.7, 2), 'largequantity' => 0, 'smallquantity' => 0, 'profit_percent' => 30, 'default_sell_price' => $price, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['company_id' => $companyId, 'code' => self::PREFIX.strtoupper($token), 'email' => $email, 'password' => $password];
    }

    /**
     * Removes the data of a demo company (the same purge a cancelled tenant gets). Refuses anything that is not a demo company.
     */
    public function remove(int $companyId): bool
    {
        $company = Company::query()->find($companyId);

        if ($company === null || ! str_starts_with((string) $company->code, self::PREFIX)) {
            return false;
        }

        app(TenantLifecycle::class)->purge($companyId);
        User::query()->where('company_id', $companyId)->delete();

        return true;
    }
}
