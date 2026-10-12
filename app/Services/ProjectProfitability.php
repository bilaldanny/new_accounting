<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Project profitability: revenue, direct cost and margin per project, from what is tagged with it, against its budget.
 *
 *  - revenue: tagged sale lines (net of what was returned) plus tagged voucher lines on revenue accounts (5xx, credit less debit);
 *  - cost: tagged purchase lines (net of returns, with their landed cost) plus tagged voucher lines on expense accounts
 *    (4xx and 6xx, debit less credit);
 *  - only approved vouchers, posted sales and non-draft purchases count, so the figures follow the books.
 *
 * A project is a dimension: the sale and purchase journals themselves are not split by project (the report reads the tagged
 * lines), so a tagged voucher line and the document line it relates to are never both counted unless both were tagged.
 */
class ProjectProfitability
{
    /**
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, float|int>}
     */
    public function build(int $companyId, ?int $projectId = null, ?string $from = null, ?string $to = null): array
    {
        $projects = Project::query()->where('company_id', $companyId)->when($projectId !== null, fn ($q) => $q->whereKey($projectId))->orderBy('code')->get();
        $ids = $projects->pluck('id')->all();

        $sales = DB::table('sell_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->whereIn('l.project_id', $ids)
            ->where('t.company_id', $companyId)->where('t.type', Transaction::TYPE_SELL)->whereNull('t.deleted_at')
            ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)
            ->when($from, fn ($q) => $q->whereDate('t.transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('t.transaction_date', '<=', $to))
            ->groupBy('l.project_id')
            ->selectRaw('l.project_id, count(distinct t.id) as documents, sum(l.subtotal * (1 - (case when l.quantity > 0 then (case when l.quantity_returned / l.quantity > 1 then 1 else l.quantity_returned / l.quantity end) else 0 end))) as amount')
            ->get()->keyBy('project_id');

        $packing = '(case when l.packing_qty is null or l.packing_qty < 1 then 1 else l.packing_qty end)';
        $purchases = DB::table('purchase_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->whereIn('l.project_id', $ids)
            ->where('t.company_id', $companyId)->where('t.type', Transaction::TYPE_PURCHASE)->whereNull('t.deleted_at')->where('t.status', '!=', 'draft')
            ->when($from, fn ($q) => $q->whereDate('t.transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('t.transaction_date', '<=', $to))
            ->groupBy('l.project_id')
            ->selectRaw("l.project_id, count(distinct t.id) as documents, sum(l.purchase_rate * (l.quantity - l.quantity_returned) * {$packing} + coalesce(l.landed_cost * (l.quantity - l.quantity_returned) / nullif(l.quantity, 0), 0)) as amount")
            ->get()->keyBy('project_id');

        $vouchers = DB::table('t_account_details as d')
            ->join('t_accounts as a', 'a.id', '=', 'd.t_account_id')
            ->whereIn('d.project_id', $ids)
            ->where('a.company_id', $companyId)->where('a.status', 'approved')
            ->when($from, fn ($q) => $q->whereDate('a.voucher_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('a.voucher_date', '<=', $to))
            ->groupBy('d.project_id')
            ->selectRaw("d.project_id,
                sum(case when substr(d.account_code, 1, 1) = '5' then d.credit - d.debit else 0 end) as revenue,
                sum(case when substr(d.account_code, 1, 1) in ('4', '6') then d.debit - d.credit else 0 end) as cost")
            ->get()->keyBy('project_id');

        $rows = $projects->map(function (Project $project) use ($sales, $purchases, $vouchers): array {
            $revenueSales = round((float) ($sales[$project->id]->amount ?? 0), 2);
            $revenueVouchers = round((float) ($vouchers[$project->id]->revenue ?? 0), 2);
            $costPurchases = round((float) ($purchases[$project->id]->amount ?? 0), 2);
            $costVouchers = round((float) ($vouchers[$project->id]->cost ?? 0), 2);
            $revenue = round($revenueSales + $revenueVouchers, 2);
            $cost = round($costPurchases + $costVouchers, 2);
            $margin = round($revenue - $cost, 2);

            return [
                'id' => (int) $project->id, 'code' => $project->code, 'name' => $project->name, 'status' => $project->status,
                'revenue_sales' => $revenueSales, 'revenue_vouchers' => $revenueVouchers, 'revenue' => $revenue,
                'cost_purchases' => $costPurchases, 'cost_vouchers' => $costVouchers, 'cost' => $cost,
                'margin' => $margin, 'margin_percent' => $revenue > 0 ? round($margin / $revenue * 100, 2) : null,
                'budget_revenue' => (float) $project->budget_revenue, 'budget_cost' => (float) $project->budget_cost,
                'cost_over_budget' => $project->budget_cost > 0 ? round($cost - (float) $project->budget_cost, 2) : null,
                'revenue_vs_budget' => $project->budget_revenue > 0 ? round($revenue - (float) $project->budget_revenue, 2) : null,
            ];
        })->values();

        return [
            'rows' => $rows,
            'summary' => [
                'projects' => $rows->count(), 'revenue' => round((float) $rows->sum('revenue'), 2), 'cost' => round((float) $rows->sum('cost'), 2),
                'margin' => round((float) $rows->sum('margin'), 2),
            ],
        ];
    }
}
