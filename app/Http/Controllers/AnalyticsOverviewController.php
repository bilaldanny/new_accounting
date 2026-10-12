<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Reports\ProfitByDimensionReport;
use App\Services\Reports\PurchaseSaleSummary;
use App\Services\Reports\SalesByDimensionReport;
use App\Services\SalesForecast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The analytics page: the figures of the reports in one place (net sales by month, the forecast, sales by salesperson and payment
 * method, profit by branch and customer). Each block is the report of the same name for the period, so the page and the reports
 * cannot disagree. Read only; company and branch come from the signed-in user.
 */
class AnalyticsOverviewController extends Controller
{
    use ScopesToCompany;

    public function show(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/analytics');

        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'company_id' => 'nullable|integer']);
        $companyId = $this->scopeCompanyId($request);
        $user = Auth::user();
        $branchId = $user->branch_id && ! $user->hasRole('superadmin') && ! $user->hasRole('companyadmin') ? (int) $user->branch_id : null;
        $today = Carbon::today();
        $to = $data['to'] ?? $today->toDateString();
        $from = $data['from'] ?? Carbon::parse($to)->subDays(29)->toDateString();
        $range = ['start_date' => $from, 'end_date' => $to];
        $summary = app(PurchaseSaleSummary::class);

        $months = [];

        for ($back = 5; $back >= 0; $back--) {
            $start = $today->copy()->startOfMonth()->subMonthsNoOverflow($back);
            $totals = $summary->build($companyId, $branchId, ['start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString()])['totals'];
            $months[] = ['month' => $start->format('Y-m'), 'net_sales' => round((float) $totals['net_sales'], 2)];
        }

        $totals = $summary->build($companyId, $branchId, $range)['totals'];
        $profit = app(ProfitByDimensionReport::class);

        return response()->json(['data' => [
            'period' => ['from' => $from, 'to' => $to],
            'totals' => $totals,
            'monthly_sales' => $months,
            'forecast' => app(SalesForecast::class)->forCompany($companyId, $branchId, $today),
            'by_salesperson' => SalesByDimensionReport::for('salesperson')->rows($companyId, $branchId, $range)->take(5)->values(),
            'by_payment_method' => SalesByDimensionReport::for('payment-method')->rows($companyId, $branchId, $range)->take(6)->values(),
            'profit_by_branch' => $profit->forDimension('branch')->rows($companyId, $branchId, $range)->take(5)->values(),
            'profit_by_customer' => $profit->forDimension('customer')->rows($companyId, $branchId, $range)->take(5)->values(),
        ]]);
    }
}
