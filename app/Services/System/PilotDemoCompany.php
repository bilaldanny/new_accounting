<?php

namespace App\Services\System;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ContactImporter;
use App\Services\SelfSignup;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

/**
 * A complete pilot demonstration company: its own tenant (code `DEMO-PILOT`, never a row of a real company) with a Pakistani trading
 * business's chart of accounts, customers, suppliers, stock, a month of purchases, sales, returns and payments, a payroll month
 * and a finished production order.
 *
 * Every document goes through the application's own API as the platform superadmin (the same validation, stock and journal posting
 * as the screens), so the numbers are produced by the real code. Running it again finds the company and changes nothing; only
 * {@see self::remove()} (which refuses a company that is not a demo company) starts it over. It creates no statutory rates or
 * tax slabs: payroll tax rates are entered by the accountant (see docs/known-limitations.md).
 */
class PilotDemoCompany
{
    public const CODE = 'DEMO-PILOT';

    public const ADMIN_EMAIL = 'pilot-admin@demo.invalid';

    /** The platform superadmin the documents are posted as: the demo company's own admin is only created for the demo to be used. */
    private User $actor;

    private User $admin;

    private int $companyId;

    private int $branchId;

    private CarbonImmutable $month;

    /** @var array<string, int> chart of accounts: key => account id */
    private array $accounts = [];

    /** @var array<string, array{product_id: int, variation_id: int, unit_id: int, buy: float, sell: float}> */
    private array $products = [];

    /** @var list<int> */
    private array $customers = [];

    /** @var list<int> */
    private array $suppliers = [];

    /** @var array<string, int> */
    private array $summary = [];

    /**
     * @return array{created: bool, company_id: int, code: string, email: string, password: string|null, summary: array<string, int>}
     */
    public function build(?CarbonImmutable $today = null): array
    {
        $existing = Company::query()->where('code', self::CODE)->first();

        if ($existing !== null) {
            return ['created' => false, 'company_id' => (int) $existing->id, 'code' => self::CODE, 'email' => self::ADMIN_EMAIL, 'password' => null, 'summary' => []];
        }

        $this->actor = User::query()->where('role_id', 1)->orderBy('id')->first()
            ?? throw new RuntimeException('The pilot demo is built as the platform superadmin, and there is none in this database.');
        $this->month = ($today ?? CarbonImmutable::now())->subMonthNoOverflow()->startOfMonth();
        $password = Str::random(12).'aA9!';

        try {
            $this->signUp($password);
            $this->openFinancialYear();
            $this->buildChartOfAccounts();
            $this->buildCatalogue();
            $this->buildContacts();
            $this->tradeForAMonth();
            $this->runPayroll();
            $this->produce();
        } catch (\Throwable $e) {
            if (isset($this->companyId)) {
                $this->purge($this->companyId);
            }

            throw $e;
        }

        return ['created' => true, 'company_id' => $this->companyId, 'code' => self::CODE, 'email' => self::ADMIN_EMAIL, 'password' => $password, 'summary' => $this->summary];
    }

    /**
     * Removes the pilot company with the same purge a cancelled tenant gets. Refuses anything that is not a demo company.
     */
    public function remove(): bool
    {
        $company = Company::query()->where('code', self::CODE)->first();

        if ($company === null || ! str_starts_with((string) $company->code, DemoCompany::PREFIX)) {
            return false;
        }

        $this->purge((int) $company->id);

        return true;
    }

    private function purge(int $companyId): void
    {
        $company = Company::query()->find($companyId);

        if ($company === null || ! str_starts_with((string) $company->code, DemoCompany::PREFIX)) {
            return;
        }

        app(DemoCompany::class)->remove($companyId);

        // the purge keeps the (now inactive) company row as a record; free the code so the demo can be built again
        DB::table('companies')->where('id', $companyId)->update(['code' => self::CODE.'-REMOVED-'.$companyId]);
    }

    private function signUp(string $password): void
    {
        $user = app(SelfSignup::class)->register([
            'company_name' => 'Karachi Pilot Traders (demo)', 'company_email' => 'info@pilot.demo.invalid', 'company_phone' => '021-0000000', 'address' => 'Plot 12, Korangi Industrial Area, Karachi',
            'ntn_no' => '0000000-0', 'first_name' => 'Pilot', 'last_name' => 'Admin', 'username' => 'pilot_admin', 'email' => self::ADMIN_EMAIL, 'password' => $password,
        ]);

        $this->companyId = (int) $user->company_id;
        DB::table('companies')->where('id', $this->companyId)->update(['code' => self::CODE, 'updated_at' => now()]);
        $this->branchId = (int) ($user->branch_id ?: Branch::query()->where('company_id', $this->companyId)->value('id'));
        $this->admin = $user;
    }

    private function openFinancialYear(): void
    {
        $startYear = $this->month->month >= 7 ? $this->month->year : $this->month->year - 1;

        FinancialYear::query()->create([
            'company_id' => $this->companyId, 'name' => "FY {$startYear}-".($startYear + 1), 'start_date' => "{$startYear}-07-01", 'end_date' => ($startYear + 1).'-06-30', 'status' => true,
        ]);
    }

    /**
     * The groups and ledger accounts of a trading company, and the mapping of the posting keys to them. A new company gets only the six
     * top level accounts and empty mappings, so the accountant normally builds this; the demo company starts with it done.
     */
    private function buildChartOfAccounts(): void
    {
        $top = ChartOfAccount::query()->where('company_id', $this->companyId)->where('branch_id', $this->branchId)->pluck('id', 'code');

        $group = function (string $key, string $code, string $name, int $parent, string $nature, bool $profitAndLoss) {
            return $this->accounts[$key] = $this->account($code, $name, 'c', $parent, $nature, $profitAndLoss);
        };
        $leaf = function (string $key, string $code, string $name, int $parent, string $nature, bool $profitAndLoss = false) {
            return $this->accounts[$key] = $this->account($code, $name, 't', $parent, $nature, $profitAndLoss);
        };

        $currentAssets = $group('current_assets', '210-00000', 'Current Assets', $top['200-00000'], 'dr', false);
        $receivables = $group('receivables', '211-00000', 'Trade Receivables', $currentAssets, 'dr', false);
        $cashAndBank = $group('cash_bank', '212-00000', 'Cash and Bank', $currentAssets, 'dr', false);
        $leaf('cash', '212-00001', 'Cash in Hand', $cashAndBank, 'dr');
        $leaf('bank', '212-00002', 'Meezan Bank Current Account', $cashAndBank, 'dr');
        $inventory = $group('inventory_group', '213-00000', 'Inventory', $currentAssets, 'dr', false);
        $leaf('inventory', '213-00001', 'Stock in Trade', $inventory, 'dr');
        $taxAsset = $group('tax_asset_group', '214-00000', 'Taxes Recoverable', $currentAssets, 'dr', false);
        $leaf('input_tax', '214-00001', 'Sales Tax Input', $taxAsset, 'dr');
        $staff = $group('staff_group', '215-00000', 'Staff Receivables', $currentAssets, 'dr', false);
        $leaf('staff_loans', '215-00001', 'Staff Loans and Advances', $staff, 'dr');

        $currentLiabilities = $group('current_liabilities', '310-00000', 'Current Liabilities', $top['300-00000'], 'cr', false);
        $payables = $group('payables', '311-00000', 'Trade Payables', $currentLiabilities, 'cr', false);
        $taxLiabilities = $group('tax_liability_group', '312-00000', 'Taxes and Contributions Payable', $currentLiabilities, 'cr', false);
        $leaf('output_tax', '312-00001', 'Sales Tax Output', $taxLiabilities, 'cr');
        $leaf('income_tax_payable', '312-00002', 'Income Tax Payable (salaries)', $taxLiabilities, 'cr');
        $leaf('statutory_payable', '312-00003', 'Statutory Contributions Payable', $taxLiabilities, 'cr');
        $accrued = $group('accrued_group', '313-00000', 'Accrued Liabilities', $currentLiabilities, 'cr', false);
        $leaf('salaries_payable', '313-00001', 'Salaries Payable', $accrued, 'cr');
        $advances = $group('advances_group', '314-00000', 'Customer Advances', $currentLiabilities, 'cr', false);
        $leaf('customer_advance', '314-00001', 'Advances from Customers', $advances, 'cr');

        $capital = $group('capital_group', '110-00000', 'Capital and Reserves', $top['100-00000'], 'cr', false);
        $leaf('capital', '110-00001', "Owner's Capital", $capital, 'cr');
        $leaf('pnl', '110-00002', 'Retained Earnings', $capital, 'cr');

        $salesGroup = $group('sales_group', '510-00000', 'Sales', $top['500-00000'], 'cr', true);
        $leaf('local_sales', '510-00001', 'Local Sales', $salesGroup, 'cr', true);
        $leaf('export_sales', '510-00002', 'Export Sales', $salesGroup, 'cr', true);

        $cogs = $group('cogs_group', '610-00000', 'Cost of Sales', $top['600-00000'], 'dr', true);
        $leaf('cogs', '610-00001', 'Cost of Goods Sold', $cogs, 'dr', true);
        $leaf('purchases', '610-00002', 'Purchases', $cogs, 'dr', true);

        $operating = $group('operating_group', '410-00000', 'Operating Expenses', $top['400-00000'], 'dr', true);
        $leaf('salaries_expense', '410-00001', 'Salaries and Wages', $operating, 'dr', true);
        $leaf('rent', '410-00002', 'Shop Rent', $operating, 'dr', true);
        $leaf('utilities', '410-00003', 'Electricity and Utilities', $operating, 'dr', true);

        $mapping = [
            'equity' => $top['100-00000'], 'assets' => $top['200-00000'], 'liability' => $top['300-00000'], 'expenses' => $top['400-00000'], 'revenue' => $top['500-00000'],
            'customer' => $receivables, 'supplier' => $payables, 'customeradvance' => $this->accounts['customer_advance'], 'bank' => $cashAndBank, 'cash' => $this->accounts['cash'],
            'purchase' => $this->accounts['purchases'], 'importpurchase' => $this->accounts['purchases'], 'localpurchase' => $this->accounts['purchases'], 'inputtax' => $this->accounts['input_tax'],
            'sale' => $this->accounts['local_sales'], 'localsales' => $this->accounts['local_sales'], 'exportsale' => $this->accounts['export_sales'], 'outputtax' => $this->accounts['output_tax'],
            'pnl' => $this->accounts['pnl'],
        ];

        foreach ($mapping as $key => $accountId) {
            DB::table('chart_of_account_mappings')->where('company_id', $this->companyId)->where('branch_id', $this->branchId)->where('key', $key)
                ->update(['value' => (string) $accountId, 'updated_at' => now()]);
        }

        $this->summary['accounts'] = ChartOfAccount::query()->where('company_id', $this->companyId)->count();
    }

    private function account(string $code, string $name, string $type, int $parent, string $nature, bool $profitAndLoss): int
    {
        return (int) ChartOfAccount::query()->create([
            'company_id' => $this->companyId, 'branch_id' => $this->branchId, 'parent_id' => $parent, 'code' => $code, 'name' => $name, 'acc_type' => $type,
            'acc_nature' => $nature, 'pl' => $profitAndLoss, 'bs' => ! $profitAndLoss, 'active' => true,
        ])->id;
    }

    private function buildCatalogue(): void
    {
        $units = [];

        foreach (['Piece' => 'pc', 'Kilogram' => 'kg', 'Carton' => 'ctn'] as $name => $short) {
            $units[$short] = (int) DB::table('units')->insertGetId(['company_id' => $this->companyId, 'name' => $name, 'short_name' => $short, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        // key => [name, unit, purchase price, sell price]
        $catalogue = [
            'rice' => ['Super Kernel Basmati Rice 5 kg', 'pc', 1450, 1750],
            'sugar' => ['Refined Sugar 1 kg', 'pc', 150, 175],
            'oil' => ['Dalda Cooking Oil 5 L', 'pc', 2650, 2900],
            'tea' => ['Tapal Danedar Tea 190 g', 'pc', 330, 380],
            'masala' => ['Shan Biryani Masala 50 g', 'pc', 115, 135],
            'salt' => ['National Iodized Salt 800 g', 'pc', 60, 75],
            'milk' => ['Milkpak UHT Milk 1 L (carton of 12)', 'ctn', 2300, 2500],
            'detergent' => ['Surf Excel Detergent 1 kg', 'pc', 520, 590],
            'soap' => ['Lifebuoy Soap 3 pack', 'pc', 210, 245],
            'flour' => ['Chakki Atta 10 kg', 'pc', 1380, 1550],
            'carton' => ['Gift Carton (empty)', 'pc', 90, 0],
            'combo' => ['Family Ration Combo (rice, oil, sugar)', 'pc', 0, 7000],
        ];
        $sequence = 0;

        foreach ($catalogue as $key => [$name, $unit, $buy, $sell]) {
            $sequence++;
            $sku = sprintf('PLT-%03d', $sequence);
            $productId = (int) DB::table('products')->insertGetId([
                'company_id' => $this->companyId, 'unit_id' => $units[$unit], 'name' => $name, 'sku' => $sku, 'active' => 1, 'type' => 'single', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $variationId = (int) DB::table('product_details')->insertGetId([
                'product_id' => $productId, 'name' => $name, 'sku' => $sku, 'variation_name' => 'Default', 'default_purchase_price' => $buy, 'dpp_unit_price' => $buy, 'largequantity' => 0,
                'smallquantity' => 0, 'profit_percent' => $buy > 0 ? round(($sell - $buy) / $buy * 100, 2) : 0, 'default_sell_price' => $sell, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->products[$key] = ['product_id' => $productId, 'variation_id' => $variationId, 'unit_id' => $units[$unit], 'buy' => (float) $buy, 'sell' => (float) $sell];
        }

        $this->summary['products'] = count($this->products);
    }

    private function buildContacts(): void
    {
        $importer = app(ContactImporter::class);
        $customers = [
            ['Al-Madina Kiryana Store', 'Karachi, Saddar'], ['Bismillah General Store', 'Lahore, Model Town'], ['Hafiz Brothers Wholesale', 'Faisalabad, Ghulam Muhammad Abad'],
            ['Metro Mart Gulshan', 'Karachi, Gulshan-e-Iqbal'], ['Sana Departmental Store', 'Islamabad, G-9 Markaz'], ['Rehmat Traders', 'Multan, Hussain Agahi'],
        ];
        $suppliers = [
            ['Haji Abdul Rehman and Sons', 'Karachi, Jodia Bazaar'], ['Punjab Edible Oil Mills', 'Lahore, Sundar Industrial Estate'],
            ['Karachi Sugar Dealers', 'Karachi, Napier Road'], ['Packages and Cartons Company', 'Karachi, SITE Area'],
        ];

        foreach (['customer' => $customers, 'supplier' => $suppliers] as $kind => $rows) {
            $result = $importer->run($kind, array_map(fn (array $row, int $i): array => [
                'business_name' => $row[0], 'mobile' => sprintf('0300%07d', ($kind === 'customer' ? 1000 : 2000) + $i), 'address' => $row[1],
                'ntn_number' => sprintf('%07d-%d', ($kind === 'customer' ? 3000 : 4000) + $i, $i % 10), 'credit_limit' => $kind === 'customer' ? 500000 : 0, 'pay_term' => $kind === 'customer' ? 15 : 30, 'pay_type' => 'day',
            ], $rows, array_keys($rows)), $this->companyId, $this->branchId, false);

            if (! $result['ok']) {
                throw new RuntimeException("Demo {$kind}s could not be created: ".json_encode($result['rows']));
            }

            foreach ($result['rows'] as $row) {
                $kind === 'customer' ? $this->customers[] = (int) $row['contact_id'] : $this->suppliers[] = (int) $row['contact_id'];
            }
        }

        $this->summary['customers'] = count($this->customers);
        $this->summary['suppliers'] = count($this->suppliers);
    }

    private function tradeForAMonth(): void
    {
        $date = fn (int $day): string => $this->month->addDays($day - 1)->toDateString();

        // purchases first so there is stock to sell: [supplier index, day, [product key => quantity], paid in full?, paid how]
        $purchases = [
            [0, 2, ['rice' => 40, 'sugar' => 120, 'salt' => 100, 'masala' => 150, 'flour' => 30], 'cash'],
            [1, 3, ['oil' => 30], 'bank_transfer'],
            [2, 4, ['sugar' => 100, 'tea' => 60], null],
            [3, 5, ['carton' => 60], 'cash'],
            [0, 9, ['detergent' => 50, 'soap' => 80, 'milk' => 20, 'tea' => 40], null],
        ];
        $purchaseIds = [];

        foreach ($purchases as [$supplier, $day, $items, $paid]) {
            $id = $this->purchase($this->suppliers[$supplier], $date($day), $items);
            $purchaseIds[] = $id;

            if ($paid !== null) {
                $this->pay('/api/purchase-payments', $id, $this->suppliers[$supplier], $this->documentTotal($id), $date($day + 1), $paid);
            } else {
                $this->pay('/api/purchase-payments', $id, $this->suppliers[$supplier], round($this->documentTotal($id) / 2, 2), $date($day + 2), 'bank_transfer');
            }
        }

        // sales: [customer index, day, [product key => quantity], paid share (0, 0.5 or 1)]
        $sales = [
            [0, 10, ['rice' => 6, 'sugar' => 20, 'salt' => 24, 'masala' => 30], 1],
            [1, 11, ['oil' => 8, 'flour' => 10, 'tea' => 24], 0.5],
            [2, 12, ['rice' => 12, 'sugar' => 40, 'oil' => 10, 'flour' => 12], 0],
            [3, 14, ['milk' => 8, 'detergent' => 20, 'soap' => 30], 1],
            [4, 15, ['tea' => 30, 'masala' => 40, 'salt' => 30], 0.5],
            [5, 17, ['rice' => 5, 'sugar' => 25, 'detergent' => 12], 0],
            [0, 19, ['soap' => 20, 'milk' => 6, 'tea' => 20], 1],
            [3, 21, ['oil' => 6, 'flour' => 8, 'rice' => 4], 0.5],
        ];
        $saleIds = [];

        foreach ($sales as [$customer, $day, $items, $share]) {
            $id = $this->sale($this->customers[$customer], $date($day), $items);
            $saleIds[] = $id;

            if ($share > 0) {
                $this->pay('/api/sell-payments', $id, $this->customers[$customer], round($this->documentTotal($id) * $share, 2), $date($day + 3), $share >= 1 ? 'cash' : 'bank_transfer');
            }
        }

        // a customer returns two bags of rice from the first sale, and a case of damaged detergent goes back to the supplier
        $this->saleReturn($saleIds[0], $this->customers[0], $date(22));
        $this->purchaseReturn($purchaseIds[4], $this->suppliers[0], $date(23));

        $this->summary['purchases'] = count($purchaseIds);
        $this->summary['sales'] = count($saleIds);
    }

    /**
     * @param  array<string, int|float>  $items  product key => quantity
     */
    private function purchase(int $supplier, string $date, array $items): int
    {
        $lines = [];
        $total = 0.0;

        foreach ($items as $key => $quantity) {
            $product = $this->products[$key];
            $lines[] = [
                'product_id' => $product['product_id'], 'variation_id' => $product['variation_id'], 'unit_id' => $product['unit_id'], 'quantity' => $quantity, 'packing_qty' => 1,
                'pp_without_discount' => $product['buy'], 'discount_percent' => 0, 'purchase_rate' => $product['buy'], 'profit_percent' => 0, 'default_sell_price' => $product['sell'],
            ];
            $total += $quantity * $product['buy'];
        }

        $this->call('POST', '/api/purchases', [
            'company_id' => $this->companyId, 'branch_id' => $this->branchId, 'contact_id' => $supplier, 'transaction_date' => $date, 'pay_term' => 30, 'pay_type' => 'day',
            'discount_type' => 'none', 'discount_amount' => 0, 'shipping_charges' => 0, 'final_amount' => $total, 'total_item' => count($lines), 'is_direct' => false, 'purchaselines' => $lines,
        ]);

        $id = (int) Transaction::query()->where('company_id', $this->companyId)->where('type', Transaction::TYPE_PURCHASE)->max('id');

        if (DB::table('transactions')->where('id', $id)->value('status') === 'pending') {
            $this->call('POST', "/api/purchase-approvals/{$id}/approve");
        }

        $received = DB::table('purchase_lines')->where('transaction_id', $id)->get(['id', 'quantity']);

        $this->call('POST', '/api/receiving-notes', [
            'company_id' => $this->companyId, 'branch_id' => $this->branchId, 'contact_id' => $supplier, 'transaction_id' => $id,
            'purchaselines' => $received->map(fn (object $line): array => ['id' => (int) $line->id, 'quantity_received' => (float) $line->quantity])->all(),
        ]);

        return $id;
    }

    /**
     * @param  array<string, int|float>  $items  product key => quantity
     */
    private function sale(int $customer, string $date, array $items): int
    {
        $lines = [];
        $total = 0.0;

        foreach ($items as $key => $quantity) {
            $product = $this->products[$key];
            $lines[] = [
                'product_id' => $product['product_id'], 'variation_id' => $product['variation_id'], 'unit_id' => $product['unit_id'], 'quantity' => $quantity, 'packing_qty' => 1,
                'unit_price' => $product['sell'], 'discount_percent' => 0, 'unit_price_after_discount' => $product['sell'], 'row_subtotal' => $quantity * $product['sell'],
            ];
            $total += $quantity * $product['sell'];
        }

        $response = $this->call('POST', '/api/sells', [
            'company_id' => $this->companyId, 'branch_id' => $this->branchId, 'contact_id' => $customer, 'transaction_date' => $date, 'pay_term' => 15, 'pay_type' => 'day',
            'status' => 'final', 'discount_type' => 'none', 'discount_amount' => 0, 'shipping_charges' => 0, 'final_amount' => $total, 'total_item' => count($lines), 'is_direct' => false,
            'selllines' => $lines,
        ]);
        $id = (int) ($response['id'] ?? Transaction::query()->where('company_id', $this->companyId)->where('type', Transaction::TYPE_SELL)->max('id'));

        if (DB::table('transactions')->where('id', $id)->value('status') !== 'approved') {
            $this->call('POST', "/api/sell-approvals/{$id}/approve");
        }

        $this->call('POST', '/api/issue-notes', [
            'company_id' => $this->companyId, 'branch_id' => $this->branchId, 'contact_id' => $customer, 'transaction_id' => $id,
            'selllines' => DB::table('sell_lines')->where('transaction_id', $id)->get(['id', 'quantity'])
                ->map(fn (object $line): array => ['id' => (int) $line->id, 'quantity_issue' => (float) $line->quantity])->all(),
        ]);

        return $id;
    }

    private function saleReturn(int $saleId, int $customer, string $date): void
    {
        $line = DB::table('sell_lines')->where('transaction_id', $saleId)->orderBy('id')->first(['id']);

        $this->call('POST', '/api/sell-returns', [
            'company_id' => $this->companyId, 'branch_id' => $this->branchId, 'contact_id' => $customer, 'transaction_id' => $saleId, 'transaction_date' => $date,
            'selllines' => [['id' => (int) $line->id, 'quantity_returned' => 2]],
        ]);
        $this->summary['sale_returns'] = ($this->summary['sale_returns'] ?? 0) + 1;
    }

    private function purchaseReturn(int $purchaseId, int $supplier, string $date): void
    {
        $line = DB::table('purchase_lines')->where('transaction_id', $purchaseId)->orderBy('id')->first(['id']);

        $this->call('POST', '/api/purchase-returns', [
            'company_id' => $this->companyId, 'branch_id' => $this->branchId, 'contact_id' => $supplier, 'transaction_id' => $purchaseId, 'transaction_date' => $date,
            'purchaselines' => [['id' => (int) $line->id, 'quantity_returned' => 3]],
        ]);
        $note = (int) Transaction::query()->where('company_id', $this->companyId)->where('type', Transaction::TYPE_PURCHASE_RETURN)->max('id');
        $this->call('POST', "/api/purchase-return-approvals/{$note}/approve");
        $this->summary['purchase_returns'] = ($this->summary['purchase_returns'] ?? 0) + 1;
    }

    private function documentTotal(int $transactionId): float
    {
        return (float) DB::table('transactions')->where('id', $transactionId)->value('final_amount');
    }

    private function pay(string $uri, int $transactionId, int $contact, float $amount, string $date, string $method): void
    {
        $this->call('POST', $uri, [
            'company_id' => $this->companyId, 'branch_id' => $this->branchId, 'contact_id' => $contact, 'transaction_id' => $transactionId, 'amount' => $amount, 'paid_on' => $date,
            'method' => $method, 'payment_account' => $method === 'cash' ? $this->accounts['cash'] : $this->accounts['bank'], 'note' => 'Pilot demo',
            'bank_account_number' => $method === 'cash' ? null : 'PK00MEZN0000000001',
        ]);
        $this->summary['payments'] = ($this->summary['payments'] ?? 0) + 1;
    }

    private function runPayroll(): void
    {
        $period = $this->month->format('Y-m');

        $this->call('PUT', '/api/hrm/settings', ['posting_enabled' => true, 'accounts' => [
            'salary_expense' => $this->accounts['salaries_expense'], 'salary_payable' => $this->accounts['salaries_payable'], 'tax_payable' => $this->accounts['income_tax_payable'],
            'statutory_payable' => $this->accounts['statutory_payable'], 'loan_receivable' => $this->accounts['staff_loans'],
        ]]);

        $staff = [
            ['E-001', 'Muhammad', 'Imran', 'Accountant', 55000], ['E-002', 'Ayesha', 'Siddiqui', 'Sales Executive', 42000],
            ['E-003', 'Bilal', 'Ahmed', 'Store Keeper', 35000], ['E-004', 'Usman', 'Ghani', 'Delivery Rider', 30000],
        ];

        foreach ($staff as [$number, $first, $last, $title, $basic]) {
            $this->call('POST', '/api/hrm/employees', [
                'employee_no' => $number, 'first_name' => $first, 'last_name' => $last, 'designation' => $title, 'employment_type' => 'permanent',
                'join_date' => $this->month->subMonths(6)->toDateString(), 'bank_name' => 'Meezan Bank', 'bank_account' => 'PK00MEZN'.str_pad((string) (int) substr($number, 2), 14, '0', STR_PAD_LEFT),
            ]);
            $employee = (int) DB::table('employees')->where('company_id', $this->companyId)->where('employee_no', $number)->value('id');
            $this->call('POST', "/api/hrm/employees/{$employee}/salary", ['effective_from' => $this->month->subMonths(6)->toDateString(), 'basic' => $basic]);
        }

        $run = (int) ($this->call('POST', '/api/hrm/payroll', ['period' => $period])['data']['id'] ?? 0);
        $this->call('POST', "/api/payroll-runs/{$run}/approve");
        $this->call('POST', "/api/hrm/payroll/{$run}/post");

        $this->summary['employees'] = count($staff);
        $this->summary['payroll_runs'] = 1;
    }

    private function produce(): void
    {
        $bom = (int) ($this->call('POST', '/api/manufacturing/boms', [
            'product_id' => $this->products['combo']['product_id'], 'name' => 'Family Ration Combo', 'output_qty' => 1, 'items' => [
                ['component_product_id' => $this->products['rice']['product_id'], 'quantity' => 1],
                ['component_product_id' => $this->products['oil']['product_id'], 'quantity' => 1],
                ['component_product_id' => $this->products['sugar']['product_id'], 'quantity' => 2],
                ['component_product_id' => $this->products['carton']['product_id'], 'quantity' => 1],
            ],
            'operations' => [['name' => 'Packing', 'minutes' => 10, 'labor_rate_per_hour' => 300, 'overhead_rate_per_hour' => 60]],
        ])['data']['id'] ?? 0);
        $this->call('POST', "/api/boms/{$bom}/approve");

        $order = (int) ($this->call('POST', '/api/manufacturing/work-orders', [
            'product_id' => $this->products['combo']['product_id'], 'planned_qty' => 5, 'planned_start' => $this->month->addDays(24)->toDateString(),
        ])['data']['id'] ?? 0);
        $this->call('POST', "/api/work-orders/{$order}/approve");

        $materials = DB::table('work_order_materials')->where('work_order_id', $order)->get();
        $this->call('POST', "/api/manufacturing/work-orders/{$order}/issue", ['date' => $this->month->addDays(25)->toDateString(), 'lines' => $materials->map(fn (object $material): array => [
            'product_id' => (int) $material->product_id, 'variation_id' => (int) $material->variation_id, 'quantity' => (float) $material->required_qty,
        ])->all()]);
        $this->call('POST', "/api/manufacturing/work-orders/{$order}/standard-labor");
        $this->call('POST', "/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 5, 'date' => $this->month->addDays(26)->toDateString()]);

        $this->summary['production_orders'] = 1;
    }

    /**
     * One request through the application's own HTTP kernel as the superadmin, so validation, stock and journal posting are exactly
     * what a screen gets.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, array $payload = []): array
    {
        Sanctum::actingAs($this->actor, ['*']);

        // the superadmin has no company of its own: every request names the demo company and branch, like the screens do for it
        $payload += ['company_id' => $this->companyId, 'branch_id' => $this->branchId];
        $request = Request::create($uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($payload));
        $response = app(Kernel::class)->handle($request);
        $body = json_decode((string) $response->getContent(), true);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException("Pilot demo: {$method} {$uri} answered {$response->getStatusCode()}: ".Str::limit((string) $response->getContent(), 600));
        }

        return is_array($body) ? $body : [];
    }
}
