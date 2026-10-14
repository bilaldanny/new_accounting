<?php

use App\Services\StockMovements;
use App\Services\System\ShowcaseDemoCompanies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the showcase builds separate companies with months of real, balanced, tenant isolated trade', function () {
    $real = DB::table('companies')->insertGetId(['code' => 'REAL-9', 'name' => 'A Real Company', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $transactionsBefore = DB::table('transactions')->where('company_id', $real)->count();

    $results = app(ShowcaseDemoCompanies::class)->build(companies: 3, months: 3, salesPerMonth: 6, purchasesPerMonth: 3);

    expect($results)->toHaveCount(3)->and(collect($results)->pluck('code')->all())->toBe(['DEMO-SHOW-1', 'DEMO-SHOW-2', 'DEMO-SHOW-3'])
        ->and(collect($results)->every(fn (array $r): bool => $r['created']))->toBeTrue();

    foreach ($results as $result) {
        $companyId = $result['company_id'];
        $branch = (int) DB::table('branches')->where('company_id', $companyId)->value('id');

        // months of trade, in this company only
        expect((int) DB::table('transactions')->where('company_id', $companyId)->where('type', 'sell')->count())->toBeGreaterThanOrEqual(12)
            ->and((int) DB::table('transactions')->where('company_id', $companyId)->where('type', 'purchaseorder')->count())->toBe(9)
            ->and((int) DB::table('transactions')->where('company_id', $companyId)->where('type', 'sellreturn')->count())->toBe(3)
            ->and(DB::table('transactions')->where('company_id', $companyId)->min('transaction_date'))->toBeLessThan(now()->startOfMonth()->subMonth()->addDays(10)->toDateString())
            ->and(DB::table('transactions')->where('company_id', $companyId)->max('transaction_date'))->toBeLessThanOrEqual(now()->toDateString().' 23:59:59');

        // the books balance, payroll for the full past months is posted, stock never below zero
        $totals = DB::table('t_account_details as d')->join('t_accounts as t', 't.id', '=', 'd.t_account_id')->where('t.company_id', $companyId)
            ->selectRaw('coalesce(sum(d.debit), 0) as debit, coalesce(sum(d.credit), 0) as credit')->first();
        expect(round((float) $totals->debit, 2))->toBe(round((float) $totals->credit, 2))->and((float) $totals->debit)->toBeGreaterThan(0)
            ->and(DB::table('payroll_runs')->where('company_id', $companyId)->where('status', 'posted')->count())->toBe(2);

        foreach (DB::table('product_details')->whereIn('product_id', DB::table('products')->where('company_id', $companyId)->pluck('id'))->get(['product_id', 'id']) as $variation) {
            expect(StockMovements::baseStock((int) $variation->product_id, (int) $variation->id, $branch))->toBeGreaterThanOrEqual(0.0);
        }
    }

    // different businesses, not copies: names and catalogues differ
    expect(DB::table('companies')->whereIn('code', ['DEMO-SHOW-1', 'DEMO-SHOW-2', 'DEMO-SHOW-3'])->distinct()->count('name'))->toBe(3)
        ->and(DB::table('products')->where('company_id', $results[1]['company_id'])->where('name', 'like', '%Samsung%')->exists())->toBeTrue();

    // a real company is untouched
    expect(DB::table('transactions')->where('company_id', $real)->count())->toBe($transactionsBefore)
        ->and(DB::table('companies')->where('id', $real)->value('code'))->toBe('REAL-9');
});

test('running the showcase again changes nothing, and removing touches only showcase companies', function () {
    $real = DB::table('companies')->insertGetId(['code' => 'REAL-9', 'name' => 'A Real Company', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $first = app(ShowcaseDemoCompanies::class)->build(companies: 2, months: 2, salesPerMonth: 4, purchasesPerMonth: 2);
    $count = DB::table('transactions')->count();

    $again = app(ShowcaseDemoCompanies::class)->build(companies: 2, months: 2, salesPerMonth: 4, purchasesPerMonth: 2);

    expect(collect($again)->every(fn (array $r): bool => ! $r['created']))->toBeTrue()->and($again[0]['company_id'])->toBe($first[0]['company_id'])
        ->and(DB::table('transactions')->count())->toBe($count);

    expect(app(ShowcaseDemoCompanies::class)->remove())->toBe(2)
        ->and(DB::table('transactions')->whereIn('company_id', collect($first)->pluck('company_id'))->count())->toBe(0)
        ->and(DB::table('companies')->where('id', $real)->exists())->toBeTrue();

    // and it can be built again after the removal
    expect(app(ShowcaseDemoCompanies::class)->build(companies: 1, months: 2, salesPerMonth: 3, purchasesPerMonth: 2)[0]['created'])->toBeTrue();
});
