<?php

namespace App\Services\System;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Company\CompanyBootstrap;
use App\Services\ContactImporter;
use App\Services\SelfSignup;
use App\Services\StockMovements;
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

    private string $code = self::CODE;

    private string $adminEmail = self::ADMIN_EMAIL;

    /**
     * Set for a showcase company: name, address, username, catalogue, customers, suppliers and the volume (months, sales and purchases
     * a month). Null is the pilot company with its single month, exactly as before.
     *
     * @var array<string, mixed>|null
     */
    private ?array $profile = null;

    private CarbonImmutable $today;

    /**
     * @return array{created: bool, company_id: int, code: string, email: string, password: string|null, summary: array<string, int>}
     */
    public function build(?CarbonImmutable $today = null): array
    {
        $existing = Company::query()->where('code', $this->code)->first();

        if ($existing !== null) {
            return ['created' => false, 'company_id' => (int) $existing->id, 'code' => $this->code, 'email' => $this->adminEmail, 'password' => null, 'summary' => []];
        }

        $this->actor = User::query()->where('role_id', 1)->orderBy('id')->first()
            ?? throw new RuntimeException('The pilot demo is built as the platform superadmin, and there is none in this database.');
        $this->today = $today ?? CarbonImmutable::now();
        $this->month = $this->today->subMonthNoOverflow()->startOfMonth();
        $password = Str::random(12).'aA9!';

        try {
            $this->signUp($password);
            $this->loadChartOfAccounts();
            $this->buildCatalogue();
            $this->buildContacts();
            $this->profile === null ? $this->tradeForAMonth() : $this->tradeGenerated();
            $this->runPayroll();

            if (isset($this->products['combo'])) {
                $this->produce();
            }
        } catch (\Throwable $e) {
            if (isset($this->companyId)) {
                $this->purge($this->companyId);
            }

            throw $e;
        }

        return ['created' => true, 'company_id' => $this->companyId, 'code' => $this->code, 'email' => $this->adminEmail, 'password' => $password, 'summary' => $this->summary];
    }

    /**
     * One of the showcase companies: its own name, people, catalogue and several months of trade (see ShowcaseDemoCompanies).
     *
     * @param  array<string, mixed>  $profile
     * @return array{created: bool, company_id: int, code: string, email: string, password: string|null, summary: array<string, int>}
     */
    public function buildShowcase(array $profile, ?CarbonImmutable $today = null): array
    {
        $this->profile = $profile;
        $this->code = (string) $profile['code'];
        $this->adminEmail = (string) $profile['admin_email'];

        return $this->build($today);
    }

    /**
     * Removes the pilot company with the same purge a cancelled tenant gets. Refuses anything that is not a demo company.
     */
    public function remove(?string $code = null): bool
    {
        $company = Company::query()->where('code', $code ?? $this->code)->first();

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
            'company_name' => $this->profile['name'] ?? 'Karachi Pilot Traders (demo)', 'company_email' => $this->profile['company_email'] ?? 'info@pilot.demo.invalid', 'company_phone' => '021-0000000',
            'address' => $this->profile['address'] ?? 'Plot 12, Korangi Industrial Area, Karachi', 'ntn_no' => '0000000-0', 'first_name' => $this->profile['admin_first'] ?? 'Pilot',
            'last_name' => 'Admin', 'username' => $this->profile['username'] ?? 'pilot_admin', 'email' => $this->adminEmail, 'password' => $password,
        ]);

        $this->companyId = (int) $user->company_id;
        DB::table('companies')->where('id', $this->companyId)->update(['code' => $this->code, 'updated_at' => now()]);
        $this->branchId = (int) ($user->branch_id ?: Branch::query()->where('company_id', $this->companyId)->value('id'));
        $this->admin = $user;
    }

    /**
     * The financial year, the chart of accounts, the mappings and the first units are made by the company bootstrap at sign-up (the
     * same step the public registration and the Company page use); the demo only reads the account ids it posts to.
     */
    private function loadChartOfAccounts(): void
    {
        $this->accounts = app(CompanyBootstrap::class)->accountIds($this->companyId, $this->branchId);

        if (! isset($this->accounts['cash'], $this->accounts['bank'], $this->accounts['salaries_expense'])) {
            throw new RuntimeException('The company bootstrap did not create the starting chart of accounts.');
        }

        $this->summary['accounts'] = ChartOfAccount::query()->where('company_id', $this->companyId)->count();
    }

    private function buildCatalogue(): void
    {
        $units = [];

        foreach (['Piece' => 'pc', 'Kilogram' => 'kg', 'Carton' => 'ctn'] as $name => $short) {
            $existing = DB::table('units')->where('company_id', $this->companyId)->where('short_name', $short)->value('id');
            $units[$short] = $existing !== null ? (int) $existing : null;

            if ($existing !== null) {
                continue;
            }

            $units[$short] = (int) DB::table('units')->insertGetId(['company_id' => $this->companyId, 'name' => $name, 'short_name' => $short, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        // key => [name, unit, purchase price, sell price]
        $catalogue = $this->profile['catalogue'] ?? [
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
            $sku = sprintf('%s-%03d', $this->profile['sku_prefix'] ?? 'PLT', $sequence);
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
        $customers = $this->profile['customers'] ?? [
            ['Al-Madina Kiryana Store', 'Karachi, Saddar'], ['Bismillah General Store', 'Lahore, Model Town'], ['Hafiz Brothers Wholesale', 'Faisalabad, Ghulam Muhammad Abad'],
            ['Metro Mart Gulshan', 'Karachi, Gulshan-e-Iqbal'], ['Sana Departmental Store', 'Islamabad, G-9 Markaz'], ['Rehmat Traders', 'Multan, Hussain Agahi'],
        ];
        $suppliers = $this->profile['suppliers'] ?? [
            ['Haji Abdul Rehman and Sons', 'Karachi, Jodia Bazaar'], ['Punjab Edible Oil Mills', 'Lahore, Sundar Industrial Estate'],
            ['Karachi Sugar Dealers', 'Karachi, Napier Road'], ['Packages and Cartons Company', 'Karachi, SITE Area'],
        ];

        foreach (['customer' => $customers, 'supplier' => $suppliers] as $kind => $rows) {
            $result = $importer->run($kind, array_map(fn (array $row, int $i): array => [
                'business_name' => $row[0], 'mobile' => sprintf('0300%07d', ($this->profile['seed'] ?? 0) * 10000 + ($kind === 'customer' ? 1000 : 2000) + $i), 'address' => $row[1],
                'ntn_number' => sprintf('%07d-%d', ($kind === 'customer' ? 3000 : 4000) + $i, $i % 10), 'credit_limit' => $kind === 'customer' ? ($this->profile === null ? 500000 : 50000000) : 0, 'pay_term' => $kind === 'customer' ? 15 : 30, 'pay_type' => 'day',
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
     * Several months of purchases, sales, returns and payments, from the profile's volume. Deterministic for a company code (the same
     * company is the same every time) and never past today; a sale only takes what is in stock, so the stock never goes below zero.
     */
    private function tradeGenerated(): void
    {
        mt_srand(crc32($this->code));

        $months = max(1, (int) $this->profile['months']);
        $salesPerMonth = max(1, (int) $this->profile['sales_per_month']);
        $purchasesPerMonth = max(1, (int) $this->profile['purchases_per_month']);
        $keys = array_keys(array_filter($this->products, fn (array $product): bool => $product['buy'] > 0 && $product['sell'] > 0));
        $stock = array_fill_keys($keys, 0);
        $purchases = 0;
        $sales = 0;
        $pick = fn (array $list): mixed => $list[mt_rand(0, count($list) - 1)];

        for ($back = $months - 1; $back >= 0; $back--) {
            $start = $this->today->startOfMonth()->subMonthsNoOverflow($back);
            $lastDay = $back === 0 ? $this->today->day : $start->daysInMonth;
            $date = fn (int $day): string => $start->addDays(min($day, $lastDay) - 1)->toDateString();
            $monthPurchases = [];
            $monthSales = [];

            for ($i = 0; $i < $purchasesPerMonth; $i++) {
                $items = [];

                // a shop buys what it expects to sell: about 30 percent more than the sales of a month (about 12 pieces a sale), and
                // fewer of an expensive item, so purchases and sales are the same order of money
                $perItem = max(10, (int) round(3.25 * $salesPerMonth / $purchasesPerMonth));

                foreach (array_slice($keys, 0, max(3, min(5, count($keys)))) as $unused) {
                    $key = $pick($keys);
                    $scale = min(1.0, 12000 / max(1.0, $this->products[$key]['buy']));
                    $items[$key] = max(4, (int) round($perItem * mt_rand(70, 130) / 100 * $scale));
                }

                $supplier = $this->suppliers[$i % count($this->suppliers)];
                $day = mt_rand(1, max(1, min(12, $lastDay)));
                $id = $this->purchase($supplier, $date($day), $items);
                $monthPurchases[] = [$id, (string) array_key_first($items), (int) reset($items)];
                $purchases++;

                foreach ($items as $key => $quantity) {
                    $stock[$key] += $quantity;
                }

                $roll = mt_rand(1, 100);
                $share = $roll <= 70 ? 1.0 : 0.5;
                $this->pay('/api/purchase-payments', $id, $supplier, round($this->documentTotal($id) * $share, 2), $date($day + 1), $roll % 2 === 0 ? 'cash' : 'bank_transfer');
            }

            for ($i = 0; $i < $salesPerMonth; $i++) {
                $items = [];

                for ($line = mt_rand(1, 4); $line > 0; $line--) {
                    $key = $pick($keys);
                    // dear items are sold a few at a time: a till sale of 600,000 would be no sale a shop makes
                    $quantity = min(mt_rand(1, 12), max(1, intdiv(60000, (int) max(1, $this->products[$key]['sell']))), $stock[$key] - ($items[$key] ?? 0));

                    if ($quantity > 0) {
                        $items[$key] = ($items[$key] ?? 0) + $quantity;
                    }
                }

                if ($items === []) {
                    continue;
                }

                $customer = $this->customers[mt_rand(0, count($this->customers) - 1)];
                $day = mt_rand(min(3, $lastDay), max(1, $lastDay));
                $id = $this->sale($customer, $date($day), $items);
                $monthSales[] = [$id, $customer];
                $sales++;

                foreach ($items as $key => $quantity) {
                    $stock[$key] -= $quantity;
                }

                $roll = mt_rand(1, 100);

                if ($roll <= 75) {
                    $share = $roll <= 55 ? 1.0 : 0.5;
                    $this->pay('/api/sell-payments', $id, $customer, round($this->documentTotal($id) * $share, 2), $date($day + mt_rand(0, 5)), $roll % 3 === 0 ? 'bank_transfer' : 'cash');
                }
            }

            if ($monthSales !== []) {
                [$saleId, $customer] = $monthSales[0];
                $this->saleReturn($saleId, $customer, $date($lastDay));
            }

            if ($monthPurchases !== []) {
                // the return takes up to 3 of the first item back to the supplier: only when that much is still on the shelf
                [$purchaseId, $key, $quantity] = $monthPurchases[0];
                $returned = min(3, $quantity);

                if ($stock[$key] >= $returned) {
                    $this->purchaseReturn($purchaseId, $this->suppliers[0], $date($lastDay));
                    $stock[$key] -= $returned;
                }
            }
        }

        $this->summary['purchases'] = $purchases;
        $this->summary['sales'] = $sales;
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
        $line = DB::table('sell_lines')->where('transaction_id', $saleId)->orderBy('id')->first(['id', 'quantity']);

        $this->call('POST', '/api/sell-returns', [
            'company_id' => $this->companyId, 'branch_id' => $this->branchId, 'contact_id' => $customer, 'transaction_id' => $saleId, 'transaction_date' => $date,
            'selllines' => [['id' => (int) $line->id, 'quantity_returned' => min(2, (float) $line->quantity)]],
        ]);
        $this->summary['sale_returns'] = ($this->summary['sale_returns'] ?? 0) + 1;
    }

    private function purchaseReturn(int $purchaseId, int $supplier, string $date): void
    {
        $line = DB::table('purchase_lines')->where('transaction_id', $purchaseId)->orderBy('id')->first(['id', 'quantity']);

        $this->call('POST', '/api/purchase-returns', [
            'company_id' => $this->companyId, 'branch_id' => $this->branchId, 'contact_id' => $supplier, 'transaction_id' => $purchaseId, 'transaction_date' => $date,
            'purchaselines' => [['id' => (int) $line->id, 'quantity_returned' => min(3, (float) $line->quantity)]],
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
        $periods = $this->profile === null
            ? [$this->month->format('Y-m')]
            : collect(range(1, max(1, (int) $this->profile['months'] - 1)))->map(fn (int $back): string => $this->today->startOfMonth()->subMonthsNoOverflow($back)->format('Y-m'))->reverse()->values()->all();

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
                'join_date' => $this->month->subMonths(6 + (int) ($this->profile['months'] ?? 0))->toDateString(), 'bank_name' => 'Meezan Bank', 'bank_account' => 'PK00MEZN'.str_pad((string) (int) substr($number, 2), 14, '0', STR_PAD_LEFT),
            ]);
            $employee = (int) DB::table('employees')->where('company_id', $this->companyId)->where('employee_no', $number)->value('id');
            $this->call('POST', "/api/hrm/employees/{$employee}/salary", ['effective_from' => $this->month->subMonths(6 + (int) ($this->profile['months'] ?? 0))->toDateString(), 'basic' => $basic]);
        }

        foreach ($periods as $period) {
            $run = (int) ($this->call('POST', '/api/hrm/payroll', ['period' => $period])['data']['id'] ?? 0);
            $this->call('POST', "/api/payroll-runs/{$run}/approve");
            $this->call('POST', "/api/hrm/payroll/{$run}/post");
        }

        $this->summary['employees'] = count($staff);
        $this->summary['payroll_runs'] = count($periods);
    }

    private function produce(): void
    {
        // a showcase company may have sold its stock down: only produce what the shelves can supply
        $needs = ['rice' => 5, 'oil' => 5, 'sugar' => 10, 'carton' => 5];

        foreach ($needs as $key => $quantity) {
            if (StockMovements::baseStock($this->products[$key]['product_id'], $this->products[$key]['variation_id'], $this->branchId) < $quantity) {
                return;
            }
        }

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
