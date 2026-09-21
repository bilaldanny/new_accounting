<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Services\DashboardMetrics;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The dashboard cards. The page loads them one group at a time (`GET /api/dashboard/{widget}`), so the light ones
 * show at once and a slow one never holds the rest back.
 *
 * - Scope: a superadmin sees the company they pick (`company_id`), or every company when none is picked; anyone
 *   else sees their own company, and a plain branch user only their own branch (ResolvesReportScope, the
 *   reports' rule).
 * - Permission: a figure is sent only when the user may open the page it comes from (the same menu rows the
 *   reports and lists check), so a card the user cannot see is not in the answer at all.
 * - Cost: what runs a ledger per contact, the stock valuation or the profit and loss is kept for CACHE_SECONDS
 *   per company and branch (`refresh=1` recalculates it). The figures cached are the raw ones, never filtered by
 *   who asked, and the key names the company and branch, so a company never reads another's.
 * - Figures that need one company (the ledger, the stock value, profit and loss, cash and bank) are left out
 *   for a superadmin who has not picked one; `needs_company` names them.
 */
class DashboardController extends Controller
{
    use ResolvesReportScope;

    public const CACHE_SECONDS = 300;

    /**
     * The menu row each figure needs, by widget and part.
     *
     * @var array<string, array<string, string>>
     */
    public const PERMISSIONS = [
        'sales' => ['sales' => '/sell', 'purchases' => '/purchase'],
        'receivables' => ['receivables' => '/report/customer-outstanding', 'payables' => '/report/supplier-outstanding', 'credit_watch' => '/customer'],
        'inventory' => ['low_stock' => '/lowstock', 'stock_value' => '/report/stock'],
        'approvals' => [
            'purchase' => '/purchase/approval', 'sell' => '/sell/approval', 'journal' => '/journalentry/approval',
            'payment' => '/acpayment/approval', 'expense' => '/expense/approval', 'deposit' => '/deposit/approval', 'fundtransfer' => '/fundtransfer/approval',
        ],
        'financial' => ['net_profit' => '/report/profit-loss', 'cash_bank' => '/chart-of-account'],
        'stats' => ['customers' => '/customer', 'suppliers' => '/supplier', 'products' => '/product'],
        'recent' => ['recent_sales' => '/sell'],
    ];

    /**
     * Parts that are worked out for one company only.
     *
     * @var list<string>
     */
    private const NEEDS_COMPANY = ['receivables', 'payables', 'credit_watch', 'stock_value', 'net_profit', 'cash_bank'];

    public function __construct(private readonly DashboardMetrics $metrics) {}

    public function show(Request $request, string $widget): JsonResponse
    {
        abort_unless(array_key_exists($widget, self::PERMISSIONS), 404);

        $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'refresh' => 'nullable|in:0,1,true,false',
        ]);

        [$companyId, $branchId] = $this->reportScope($request);
        $refresh = $request->boolean('refresh');

        $allowed = array_keys(array_filter(self::PERMISSIONS[$widget], fn (string $path): bool => hasMenuPermission($path)));
        $skipped = $companyId === null ? array_values(array_intersect($allowed, self::NEEDS_COMPANY)) : [];
        $wanted = array_values(array_diff($allowed, $skipped));

        $cachedAt = [];
        $data = $wanted === [] ? [] : $this->{$widget}($wanted, $companyId, $branchId, $refresh, $cachedAt);

        return response()->json([
            'data' => $data,
            'needs_company' => $skipped,
            'cached_at' => $cachedAt === [] ? null : min($cachedAt),
        ]);
    }

    /**
     * @param  list<string>  $wanted
     * @return array<string, mixed>
     */
    private function sales(array $wanted, ?int $companyId, ?int $branchId): array
    {
        return array_intersect_key($this->metrics->trade($companyId, $branchId, $this->today()), array_flip($wanted));
    }

    /**
     * @param  list<string>  $wanted
     * @param  list<string>  $cachedAt
     * @return array<string, mixed>
     */
    private function receivables(array $wanted, int $companyId, ?int $branchId, bool $refresh, array &$cachedAt): array
    {
        $figures = $this->cached('receivables', $companyId, $branchId, $refresh, $cachedAt, fn (): array => $this->metrics->receivables($companyId, $branchId));

        return array_intersect_key($figures, array_flip($wanted));
    }

    /**
     * @param  list<string>  $wanted
     * @param  list<string>  $cachedAt
     * @return array<string, mixed>
     */
    private function inventory(array $wanted, ?int $companyId, ?int $branchId, bool $refresh, array &$cachedAt): array
    {
        $data = [];

        if (in_array('low_stock', $wanted, true)) {
            $data['low_stock'] = $this->cached('low_stock', $companyId, $branchId, $refresh, $cachedAt, fn (): array => $this->metrics->lowStockAlerts($companyId, $branchId));
        }

        if (in_array('stock_value', $wanted, true)) {
            $data['stock_value'] = $this->cached('stock_value', $companyId, $branchId, $refresh, $cachedAt, fn (): array => $this->metrics->stockOnHand($companyId, $branchId, $this->today()));
        }

        return $data;
    }

    /**
     * @param  list<string>  $wanted
     * @return array<string, mixed>
     */
    private function approvals(array $wanted, ?int $companyId, ?int $branchId): array
    {
        return $this->metrics->approvals($companyId, $branchId, $wanted);
    }

    /**
     * @param  list<string>  $wanted
     * @param  list<string>  $cachedAt
     * @return array<string, mixed>
     */
    private function financial(array $wanted, int $companyId, ?int $branchId, bool $refresh, array &$cachedAt): array
    {
        $data = [];

        if (in_array('net_profit', $wanted, true)) {
            $data['net_profit'] = $this->cached('net_profit', $companyId, $branchId, $refresh, $cachedAt, fn (): array => $this->metrics->netProfit($companyId, $branchId, $this->today()));
        }

        if (in_array('cash_bank', $wanted, true)) {
            $data['cash_bank'] = $this->cached('cash_bank', $companyId, $branchId, $refresh, $cachedAt, fn (): array => $this->metrics->cashAndBank($companyId, $branchId, $this->today()));
        }

        return $data;
    }

    /**
     * @param  list<string>  $wanted
     * @return array<string, mixed>
     */
    private function stats(array $wanted, ?int $companyId, ?int $branchId): array
    {
        return $this->metrics->stats($companyId, $branchId, $wanted);
    }

    /**
     * @param  list<string>  $wanted
     * @return array<string, mixed>
     */
    private function recent(array $wanted, ?int $companyId, ?int $branchId): array
    {
        return ['recent_sales' => $this->metrics->recentSales($companyId, $branchId)];
    }

    /**
     * The figures of one part, worked out at most once per CACHE_SECONDS for a company and branch.
     *
     * @param  list<string>  $cachedAt  gets the time the figures were worked out
     * @return array<string, mixed>
     */
    private function cached(string $part, ?int $companyId, ?int $branchId, bool $refresh, array &$cachedAt, Closure $compute): array
    {
        $key = "dashboard:{$part}:{$companyId}:{$branchId}";

        if ($refresh) {
            Cache::forget($key);
        }

        $entry = Cache::remember($key, self::CACHE_SECONDS, fn (): array => ['at' => now()->toIso8601String(), 'value' => $compute()]);
        $cachedAt[] = $entry['at'];

        return $entry['value'];
    }

    private function today(): CarbonInterface
    {
        return today();
    }
}
