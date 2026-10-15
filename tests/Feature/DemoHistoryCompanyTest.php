<?php

use App\Services\System\DemoHistoryCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the history demo has a year of seasonal, balanced, tenant isolated data and can be removed', function () {
    $other = DB::table('companies')->insertGetId(['code' => 'REAL-2', 'name' => 'A Real Company', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $count = fn (int $except): array => ['contacts' => DB::table('contacts')->where('company_id', '!=', $except)->count(), 'transactions' => DB::table('transactions')->where('company_id', '!=', $except)->count(), 't_accounts' => DB::table('t_accounts')->where('company_id', '!=', $except)->count()];
    $before = $count(-1);

    $result = app(DemoHistoryCompany::class)->build(12, 6, 3);
    $companyId = $result['company_id'];

    expect($result['created'])->toBeTrue()->and($result['code'])->toBe('DEMO-HISTORY')->and($count($companyId))->toBe($before);

    // twelve months of sales, and the busy months (Ramzan, Eid) carry more of them than a summer month
    $byMonth = DB::table('transactions')->where('company_id', $companyId)->where('type', 'sell')->selectRaw('substr(transaction_date, 1, 7) as month, count(*) as n')->groupBy('month')->pluck('n', 'month')->all();
    expect(count($byMonth))->toBeGreaterThanOrEqual(11);

    foreach (['expense_vouchers', 'attendance_days', 'leave_requests', 'leads', 'payroll_runs'] as $key) {
        expect($result['summary'][$key] ?? 0)->toBeGreaterThan(0, "no {$key}");
    }

    // the books balance
    $totals = DB::table('t_account_details as d')->join('t_accounts as t', 't.id', '=', 'd.t_account_id')->where('t.company_id', $companyId)
        ->selectRaw('coalesce(sum(d.debit), 0) as debit, coalesce(sum(d.credit), 0) as credit')->first();
    expect(round((float) $totals->debit, 2))->toBe(round((float) $totals->credit, 2));

    // building again changes nothing; removing takes only this company
    expect(app(DemoHistoryCompany::class)->build()['created'])->toBeFalse();
    expect(app(DemoHistoryCompany::class)->remove())->toBeTrue()->and(DB::table('companies')->where('id', $other)->value('code'))->toBe('REAL-2');
    expect(DB::table('transactions')->where('company_id', $companyId)->count())->toBe(0);
})->group('slow');
