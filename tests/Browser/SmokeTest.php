<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Company\CompanyBootstrap;
use App\Services\StockMovements;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * The self smoke test: a brand new company is signed up through the public form and then driven through the whole product with a real
 * browser, one step at a time (docs/smoke-test-report.md lists each step with its result and screenshot in docs/smoke-test/).
 *
 * What a step does: pages and forms (sign-up, sign-in, the till, the dashboard, the offline screen, the sound switch, every list and
 * report) are driven and read in the browser. A document (a purchase, a sale, a payment, a voucher, an employee, a work order) is saved
 * by the browser calling the same endpoint the screen calls, with the session and CSRF token of the signed-in user: the validation,
 * permission, posting and numbering are the real ones, but no form is typed for it. The report says which is which.
 *
 * Run it alone on a fresh sqlite database:  php artisan dusk --filter=SmokeTest  (inline environment, see tests/DuskTestCase.php).
 */
function smokeNetwork(Browser $browser, bool $offline): void
{
    $browser->driver->executeCustomCommand('/session/:sessionId/chromium/network_conditions', 'POST', [
        'network_conditions' => ['offline' => $offline, 'latency' => 0, 'download_throughput' => -1, 'upload_throughput' => -1],
    ]);
}

/** One call to the app's own endpoint from the signed-in page. A refusal stops the step with the server's own words. */
function smokeCall(Browser $browser, string $method, string $url, array $payload = []): array
{
    $result = duskRequest($browser, $method, $url, $payload);

    if ($result['status'] >= 400) {
        $body = $result['body'];
        $message = (string) ($body['errormessage'] ?? $body['message'] ?? json_encode($body));
        throw new RuntimeException("{$method} {$url} answered {$result['status']}: ".mb_substr($message, 0, 300));
    }

    return $result['body'];
}

function smokeShot(Browser $browser, int $number, string $slug): string
{
    $dir = base_path('docs/smoke-test');
    @mkdir($dir, 0777, true);
    $file = sprintf('%02d-%s.png', $number, $slug);
    $browser->driver->takeScreenshot($dir.'/'.$file);

    return 'smoke-test/'.$file;
}

/** @return array<string, int> id of each starting account of the company, by key (cash, bank, rent, ...) */
function smokeAccounts(int $companyId): array
{
    $branch = (int) DB::table('branches')->where('company_id', $companyId)->value('id');

    return app(CompanyBootstrap::class)->accountIds($companyId, $branch);
}

function smokeEmployeeCsv(array $numbers, string $date): string
{
    $csv = "employee_no,date,check_in,check_out\n";

    foreach ($numbers as $number) {
        $csv .= "{$number},{$date},09:05,18:00\n";
    }

    return $csv;
}

test('a new company is signed up and driven through the whole product', function () {
    $this->world();
    Artisan::call('plans:seed-defaults');
    $template = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true]);
    $stamp = substr(md5((string) microtime(true)), 0, 6);
    $email = "smoke.{$stamp}@smoke-test.example";
    $password = 'Smoke!Test7pass';
    $company = null;
    $S = ['steps' => [], 'n' => 0];

    $this->browse(function (Browser $browser) use (&$S, &$company, $email, $password, $stamp) {
        $step = function (string $name, string $how, callable $work) use (&$S, $browser): void {
            $S['n']++;
            $number = $S['n'];
            $result = 'pass';
            $note = '';

            try {
                $note = (string) ($work() ?? '');
            } catch (Throwable $e) {
                $result = 'FAIL';
                $note = get_class($e).': '.mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 400);
            }

            try {
                $shot = smokeShot($browser, $number, preg_replace('/[^a-z0-9]+/', '-', strtolower($name)));
            } catch (Throwable) {
                $shot = '';
            }

            $S['steps'][] = ['n' => $number, 'name' => $name, 'how' => $how, 'result' => $result, 'shot' => $shot, 'note' => $note];
        };

        $cid = function () use (&$company): int {
            return (int) $company->id;
        };
        $branch = function () use (&$company): int {
            return (int) DB::table('branches')->where('company_id', $company->id)->value('id');
        };
        $products = [];
        $ids = [];

        // 1. the public sign-up form
        $step('Sign up a new company', 'browser: public form', function () use ($browser, $email, $password, $stamp, &$company) {
            $browser->logout()->visit('/register')->waitFor('#company_name', 40);
            $browser->type('#company_name', "Smoke Traders {$stamp}")->type('#first_name', 'Smoke')->type('#last_name', 'Owner')->type('#username', "smoke{$stamp}")
                ->type('#email', $email)->type('#phone', '+92 300 1112233')->type('#password', $password)->type('#password_confirmation', $password)
                ->press('Create account')->waitForLocation('/dashboard', 60);
            $browser->assertAuthenticated();
            $company = Company::query()->where('name', "Smoke Traders {$stamp}")->firstOrFail();

            return "company #{$company->id}, ".DB::table('roles')->where('company_id', $company->id)->count().' roles, '.DB::table('chart_of_accounts')->where('company_id', $company->id)->count().' accounts';
        });

        // 2. sign out and sign in again with the new credentials
        $step('Sign out and sign in again', 'browser: login form', function () use ($browser, $email, $password) {
            $browser->logout()->visit('/login')->waitFor('input[type=password]', 40)->pause(3500);
            $browser->driver->executeScript(
                'document.querySelector("input[placeholder=\"name@company.com\"]").value = arguments[0]; document.querySelector("input[type=password]").value = arguments[1]; document.querySelector("button[type=submit]").click();',
                [$email, $password],
            );
            $browser->waitForLocation('/dashboard', 60)->waitUntilMissing('.dash-skel', 60);
            $browser->assertAuthenticated();
        });

        // 3. the setup wizard
        $step('Setup wizard', 'browser: page', function () use ($browser) {
            $browser->visit('/setup')->waitForText('Setup Wizard', 40)->waitForText('steps done', 30)->assertSee('Add your first product');

            return 'wizard opened';
        });

        // 4. master data: a supplier, a customer, two products and the combo
        $step('Create a supplier, a customer and products', 'endpoint calls, then the list pages in the browser', function () use ($browser, $cid, $branch, &$products, &$ids) {
            $ids['barcode'] = '8964111'.random_int(100000, 999999);
            $base = ['company_id' => $cid(), 'branch_id' => $branch()];
            smokeCall($browser, 'POST', '/api/suppliers', $base + ['business_name' => 'Smoke Supplier', 'first_name' => 'Sam', 'mobile' => '03001110001', 'address' => 'Supplier Road', 'ntn_number' => '1000001', 'user_type' => 'supplier', 'type' => 'local']);
            smokeCall($browser, 'POST', '/api/customers', $base + ['business_name' => 'Smoke Customer', 'first_name' => 'Cara', 'mobile' => '03001110002', 'address' => 'Customer Road', 'ntn_number' => '2000002', 'user_type' => 'customer', 'type' => 'local', 'credit_limit' => 1000000]);
            $unit = (int) DB::table('units')->where('company_id', $cid())->value('id');
            $brand = (int) DB::table('brands')->where('company_id', $cid())->value('id');
            $category = (int) DB::table('categories')->where('company_id', $cid())->value('id');
            $itemType = (int) DB::table('item_types')->where('company_id', $cid())->value('id');

            foreach ([['Smoke Rice', 100, 130], ['Smoke Sugar', 60, 80], ['Smoke Combo', 0, 400]] as [$name, $buy, $sell]) {
                smokeCall($browser, 'POST', '/api/products', ['company_id' => $cid(), 'name' => $name, 'unit_id' => $unit, 'brand_id' => $brand, 'category_id' => $category, 'itemtype_id' => $itemType, 'type' => 'single',
                    'sku' => 'SMK-'.strtoupper(substr($name, 6, 3)), 'barcodes' => $name === 'Smoke Rice' ? [['barcode' => $ids['barcode']]] : [], 'productdetail' => [['variation_name' => 'Default', 'default_purchase_price' => $buy, 'profit_percent' => 0, 'default_sell_price' => $sell]]]);
                $product = (int) DB::table('products')->where('company_id', $cid())->where('name', $name)->value('id');
                $products[$name] = ['product_id' => $product, 'variation_id' => (int) DB::table('product_details')->where('product_id', $product)->value('id'), 'unit_id' => $unit, 'buy' => (float) $buy, 'sell' => (float) $sell];
            }

            $browser->visit('/product')->waitForText('Smoke Rice', 40)->assertSee('Smoke Sugar');
            $browser->visit('/customer')->waitForText('Smoke Customer', 40);
            $browser->visit('/supplier')->waitForText('Smoke Supplier', 40);

            return count($products).' products, 1 supplier, 1 customer';
        });

        $supplier = function () use (&$company): int {
            return (int) DB::table('contacts')->where('company_id', $company->id)->where('business_name', 'Smoke Supplier')->value('id');
        };
        $customer = function () use (&$company): int {
            return (int) DB::table('contacts')->where('company_id', $company->id)->where('business_name', 'Smoke Customer')->value('id');
        };

        // 5. purchase and receive
        $step('Purchase and receive stock', 'endpoint calls, then the purchase list in the browser', function () use ($browser, $cid, $branch, $supplier, &$products, &$ids) {
            $lines = [];
            $total = 0;

            foreach (['Smoke Rice' => 50, 'Smoke Sugar' => 100] as $name => $quantity) {
                $p = $products[$name];
                $lines[] = ['product_id' => $p['product_id'], 'variation_id' => $p['variation_id'], 'unit_id' => $p['unit_id'], 'quantity' => $quantity, 'packing_qty' => 1, 'pp_without_discount' => $p['buy'], 'discount_percent' => 0,
                    'purchase_rate' => $p['buy'], 'profit_percent' => 0, 'default_sell_price' => $p['sell']];
                $total += $quantity * $p['buy'];
            }

            smokeCall($browser, 'POST', '/api/purchases', ['company_id' => $cid(), 'branch_id' => $branch(), 'contact_id' => $supplier(), 'transaction_date' => now()->toDateString(), 'pay_term' => 30, 'pay_type' => 'day',
                'discount_type' => 'none', 'discount_amount' => 0, 'shipping_charges' => 0, 'final_amount' => $total, 'total_item' => count($lines), 'is_direct' => false, 'purchaselines' => $lines]);
            $id = (int) DB::table('transactions')->where('company_id', $cid())->where('type', 'purchaseorder')->max('id');
            $ids['purchase'] = $id;

            if (DB::table('transactions')->where('id', $id)->value('status') === 'pending') {
                smokeCall($browser, 'POST', "/api/purchase-approvals/{$id}/approve");
            }

            smokeCall($browser, 'POST', '/api/receiving-notes', ['company_id' => $cid(), 'branch_id' => $branch(), 'contact_id' => $supplier(), 'transaction_id' => $id,
                'purchaselines' => DB::table('purchase_lines')->where('transaction_id', $id)->get(['id', 'quantity'])->map(fn ($l): array => ['id' => (int) $l->id, 'quantity_received' => (float) $l->quantity])->all()]);

            $rice = StockMovements::baseStock($products['Smoke Rice']['product_id'], $products['Smoke Rice']['variation_id'], $branch());
            expect($rice)->toBe(50.0);
            $browser->visit('/purchase')->waitForText(DB::table('transactions')->where('id', $id)->value('invoice_no'), 40);

            return "stock of rice {$rice}, bill ".$total;
        });

        // 6. sale and payment
        $step('Sell to the customer and take a payment', 'endpoint calls, then the sell list in the browser', function () use ($browser, $cid, $branch, $customer, &$products, &$ids) {
            $lines = [];
            $total = 0;

            foreach (['Smoke Rice' => 10, 'Smoke Sugar' => 20] as $name => $quantity) {
                $p = $products[$name];
                $lines[] = ['product_id' => $p['product_id'], 'variation_id' => $p['variation_id'], 'unit_id' => $p['unit_id'], 'quantity' => $quantity, 'packing_qty' => 1, 'unit_price' => $p['sell'], 'discount_percent' => 0,
                    'unit_price_after_discount' => $p['sell'], 'row_subtotal' => $quantity * $p['sell']];
                $total += $quantity * $p['sell'];
            }

            $response = smokeCall($browser, 'POST', '/api/sells', ['company_id' => $cid(), 'branch_id' => $branch(), 'contact_id' => $customer(), 'transaction_date' => now()->toDateString(), 'pay_term' => 15, 'pay_type' => 'day', 'status' => 'final',
                'discount_type' => 'none', 'discount_amount' => 0, 'shipping_charges' => 0, 'final_amount' => $total, 'total_item' => count($lines), 'is_direct' => false, 'selllines' => $lines]);
            $id = (int) ($response['id'] ?? DB::table('transactions')->where('company_id', $cid())->where('type', 'sell')->max('id'));
            $ids['sale'] = $id;

            if (DB::table('transactions')->where('id', $id)->value('status') !== 'approved') {
                smokeCall($browser, 'POST', "/api/sell-approvals/{$id}/approve");
            }

            smokeCall($browser, 'POST', '/api/issue-notes', ['company_id' => $cid(), 'branch_id' => $branch(), 'contact_id' => $customer(), 'transaction_id' => $id,
                'selllines' => DB::table('sell_lines')->where('transaction_id', $id)->get(['id', 'quantity'])->map(fn ($l): array => ['id' => (int) $l->id, 'quantity_issue' => (float) $l->quantity])->all()]);
            $accounts = smokeAccounts($cid());
            smokeCall($browser, 'POST', '/api/sell-payments', ['company_id' => $cid(), 'branch_id' => $branch(), 'contact_id' => $customer(), 'transaction_id' => $id, 'amount' => $total, 'paid_on' => now()->toDateString(), 'method' => 'cash', 'payment_account' => $accounts['cash']]);

            expect(DB::table('transactions')->where('id', $id)->value('payment_status'))->toBe('paid');
            $browser->visit('/sell')->waitForText(DB::table('transactions')->where('id', $id)->value('invoice_no'), 40);

            return "sale {$total} paid in cash";
        });

        // 7. sale return
        $step('Customer returns two bags (sale return)', 'endpoint call, then the stock check', function () use ($browser, $cid, $branch, $customer, &$ids, &$products) {
            $line = DB::table('sell_lines')->where('transaction_id', $ids['sale'])->orderBy('id')->first(['id', 'quantity']);
            $before = StockMovements::baseStock($products['Smoke Rice']['product_id'], $products['Smoke Rice']['variation_id'], $branch());
            smokeCall($browser, 'POST', '/api/sell-returns', ['company_id' => $cid(), 'branch_id' => $branch(), 'contact_id' => $customer(), 'transaction_id' => $ids['sale'], 'transaction_date' => now()->toDateString(),
                'selllines' => [['id' => (int) $line->id, 'quantity_returned' => 2]]]);
            $after = StockMovements::baseStock($products['Smoke Rice']['product_id'], $products['Smoke Rice']['variation_id'], $branch());
            expect($after)->toBe($before + 2);
            $browser->visit('/sell')->waitForText('Sell', 30);

            return "rice stock {$before} -> {$after}";
        });

        // 8. an expense
        $step('Record an expense voucher', 'endpoint call, then the expense list in the browser', function () use ($browser, $cid, $branch) {
            $accounts = smokeAccounts($cid());
            $rent = (int) ($accounts['rent'] ?? 0);
            smokeCall($browser, 'POST', '/api/expenses', ['company_id' => $cid(), 'branch_id' => $branch(), 'voucher_type' => 'EXP', 'voucher_date' => now()->toDateString(), 'comments' => 'Smoke shop rent',
                'taccountdetails' => [['account_id' => $rent, 'description' => 'Rent', 'debit' => 5000, 'credit' => 0], ['account_id' => $accounts['cash'], 'description' => 'Rent', 'debit' => 0, 'credit' => 5000]]]);
            $voucher = (int) DB::table('t_accounts')->where('company_id', $cid())->where('voucher_no', 'like', 'EXP-%')->max('id');

            if (DB::table('t_accounts')->where('id', $voucher)->value('status') !== 'approved') {
                smokeCall($browser, 'POST', "/api/expense-approvals/{$voucher}/approve");
            }

            $browser->visit('/expense')->waitForText('EXP-', 40);

            return DB::table('t_accounts')->where('id', $voucher)->value('voucher_no').' approved';
        });

        // 9. a journal entry (a new company starts with approval on)
        $step('Journal entry and its approval', 'endpoint calls, then the journal list in the browser', function () use ($browser, $cid, $branch) {
            $accounts = smokeAccounts($cid());
            $capital = (int) (DB::table('chart_of_accounts')->where('company_id', $cid())->where('acc_type', 't')->where('acc_nature', 'cr')->where('bs', 1)->where('active', 1)->where('code', 'like', '3%')->value('id')
                ?: DB::table('chart_of_accounts')->where('company_id', $cid())->where('acc_type', 't')->where('acc_nature', 'cr')->where('bs', 1)->where('active', 1)->orderByDesc('code')->value('id'));
            smokeCall($browser, 'POST', '/api/journal-entries', ['company_id' => $cid(), 'branch_id' => $branch(), 'voucher_type' => 'JV', 'voucher_date' => now()->toDateString(), 'comments' => 'Smoke opening capital',
                'taccountdetails' => [['account_id' => $accounts['cash'], 'account_nature' => 'dr', 'description' => 'Cash in', 'debit' => 20000, 'credit' => 0], ['account_id' => $capital, 'account_nature' => 'cr', 'description' => 'Owner capital', 'debit' => 0, 'credit' => 20000]]]);
            $voucher = (int) DB::table('t_accounts')->where('company_id', $cid())->where('voucher_no', 'like', 'JV-%')->max('id');
            $status = DB::table('t_accounts')->where('id', $voucher)->value('status');

            if ($status !== 'approved') {
                smokeCall($browser, 'POST', "/api/journal-entry-approvals/{$voucher}/approve");
            }

            expect(DB::table('t_accounts')->where('id', $voucher)->value('status'))->toBe('approved');
            $browser->visit('/journalentry')->waitForText('JV-', 40);

            return "was {$status}, now approved";
        });

        // 10. bank reconciliation
        $step('Bank statement reconciled', 'endpoint calls, then the page in the browser', function () use ($browser, $cid) {
            $accounts = smokeAccounts($cid());
            $bank = (string) DB::table('chart_of_accounts')->where('id', $accounts['bank'])->value('code');
            $rent = (int) ($accounts['rent'] ?? 0);
            // a payment out of the bank and a deposit into it, then the statement that shows the same two lines
            smokeCall($browser, 'POST', '/api/journal-entries', ['company_id' => (int) DB::table('chart_of_accounts')->where('id', $accounts['bank'])->value('company_id'), 'branch_id' => (int) DB::table('branches')->where('company_id', $cid())->value('id'), 'voucher_type' => 'JV', 'voucher_date' => now()->toDateString(),
                'comments' => 'Smoke bank deposit', 'taccountdetails' => [['account_id' => $accounts['bank'], 'account_nature' => 'dr', 'description' => 'Deposit', 'debit' => 3000, 'credit' => 0], ['account_id' => $accounts['cash'], 'account_nature' => 'cr', 'description' => 'Deposit', 'debit' => 0, 'credit' => 3000]]]);
            $voucher = (int) DB::table('t_accounts')->where('company_id', $cid())->where('voucher_no', 'like', 'JV-%')->max('id');

            if (DB::table('t_accounts')->where('id', $voucher)->value('status') !== 'approved') {
                smokeCall($browser, 'POST', "/api/journal-entry-approvals/{$voucher}/approve");
            }

            $created = smokeCall($browser, 'POST', '/api/bank-reconciliations', ['account_code' => $bank, 'statement_from' => now()->startOfMonth()->toDateString(), 'statement_to' => now()->toDateString(), 'opening_balance' => 0, 'closing_balance' => 3000, 'note' => 'Smoke statement',
                'rows' => [['txn_date' => now()->toDateString(), 'amount' => 3000, 'description' => 'Deposit', 'reference' => 'DEP-1']]]);
            $statement = (int) ($created['id'] ?? DB::table('bank_statements')->where('company_id', $cid())->max('id'));
            smokeCall($browser, 'POST', "/api/bank-reconciliations/{$statement}/auto-match");
            smokeCall($browser, 'POST', "/api/bank-reconciliations/{$statement}/reconcile");
            expect(DB::table('bank_statements')->where('id', $statement)->value('status'))->toBe('reconciled');
            $browser->visit('/bankreconciliation')->waitForText('Statements', 40)->waitForText('reconciled', 30);

            return 'statement '.$statement.' reconciled';
        });

        // 11. the books
        $step('Trial balance, profit and loss and balance sheet agree', 'endpoint calls and the report pages in the browser', function () use ($browser, $cid) {
            $totals = DB::table('t_account_details as d')->join('t_accounts as t', 't.id', '=', 'd.t_account_id')->where('t.company_id', $cid())->where('t.status', 'approved')
                ->selectRaw('round(coalesce(sum(d.debit),0),2) as debit, round(coalesce(sum(d.credit),0),2) as credit')->first();
            expect((float) $totals->debit)->toBe((float) $totals->credit)->and((float) $totals->debit)->toBeGreaterThan(0.0);
            $date = ['company_id' => $cid(), 'from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString(), 'as_of' => now()->toDateString(), 'date' => now()->toDateString()];
            $tb = duskRequest($browser, 'GET', '/api/reports/trial-balance?'.http_build_query($date));
            $pl = duskRequest($browser, 'GET', '/api/reports/profit-loss?'.http_build_query($date));
            $bs = duskRequest($browser, 'GET', '/api/reports/balance-sheet?'.http_build_query($date));
            expect($tb['status'])->toBe(200)->and($pl['status'])->toBe(200)->and($bs['status'])->toBe(200);
            $difference = $bs['body']['summary']['difference'] ?? $bs['body']['data']['summary']['difference'] ?? null;
            expect((float) ($difference ?? 0))->toBe(0.0);
            $browser->visit('/report/trial-balance')->waitForText('Trial Balance', 40);
            $browser->visit('/report/profit-loss')->waitForText('Profit', 40);
            $browser->visit('/report/balance-sheet')->waitForText('Balance Sheet', 40);

            return "ledger debit = credit = {$totals->debit}; balance sheet difference ".($difference ?? 'n/a');
        });

        // 12. the stock report
        $step('Stock report', 'browser: report page', function () use ($browser) {
            $browser->visit('/report/stock')->waitForText('Smoke Rice', 40)->assertSee('Smoke Sugar');
        });

        // 13. HR: employee, attendance, leave, payroll
        $step('Employee, attendance, leave and a payroll month', 'endpoint calls, then the HR page in the browser', function () use ($browser, $cid) {
            $accounts = smokeAccounts($cid());
            $payroll = array_filter(['salary_expense' => $accounts['salaries_expense'] ?? null, 'salary_payable' => $accounts['salaries_payable'] ?? null, 'tax_payable' => $accounts['income_tax_payable'] ?? null,
                'statutory_payable' => $accounts['statutory_payable'] ?? null, 'loan_receivable' => $accounts['staff_loans'] ?? null]);
            smokeCall($browser, 'PUT', '/api/hrm/settings', ['posting_enabled' => true, 'accounts' => $payroll]);
            $join = now()->subMonths(3)->toDateString();

            foreach ([['E-901', 'Imran', 'Khan', 55000], ['E-902', 'Ayesha', 'Noor', 42000]] as [$number, $first, $last, $basic]) {
                smokeCall($browser, 'POST', '/api/hrm/employees', ['employee_no' => $number, 'first_name' => $first, 'last_name' => $last, 'designation' => 'Staff', 'employment_type' => 'permanent', 'join_date' => $join]);
                $employee = (int) DB::table('employees')->where('company_id', $cid())->where('employee_no', $number)->value('id');
                smokeCall($browser, 'POST', "/api/hrm/employees/{$employee}/salary", ['effective_from' => $join, 'basic' => $basic]);
            }

            $period = now()->subMonth()->format('Y-m');
            $date = now()->subMonth()->startOfMonth()->addDays(2)->toDateString();
            smokeCall($browser, 'POST', '/api/hrm/attendance/import', ['csv' => smokeEmployeeCsv(['E-901', 'E-902'], $date)]);
            $type = (int) (DB::table('leave_types')->where('company_id', $cid())->value('id') ?: (smokeCall($browser, 'POST', '/api/hrm/leave-types', ['name' => 'Annual Leave', 'days_per_year' => 14, 'is_paid' => true])['data']['id'] ?? 0));
            $leave = (int) (smokeCall($browser, 'POST', '/api/hrm/leave-requests', ['employee_id' => (int) DB::table('employees')->where('company_id', $cid())->value('id'), 'leave_type_id' => $type,
                'from_date' => now()->addDays(3)->toDateString(), 'to_date' => now()->addDays(4)->toDateString(), 'reason' => 'Smoke leave'])['data']['id'] ?? 0);
            smokeCall($browser, 'POST', "/api/leave-requests/{$leave}/approve");
            $run = (int) (smokeCall($browser, 'POST', '/api/hrm/payroll', ['period' => $period])['data']['id'] ?? 0);
            smokeCall($browser, 'POST', "/api/payroll-runs/{$run}/approve");
            smokeCall($browser, 'POST', "/api/hrm/payroll/{$run}/post");
            $browser->visit('/hrm')->waitForText('E-901', 40);
            $browser->visit('/hrm/payroll')->waitForText($period, 40);

            return "payroll {$period} posted";
        });

        // 14. manufacturing
        $step('Manufacturing order', 'endpoint calls, then the page in the browser', function () use ($browser, $cid, $branch, &$products) {
            $combo = $products['Smoke Combo'];
            $bom = (int) (smokeCall($browser, 'POST', '/api/manufacturing/boms', ['company_id' => $cid(), 'branch_id' => $branch(), 'product_id' => $combo['product_id'], 'name' => 'Smoke Combo', 'output_qty' => 1, 'items' => [
                ['component_product_id' => $products['Smoke Rice']['product_id'], 'quantity' => 1], ['component_product_id' => $products['Smoke Sugar']['product_id'], 'quantity' => 2],
            ], 'operations' => [['name' => 'Packing', 'minutes' => 10, 'labor_rate_per_hour' => 300, 'overhead_rate_per_hour' => 60]]])['data']['id'] ?? 0);
            smokeCall($browser, 'POST', "/api/boms/{$bom}/approve");
            $order = (int) (smokeCall($browser, 'POST', '/api/manufacturing/work-orders', ['company_id' => $cid(), 'branch_id' => $branch(), 'product_id' => $combo['product_id'], 'planned_qty' => 5, 'planned_start' => now()->toDateString()])['data']['id'] ?? 0);
            smokeCall($browser, 'POST', "/api/work-orders/{$order}/approve");
            $materials = DB::table('work_order_materials')->where('work_order_id', $order)->get();
            smokeCall($browser, 'POST', "/api/manufacturing/work-orders/{$order}/issue", ['date' => now()->toDateString(), 'lines' => $materials->map(fn ($m): array => ['product_id' => (int) $m->product_id, 'variation_id' => (int) $m->variation_id, 'quantity' => (float) $m->required_qty])->all()]);
            smokeCall($browser, 'POST', "/api/manufacturing/work-orders/{$order}/standard-labor");
            smokeCall($browser, 'POST', "/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 5, 'date' => now()->toDateString()]);
            expect(StockMovements::baseStock($combo['product_id'], $combo['variation_id'], $branch()))->toBe(5.0);
            $browser->visit('/manufacturing')->waitForText('Smoke Combo', 40);

            return 'produced 5 combos';
        });

        // 15. a custom invoice written as template code, printed
        $step('Custom invoice from template code, printed', 'endpoint calls, then the printed page in the browser', function () use ($browser, $cid, &$ids) {
            smokeCall($browser, 'POST', '/api/document-templates', ['company_id' => $cid(), 'name' => 'Smoke coded invoice', 'doc_type' => 'invoice', 'language' => 'en', 'paper' => 'a4', 'mode' => 'code', 'is_default' => true,
                'code_html' => '<h1>{{company.name}}</h1><p>Smoke invoice {{invoice.number}} for {{customer.name}}</p>{{#lines}}<i>{{name}} x {{qty}}</i><br>{{/lines}}<b>Total {{totals.grand}}</b>', 'code_css' => 'h1{color:#0f766e}']);
            $browser->visit('/documentcenter')->waitForText('Smoke coded invoice', 40);
            $html = (string) $browser->driver->executeAsyncScript('const done = arguments[arguments.length - 1]; fetch(arguments[0], {credentials: "same-origin", headers: {"Accept": "text/html", "X-Requested-With": "XMLHttpRequest"}}).then((r) => r.text()).then(done);', ['/api/documents/'.$ids['sale'].'/render']);
            expect($html)->toContain('Smoke invoice')->toContain('Smoke Customer')->toContain('Smoke Rice')->not->toContain('<script');
            $browser->driver->executeScript('document.open(); document.write(arguments[0]); document.close();', [$html]);
            $browser->waitForText('Smoke invoice', 20)->assertSee('Smoke Customer');
        });

        // 16. the till: a sale with the keyboard alone, and a scanned code
        $step('Till: scanned code and a keyboard-only sale', 'browser: POS', function () use ($browser, $cid, &$ids) {
            $code = $ids['barcode'];
            $before = DB::table('transactions')->where('company_id', $cid())->where('type', 'sell')->count();
            $browser->visit('/sell/pos/add')->waitFor('[data-test="pos-search"]', 40);
            $browser->waitUsing(10, 1, fn (): bool => (bool) $browser->driver->executeScript('return document.activeElement && document.activeElement.getAttribute("data-test") === "pos-search";'));
            $browser->type('[data-test="pos-search"]', $code)->keys('[data-test="pos-search"]', '{enter}');
            $browser->waitForTextIn('[data-test="pos-scan-status"]', 'added', 20)->assertPresent('.pos-cart-line');
            $browser->driver->executeScript('document.activeElement.blur();');
            $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::F3);
            usleep(300000);
            $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ARROW_DOWN);
            usleep(300000);
            $browser->driver->executeScript('document.activeElement.blur();');
            $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::F10);
            $browser->waitUsing(40, 1, fn (): bool => (int) $browser->driver->executeScript('return document.querySelectorAll(".pos-cart-line").length;') === 0);
            expect(DB::table('transactions')->where('company_id', $cid())->where('type', 'sell')->count())->toBe($before + 1);

            return 'one scanned line sold as cash';
        });

        // 17. the dashboard graphs
        $step('Dashboard graphs', 'browser: dashboard', function () use ($browser) {
            $trends = duskRequest($browser, 'GET', '/api/dashboard/trends?refresh=1');
            $diagnosis = 'trends answered '.$trends['status'].': '.mb_substr(json_encode($trends['body']), 0, 300);
            $browser->visit('/dashboard')->waitFor('[data-test="dashboard-glance"]', 40)->waitFor('[data-test="dashboard-charts"]', 30);
            // the graphs of a company are kept for a few minutes (this one was opened before its first sale): Refresh draws the new figures
            $browser->press('Refresh')->pause(1500);
            try {
                $browser->waitUsing(30, 1, fn (): bool => (int) $browser->driver->executeScript('return document.querySelectorAll("[data-test=dashboard-charts] svg rect").length;') > 0);
            } catch (Throwable $e) {
                throw new RuntimeException('no bars were drawn; '.$diagnosis, 0, $e);
            }

            $browser->script('document.querySelector("[data-test=dashboard-charts]").scrollIntoView({block: "start"});');
            $browser->pause(400);
        });

        // 18. the notification sound switch
        $step('Notification sound switch', 'browser: header', function () use ($browser, $email) {
            $browser->visit('/dashboard')->waitFor('[data-test="sound-toggle"]', 40);
            $on = $browser->attribute('[data-test="sound-toggle"]', 'aria-pressed');
            $browser->click('[data-test="sound-toggle"]');
            $browser->waitUsing(10, 1, fn (): bool => (string) $browser->attribute('[data-test="sound-toggle"]', 'aria-pressed') !== (string) $on);
            $saved = (bool) DB::table('users')->where('email', $email)->value('notification_sound');
            $browser->refresh()->waitFor('[data-test="sound-toggle"]', 40);
            expect((string) $browser->attribute('[data-test="sound-toggle"]', 'aria-pressed'))->toBe($saved ? 'true' : 'false');
            $browser->click('[data-test="sound-toggle"]');

            return "was {$on}, saved ".($saved ? 'on' : 'off').' and still so after a reload';
        });

        // 19. offline: a sale and an expense with the network off, sent when it is back
        $step('Offline: sale and expense entered with no network, sent once', 'browser: Offline entries page, network switched off', function () use ($browser, $cid, &$products) {
            $accounts = smokeAccounts($cid());
            $sales = DB::table('transactions')->where('company_id', $cid())->where('type', 'sell')->count();
            $browser->visit('/offline')->waitFor('[data-test="offline-form"]', 40);
            $reference = duskRequest($browser, 'GET', '/api/offline/reference');
            $referenceNote = 'reference answered '.$reference['status'].' with '.count($reference['body']['data']['contacts'] ?? []).' contacts, '.count($reference['body']['data']['payment_accounts'] ?? []).' payment accounts, '.count($reference['body']['data']['products'] ?? []).' products';
            $browser->waitUsing(40, 1, fn (): bool => ! str_contains((string) $browser->text('[data-test="offline-reference-age"]'), 'not loaded'));
            $browser->driver->executeAsyncScript('const done = arguments[arguments.length - 1]; navigator.serviceWorker.ready.then(() => done(true));');
            $browser->waitUsing(40, 1, fn (): bool => (bool) $browser->driver->executeAsyncScript('const done = arguments[arguments.length - 1]; caches.keys().then(async (keys) => { let shell = false; let assets = 0; for (const k of keys) { for (const r of await (await caches.open(k)).keys()) { if (new URL(r.url).pathname === "/offline") { shell = true; } else { assets++; } } } done(shell && assets > 3); });'));
            smokeNetwork($browser, true);

            try {
                $browser->refresh()->waitFor('[data-test="offline-form"]', 40);
                $options = (string) $browser->driver->executeScript('return JSON.stringify({contact: Array.from((document.querySelector("#offline-contact") || {options: []}).options).map((o) => o.value + ":" + o.text), account: Array.from((document.querySelector("#offline-account") || {options: []}).options).map((o) => o.value + ":" + o.text)});');
                $browser->select('#offline-kind', 'sale')->select('#offline-contact', (string) DB::table('contacts')->where('company_id', $cid())->where('business_name', 'Smoke Customer')->value('id'))
                    ->select('select[aria-label="Product 1"]', (string) $products['Smoke Sugar']['variation_id'])->check('#offline-paynow')->select('#offline-account', (string) $accounts['cash']);
                $browser->click('[data-test="offline-save"]');

                try {
                    $browser->waitUsing(15, 1, fn (): bool => str_starts_with(trim((string) $browser->text('[data-test="offline-pending-count"]')), '1 waiting'));
                } catch (Throwable $e) {
                    throw new RuntimeException('the sale was not queued; form said: '.trim((string) $browser->text('[data-test="offline-failure"]')).'; lists: '.mb_substr($options, 0, 300).'; '.$referenceNote, 0, $e);
                }

                $rent = (int) ($accounts['rent'] ?? 0);
                $browser->select('#offline-kind', 'expense')->select('#offline-expense-account', (string) $rent)->type('#offline-expense-amount', '250')->type('#offline-description', 'Smoke offline tea')->select('#offline-account', (string) $accounts['cash']);
                $browser->click('[data-test="offline-save"]');
                $browser->waitUsing(15, 1, fn (): bool => str_starts_with(trim((string) $browser->text('[data-test="offline-pending-count"]')), '2 waiting'));
                expect(DB::table('transactions')->where('company_id', $cid())->where('type', 'sell')->count())->toBe($sales);
                $browser->pause(300)->driver->takeScreenshot(base_path('docs/smoke-test/_offline-queued.png'));
            } finally {
                smokeNetwork($browser, false);
            }

            $browser->waitUsing(90, 1, fn (): bool => str_starts_with(trim((string) $browser->text('[data-test="offline-pending-count"]')), '0 waiting') && DB::table('offline_sync_entries')->where('company_id', $cid())->where('status', 'synced')->count() === 2);
            expect(DB::table('transactions')->where('company_id', $cid())->where('type', 'sell')->count())->toBe($sales + 1);

            return '2 entries queued offline, both posted exactly once';
        });

        // 20. the demo companies
        $step('Demo companies build (pilot and history)', 'commands', function () {
            $code = Artisan::call('demo:pilot', ['--fresh' => true]);
            expect($code)->toBe(0)->and(Company::query()->where('code', 'DEMO-PILOT')->exists())->toBeTrue();
            $transactions = DB::table('transactions')->where('company_id', Company::query()->where('code', 'DEMO-PILOT')->value('id'))->count();
            $history = Artisan::call('demo:history');
            expect($history)->toBe(0);

            return "pilot company built with {$transactions} documents; demo:history exit {$history}";
        });

        // 21. a limited role: create a user, sign in as them, see only what the role allows
        $step('Create a role and a user; the limited user is kept to their pages', 'endpoint calls, then the browser as the new user', function () use ($browser, $cid, $branch, $stamp, $password) {
            smokeCall($browser, 'POST', '/api/roles', ['name' => 'Smoke Role', 'company_id' => $cid(), 'branch_id' => $branch()]);
            expect(DB::table('roles')->where('company_id', $cid())->where('name', 'Smoke Role')->exists())->toBeTrue();
            $cashier = (int) DB::table('roles')->where('company_id', $cid())->where('name', 'Cashier')->value('id');
            $department = (int) (DB::table('departments')->where('company_id', $cid())->value('id') ?: DB::table('departments')->insertGetId(['company_id' => $cid(), 'name' => 'Smoke Dept', 'created_at' => now(), 'updated_at' => now()]));
            $login = "cashier.{$stamp}@smoke-test.example";
            smokeCall($browser, 'POST', '/api/users', ['role_id' => $cashier, 'department_id' => $department, 'first_name' => 'Cash', 'last_name' => 'Ier', 'email' => $login, 'username' => "cashier{$stamp}", 'password' => $password,
                'password_confirmation' => $password, 'company_id' => $cid(), 'branch_id' => $branch()]);
            $user = User::query()->where('email', $login)->firstOrFail();
            $browser->logout()->visit('/login')->waitFor('input[type=password]', 40)->pause(3500);
            $browser->driver->executeScript('document.querySelector("input[placeholder=\"name@company.com\"]").value = arguments[0]; document.querySelector("input[type=password]").value = arguments[1]; document.querySelector("button[type=submit]").click();', [$login, $password]);
            $browser->pause(4000);
            $browser->assertAuthenticatedAs($user);
            $browser->visit('/sell/pos/add')->waitFor('[data-test="pos-search"]', 40);
            $allowed = true;
            $browser->visit('/role')->pause(1500);
            $roleBody = (string) $browser->driver->getPageSource();
            $denied = str_contains($roleBody, '403') || str_contains(strtolower($roleBody), 'not authorized') || str_contains(strtolower($roleBody), 'forbidden') || ! str_contains($browser->driver->getCurrentURL(), '/role');
            expect($allowed && $denied)->toBeTrue();
            $browser->logout();

            return "cashier #{$user->id} reaches the till and is refused the role page";
        });
    });

    // the report
    $steps = collect($S['steps']);
    $failed = $steps->where('result', 'FAIL');
    $lines = ['# Smoke test report', '', 'Made by `tests/Browser/SmokeTest.php` on '.now()->toDateTimeString().' (sqlite, `php artisan dusk --filter=SmokeTest`). A brand new company is signed up through the public form and driven through the product; screenshots are in `docs/smoke-test/`.', '',
        "**Result: {$steps->where('result', 'pass')->count()} of {$steps->count()} steps passed, {$failed->count()} failed.**", '',
        'How to read "driven by": *browser* = typed or clicked in the browser; *endpoint calls* = the browser called the same endpoint the screen calls (real session, CSRF, validation, permission, posting), no form was typed; *commands* = an artisan command.', '',
        '| # | Step | Driven by | Result | Screenshot | Notes |', '|---|---|---|---|---|---|'];

    foreach ($steps as $s) {
        $lines[] = "| {$s['n']} | {$s['name']} | {$s['how']} | {$s['result']} | ".($s['shot'] !== '' ? "[{$s['shot']}]({$s['shot']})" : '-').' | '.str_replace('|', '/', $s['note']).' |';
    }

    $lines[] = '';
    foreach (['## What the smoke test found and what was done about it', '', 'Bugs of the product (fixed, with tests):', '', '* **Offline mode gave a company-level user (no branch of their own) an empty reference list** (no customers, no payment accounts) and would have refused their entries as "must belong to a branch": the `User` model answers an empty string for "no branch", which the sync code took for branch 0. Now a user without a branch sees the whole company and posts into the company\'s first branch (`tests/Feature/OfflineSyncTest.php`, last test).', '* **The offline entries page showed a copy of the reference data up to 30 minutes old** (a customer or product added a minute ago was missing from the lists). Opening the page while online now always takes a fresh copy; the background copy is refreshed after 10 minutes.', '', 'Not bugs, but worth knowing (what the test had to allow for):', '', '* The dashboard graphs of a company are kept for five minutes (the *Refresh* button recalculates); a company that opens the dashboard before its first sale sees empty graphs until then. The step calls the same recalculation the button does.', '* A new company starts with journal approval on, so the journal entry of step 9 needed its approval.', '* The bank reconciliation list shows the account, period, closing balance, matched count and status, not the note typed on the statement.'] as $found) {
        $lines[] = $found;
    }

    $lines[] = '';
    $lines[] = '## Not run here';
    $lines[] = '';
    $lines[] = 'The migration rehearsal on a copy of the old schema needs a copy of the live backup, so it was only **prepared**: the commands are in `docs/fresh-database-proof.md` (section "Old-schema (staging) proof") and must be run by the owner.';
    file_put_contents(base_path('docs/smoke-test-report.md'), implode("\n", $lines)."\n");

    expect($failed->pluck('name')->all())->toBe([]);
});
