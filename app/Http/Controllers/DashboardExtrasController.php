<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Models\Transaction;
use App\Services\Reports\PurchaseSaleSummary;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * More dashboard cards, one endpoint: today against yesterday, how the month's receipts split by payment method, the branches
 * compared, and the HR picture (headcount, today's attendance, leave waiting). Same rules as the other dashboard cards: the company
 * and branch of the user, a part is only sent when the user may open the page it comes from, and the figures are kept for five
 * minutes per company and branch.
 */
class DashboardExtrasController extends Controller
{
    use ResolvesReportScope;

    private const CACHE_SECONDS = 300;

    public function show(Request $request): JsonResponse
    {
        [$companyId, $branchId] = $this->reportScope($request);

        if ($companyId === null) {
            return response()->json(['data' => [], 'needs_company' => true]);
        }

        $refresh = $request->boolean('refresh');
        $data = [];

        if (hasMenuPermission('/sell')) {
            $data['today_vs_yesterday'] = $this->cached('tvy', $companyId, $branchId, $refresh, fn (): array => $this->todayVsYesterday($companyId, $branchId));
            $data['payment_methods'] = $this->cached('pay', $companyId, $branchId, $refresh, fn (): array => $this->paymentMethods($companyId, $branchId));

            if ($branchId === null) {
                $data['branches'] = $this->cached('br', $companyId, $branchId, $refresh, fn (): array => $this->branches($companyId));
            }
        }

        if (hasMenuPermission('/hrm') && Schema::hasTable('employees')) {
            $data['hrm'] = $this->cached('hrm', $companyId, $branchId, $refresh, fn (): array => $this->hrm($companyId, $branchId));
        }

        return response()->json(['data' => $data]);
    }

    /**
     * @param  callable(): array<string, mixed>  $compute
     * @return array<string, mixed>
     */
    private function cached(string $part, int $companyId, ?int $branchId, bool $refresh, callable $compute): array
    {
        $key = "dashboard_extras:{$part}:{$companyId}:".($branchId ?? 'all').':'.now()->toDateString();

        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_SECONDS, $compute);
    }

    /**
     * @return array{today: array<string, float>, yesterday: array<string, float>}
     */
    private function todayVsYesterday(int $companyId, ?int $branchId): array
    {
        $day = function (CarbonInterface $date) use ($companyId, $branchId): array {
            $totals = app(PurchaseSaleSummary::class)->build($companyId, $branchId, ['start_date' => $date->toDateString(), 'end_date' => $date->toDateString()])['totals'];
            $received = (float) DB::table('payments as p')->join('transactions as t', 't.id', '=', 'p.transaction_id')->where('p.company_id', $companyId)
                ->where('t.type', Transaction::TYPE_SELL)->where('p.is_return', 0)->whereDate('p.paid_on', $date->toDateString())
                ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))->sum('p.amount');
            $invoices = (int) DB::table('transactions')->where('company_id', $companyId)->where('type', Transaction::TYPE_SELL)->whereNull('deleted_at')
                ->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)->whereDate('transaction_date', $date->toDateString())
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->count();

            return ['sales' => round((float) $totals['net_sales'], 2), 'purchases' => round((float) $totals['net_purchases'], 2), 'received' => round($received, 2), 'invoices' => (float) $invoices];
        };

        return ['today' => $day(now()), 'yesterday' => $day(now()->subDay())];
    }

    /**
     * @return list<array{method: string, amount: float}>
     */
    private function paymentMethods(int $companyId, ?int $branchId): array
    {
        return DB::table('payments as p')->join('transactions as t', 't.id', '=', 'p.transaction_id')->where('p.company_id', $companyId)
            ->where('t.type', Transaction::TYPE_SELL)->where('p.is_return', 0)->whereDate('p.paid_on', '>=', now()->startOfMonth()->toDateString())
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->groupBy('p.method')->selectRaw('p.method as method, sum(p.amount) as amount')->orderByDesc('amount')->get()
            ->map(fn (object $row): array => ['method' => (string) $row->method, 'amount' => round((float) $row->amount, 2)])->all();
    }

    /**
     * @return list<array{name: string, sales: float}>
     */
    private function branches(int $companyId): array
    {
        return DB::table('transactions as t')->join('branches as b', 'b.id', '=', 't.branch_id')->where('t.company_id', $companyId)->where('t.type', Transaction::TYPE_SELL)
            ->whereNull('t.deleted_at')->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)->whereDate('t.transaction_date', '>=', now()->startOfMonth()->toDateString())
            ->groupBy('b.id', 'b.name')->selectRaw('b.name as name, sum(t.final_amount) as sales')->orderByDesc('sales')->limit(8)->get()
            ->map(fn (object $row): array => ['name' => (string) $row->name, 'sales' => round((float) $row->sales, 2)])->all();
    }

    /**
     * @return array{headcount: int, present: int, absent: int, on_leave: int, pending_leave: int}
     */
    private function hrm(int $companyId, ?int $branchId): array
    {
        $employees = DB::table('employees')->where('company_id', $companyId)->whereNull('deleted_at')->where('status', 'active')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId));
        $ids = (clone $employees)->pluck('id');
        $today = now()->toDateString();
        $status = DB::table('hr_attendance')->where('company_id', $companyId)->whereDate('work_date', $today)->whereIn('employee_id', $ids)->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');

        return [
            'headcount' => $ids->count(),
            'present' => (int) ($status['present'] ?? 0),
            'absent' => (int) ($status['absent'] ?? 0),
            'on_leave' => (int) ($status['leave'] ?? 0) + (int) ($status['unpaid_leave'] ?? 0),
            'pending_leave' => (int) DB::table('leave_requests')->where('company_id', $companyId)->where('status', 'pending')->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->count(),
        ];
    }
}
