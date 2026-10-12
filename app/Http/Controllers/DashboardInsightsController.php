<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Models\ChartOfAccount;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The dashboard gaps closed in Wave 1, as one endpoint of insight cards that every report permission still guards:
 *
 *  - top customers and top products of a period (sales net of nothing returned: posted sales only);
 *  - this period's expenses by account and the cash and bank in and out;
 *  - a personal dashboard layout (which cards, in which order) saved per user;
 *  - the same figures as a CSV.
 *
 * The figures come from the posted sales and the approved vouchers, like the reports they summarise, for the company and
 * branch the user may see.
 */
class DashboardInsightsController extends Controller
{
    use ResolvesReportScope;

    public const WIDGETS = ['sales', 'receivables', 'inventory', 'approvals', 'financial', 'cheques', 'stats', 'recent', 'forecast', 'trends', 'aging', 'movement', 'top_customers', 'top_products', 'expenses', 'cash_flow'];

    public function show(Request $request): JsonResponse|StreamedResponse
    {
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'export' => 'nullable|in:csv']);
        [$companyId, $branchId] = $this->reportScope($request);

        if ($companyId === null) {
            return response()->json(['data' => [], 'needs_company' => true]);
        }

        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $insights = [
            'period' => ['from' => $from, 'to' => $to],
            'top_customers' => hasMenuPermission('/sell') ? $this->topCustomers($companyId, $branchId, $from, $to) : [],
            'top_products' => hasMenuPermission('/sell') ? $this->topProducts($companyId, $branchId, $from, $to) : [],
            'expenses' => hasMenuPermission('/report/expense') ? $this->expenses($companyId, $branchId, $from, $to) : [],
            'cash_flow' => hasMenuPermission('/chart-of-account') ? $this->cashFlow($companyId, $branchId, $from, $to) : ['in' => 0.0, 'out' => 0.0, 'net' => 0.0],
        ];

        if (($data['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($insights): void {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['section', 'name', 'amount', 'extra']);

                foreach ($insights['top_customers'] as $row) {
                    fputcsv($out, ['top_customers', $row['name'], $row['amount'], $row['documents'].' documents']);
                }

                foreach ($insights['top_products'] as $row) {
                    fputcsv($out, ['top_products', $row['name'], $row['amount'], $row['quantity'].' sold']);
                }

                foreach ($insights['expenses'] as $row) {
                    fputcsv($out, ['expenses', $row['name'], $row['amount'], $row['code']]);
                }

                foreach (['in', 'out', 'net'] as $key) {
                    fputcsv($out, ['cash_flow', $key, $insights['cash_flow'][$key], '']);
                }

                fclose($out);
            }, 'dashboard-'.$from.'-'.$to.'.csv', ['Content-Type' => 'text/csv']);
        }

        return response()->json(['data' => $insights]);
    }

    public function preferences(): JsonResponse
    {
        $layout = DB::table('dashboard_preferences')->where('user_id', Auth::id())->value('layout');

        return response()->json(['data' => $layout === null ? ['order' => self::WIDGETS, 'hidden' => []] : json_decode((string) $layout, true), 'widgets' => self::WIDGETS]);
    }

    public function savePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => 'required|array|max:40', 'order.*' => ['string', Rule::in(self::WIDGETS)],
            'hidden' => 'nullable|array|max:40', 'hidden.*' => ['string', Rule::in(self::WIDGETS)],
        ]);

        DB::table('dashboard_preferences')->updateOrInsert(
            ['user_id' => Auth::id()],
            ['layout' => json_encode(['order' => array_values(array_unique($data['order'])), 'hidden' => array_values(array_unique($data['hidden'] ?? []))]), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['message' => 'Successfully Saved']);
    }

    /**
     * @return list<array{name: string, amount: float, documents: int}>
     */
    private function topCustomers(int $companyId, ?int $branchId, string $from, string $to): array
    {
        return DB::table('transactions as t')->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.company_id', $companyId)->where('t.type', Transaction::TYPE_SELL)->whereNull('t.deleted_at')
            ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->whereDate('t.transaction_date', '>=', $from)->whereDate('t.transaction_date', '<=', $to)
            ->groupBy('t.contact_id', 'c.business_name', 'c.first_name', 'c.last_name')
            ->selectRaw('c.business_name, c.first_name, c.last_name, sum(t.final_amount) as amount, count(*) as documents')
            ->orderByDesc('amount')->limit(10)->get()
            ->map(fn (object $row): array => [
                'name' => (string) ($row->business_name ?: trim($row->first_name.' '.$row->last_name)), 'amount' => round((float) $row->amount, 2), 'documents' => (int) $row->documents,
            ])->all();
    }

    /**
     * @return list<array{name: string, amount: float, quantity: float}>
     */
    private function topProducts(int $companyId, ?int $branchId, string $from, string $to): array
    {
        return DB::table('sell_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('t.company_id', $companyId)->where('t.type', Transaction::TYPE_SELL)->whereNull('t.deleted_at')
            ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->whereDate('t.transaction_date', '>=', $from)->whereDate('t.transaction_date', '<=', $to)
            ->groupBy('l.product_id', 'p.name')
            ->selectRaw('p.name, sum(l.subtotal) as amount, sum(l.quantity - l.quantity_returned) as quantity')
            ->orderByDesc('amount')->limit(10)->get()
            ->map(fn (object $row): array => ['name' => (string) $row->name, 'amount' => round((float) $row->amount, 2), 'quantity' => round((float) $row->quantity, 2)])->all();
    }

    /**
     * @return list<array{code: string, name: string, amount: float}>
     */
    private function expenses(int $companyId, ?int $branchId, string $from, string $to): array
    {
        return DB::table('t_account_details as d')->join('t_accounts as a', 'a.id', '=', 'd.t_account_id')->leftJoin('chart_of_accounts as c', 'c.id', '=', 'd.coa_id')
            ->where('a.company_id', $companyId)->where('a.status', 'approved')->whereRaw("substr(d.account_code, 1, 1) = '4'")
            ->when($branchId !== null, fn ($q) => $q->where('a.branch_id', $branchId))
            ->whereDate('a.voucher_date', '>=', $from)->whereDate('a.voucher_date', '<=', $to)
            ->groupBy('d.account_code', 'c.name')
            ->selectRaw('d.account_code as code, c.name, sum(d.debit) - sum(d.credit) as amount')
            ->orderByDesc('amount')->limit(15)->get()
            ->map(fn (object $row): array => ['code' => (string) $row->code, 'name' => (string) $row->name, 'amount' => round((float) $row->amount, 2)])->all();
    }

    /**
     * @return array{in: float, out: float, net: float}
     */
    private function cashFlow(int $companyId, ?int $branchId, string $from, string $to): array
    {
        $accounts = collect(ChartOfAccount::bankAndCashAccountOptions($companyId, $branchId))->map(fn ($account): int => (int) data_get($account, 'id'))->all();

        if ($accounts === []) {
            return ['in' => 0.0, 'out' => 0.0, 'net' => 0.0];
        }

        $row = DB::table('t_account_details as d')->join('t_accounts as a', 'a.id', '=', 'd.t_account_id')
            ->where('a.company_id', $companyId)->where('a.status', 'approved')->whereIn('d.coa_id', $accounts)
            ->when($branchId !== null, fn ($q) => $q->where('a.branch_id', $branchId))
            ->whereDate('a.voucher_date', '>=', $from)->whereDate('a.voucher_date', '<=', $to)
            ->selectRaw('coalesce(sum(d.debit), 0) as money_in, coalesce(sum(d.credit), 0) as money_out')->first();

        return ['in' => round((float) $row->money_in, 2), 'out' => round((float) $row->money_out, 2), 'net' => round((float) $row->money_in - (float) $row->money_out, 2)];
    }
}
