<?php

use App\Services\StockMovements;
use App\Services\System\DemoCompany;
use App\Services\System\PilotDemoCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Row counts of the tables a demo build must never change for anybody else.
 *
 * @return array<string, int>
 */
function pilotRowCountsOutside(int $companyId): array
{
    return [
        'contacts' => DB::table('contacts')->where('company_id', '!=', $companyId)->count(),
        'transactions' => DB::table('transactions')->where('company_id', '!=', $companyId)->count(),
        't_accounts' => DB::table('t_accounts')->where('company_id', '!=', $companyId)->count(),
        'products' => DB::table('products')->where('company_id', '!=', $companyId)->count(),
        'employees' => DB::table('employees')->where('company_id', '!=', $companyId)->count(),
    ];
}

test('the pilot demo is its own company with real, balanced, tenant isolated data', function () {
    $other = DB::table('companies')->insertGetId(['code' => 'REAL-1', 'name' => 'A Real Company', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $before = pilotRowCountsOutside(-1);

    $result = app(PilotDemoCompany::class)->build();
    $companyId = $result['company_id'];

    expect($result['created'])->toBeTrue()->and($result['code'])->toBe('DEMO-PILOT')->and($companyId)->not->toBe($other);
    expect(DB::table('companies')->where('id', $other)->value('code'))->toBe('REAL-1');

    // nothing of any other company was touched
    expect(pilotRowCountsOutside($companyId))->toBe($before);

    // every row it made carries the demo company
    expect(DB::table('contacts')->where('company_id', $companyId)->count())->toBe(10)
        ->and(DB::table('transactions')->where('company_id', $companyId)->where('type', 'sell')->count())->toBe(8)
        ->and(DB::table('transactions')->where('company_id', $companyId)->where('type', 'purchaseorder')->count())->toBe(5)
        ->and(DB::table('transactions')->where('company_id', $companyId)->where('type', 'sellreturn')->count())->toBe(1)
        ->and(DB::table('transactions')->where('company_id', $companyId)->where('type', 'purchasereturn')->count())->toBe(1)
        ->and(DB::table('payments')->whereIn('transaction_id', DB::table('transactions')->where('company_id', $companyId)->pluck('id'))->count())->toBeGreaterThan(5);

    // the books balance
    $totals = DB::table('t_account_details as d')->join('t_accounts as t', 't.id', '=', 'd.t_account_id')->where('t.company_id', $companyId)
        ->selectRaw('coalesce(sum(d.debit), 0) as debit, coalesce(sum(d.credit), 0) as credit')->first();
    expect((float) $totals->debit)->toBeGreaterThan(0)->and(round((float) $totals->debit, 2))->toBe(round((float) $totals->credit, 2));

    // a payroll month was posted to the ledger, with no statutory rate or slab invented
    $run = DB::table('payroll_runs')->where('company_id', $companyId)->first();
    expect($run->status)->toBe('posted')->and($run->voucher_id)->not->toBeNull()
        ->and(DB::table('statutory_rates')->where('company_id', $companyId)->count())->toBe(0)
        ->and(DB::table('tax_slabs')->where('company_id', $companyId)->count())->toBe(0);

    // the production order received its finished goods and no product is below zero stock
    $order = DB::table('work_orders')->where('company_id', $companyId)->first();
    expect((float) $order->produced_qty)->toBe(5.0);

    $branch = (int) DB::table('branches')->where('company_id', $companyId)->value('id');

    foreach (DB::table('product_details')->whereIn('product_id', DB::table('products')->where('company_id', $companyId)->pluck('id'))->get(['product_id', 'id']) as $variation) {
        expect(StockMovements::baseStock((int) $variation->product_id, (int) $variation->id, $branch))->toBeGreaterThanOrEqual(0.0);
    }
});

test('running the pilot demo again finds the company and changes nothing', function () {
    $first = app(PilotDemoCompany::class)->build();
    $counts = [DB::table('transactions')->count(), DB::table('contacts')->count(), DB::table('companies')->count(), DB::table('t_accounts')->count()];

    $second = app(PilotDemoCompany::class)->build();

    expect($second['created'])->toBeFalse()->and($second['company_id'])->toBe($first['company_id'])->and($second['password'])->toBeNull();
    expect([DB::table('transactions')->count(), DB::table('contacts')->count(), DB::table('companies')->count(), DB::table('t_accounts')->count()])->toBe($counts);
});

test('the command refuses production without force, and removing only ever touches the demo company', function () {
    $real = DB::table('companies')->insertGetId(['code' => 'ACME', 'name' => 'Real', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    expect(app(DemoCompany::class)->remove($real))->toBeFalse()->and(DB::table('companies')->where('id', $real)->exists())->toBeTrue();

    app()->detectEnvironment(fn () => 'production');
    $this->artisan('demo:pilot')->assertFailed();
    app()->detectEnvironment(fn () => 'testing');
    expect(DB::table('companies')->where('code', 'DEMO-PILOT')->exists())->toBeFalse();

    expect(app(PilotDemoCompany::class)->remove())->toBeFalse();
    app(PilotDemoCompany::class)->build();
    expect(app(PilotDemoCompany::class)->remove())->toBeTrue()->and(DB::table('companies')->where('code', 'DEMO-PILOT')->exists())->toBeFalse()
        ->and(DB::table('companies')->where('id', $real)->exists())->toBeTrue();
});

test('a build with no superadmin stops before it creates anything', function () {
    DB::table('users')->where('role_id', 1)->update(['role_id' => 2]);

    expect(fn () => app(PilotDemoCompany::class)->build())->toThrow(RuntimeException::class);
    expect(DB::table('companies')->where('code', 'DEMO-PILOT')->exists())->toBeFalse();
});
