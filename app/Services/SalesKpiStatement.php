<?php

namespace App\Services;

use App\Models\Cashier;
use App\Models\CommissionAgent;
use App\Models\CommissionTier;
use App\Models\SalesTarget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A month's net sales, target achievement and commission per commission agent, salesperson (the user who saved the sale) or cashier.
 * Net sales are the posted sales of the month less the sales returns made against them in the month. For a commission agent the
 * commission is the net sales at the agent's base percentage, or at the rate of the highest tier whose threshold the month reaches
 * (an agent tier, else a company tier). Read only; nothing is posted. Used by the KPI page and by the commission and target reports.
 */
class SalesKpiStatement
{
    public const SUBJECTS = ['agent', 'user', 'cashier'];

    /**
     * @return array{period: string, subject: string, rows: Collection<int, array<string, mixed>>, totals: array{net: float, commission: float}}
     */
    public function build(int $companyId, string $period, string $subject, ?int $branchId = null): array
    {
        $from = $period.'-01';
        $to = date('Y-m-t', strtotime($from));
        $column = ['agent' => 'commission_agent_id', 'user' => 'created_by', 'cashier' => 'cashier_id'][$subject];
        $empty = ['period' => $period, 'subject' => $subject, 'rows' => collect(), 'totals' => ['net' => 0.0, 'commission' => 0.0]];

        if ($subject === 'cashier' && ! Schema::hasColumn('transactions', 'cashier_id')) {
            return $empty;
        }

        $sales = DB::table('transactions')
            ->where('company_id', $companyId)->where('type', Transaction::TYPE_SELL)->whereNull('deleted_at')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)->whereNotNull($column)
            ->whereDate('transaction_date', '>=', $from)->whereDate('transaction_date', '<=', $to)
            ->groupBy($column)->selectRaw("{$column} as subject_id, sum(final_amount) as gross, count(*) as sales")->get()->keyBy('subject_id');

        $returns = DB::table('transactions as r')
            ->join('transactions as s', 's.id', '=', 'r.parent_id')
            ->where('r.company_id', $companyId)->where('r.type', Transaction::TYPE_SELL_RETURN)->whereNull('r.deleted_at')
            ->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))
            ->whereNotNull("s.{$column}")
            ->whereDate('r.transaction_date', '>=', $from)->whereDate('r.transaction_date', '<=', $to)
            ->groupBy("s.{$column}")->selectRaw("s.{$column} as subject_id, sum(r.final_amount) as returned")->pluck('returned', 'subject_id');

        $targets = SalesTarget::query()->where('company_id', $companyId)->where('subject_type', $subject)->where('period', $period)->pluck('target_amount', 'subject_id');
        $ids = $sales->keys()->merge($targets->keys())->unique()->values();

        $names = match ($subject) {
            'agent' => CommissionAgent::query()->whereIn('id', $ids)->pluck('name', 'id'),
            'cashier' => Cashier::withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
            default => User::query()->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn (User $user): array => [$user->id => trim($user->first_name.' '.$user->last_name)]),
        };
        $agents = $subject === 'agent' ? CommissionAgent::query()->whereIn('id', $ids)->pluck('commission_percent', 'id') : collect();
        $tiers = $subject === 'agent'
            ? CommissionTier::query()->where('company_id', $companyId)->orderByDesc('threshold_amount')->get()
            : collect();

        $rows = $ids->map(function ($id) use ($sales, $returns, $targets, $names, $agents, $tiers): array {
            $gross = round((float) ($sales[$id]->gross ?? 0), 2);
            $net = round($gross - (float) ($returns[$id] ?? 0), 2);
            $target = isset($targets[$id]) ? round((float) $targets[$id], 2) : null;
            $rate = null;
            $tierUsed = null;

            if ($agents->has($id)) {
                $rate = (float) $agents[$id];
                $tier = $tiers->first(fn (CommissionTier $tier): bool => ($tier->commission_agent_id === null || (int) $tier->commission_agent_id === (int) $id) && $net >= (float) $tier->threshold_amount);

                if ($tier !== null && (float) $tier->rate_percent > $rate) {
                    $rate = (float) $tier->rate_percent;
                    $tierUsed = $tier->threshold_amount;
                }
            }

            return [
                'subject_id' => (int) $id, 'name' => (string) ($names[$id] ?? '#'.$id), 'sales' => (int) ($sales[$id]->sales ?? 0),
                'gross' => $gross, 'returned' => round((float) ($returns[$id] ?? 0), 2), 'net' => $net,
                'target' => $target, 'achievement_percent' => $target !== null && $target > 0 ? round($net / $target * 100, 1) : null,
                'rate_percent' => $rate, 'tier_threshold' => $tierUsed, 'commission' => $rate === null ? null : round($net * $rate / 100, 2),
            ];
        })->sortByDesc('net')->values();

        return ['period' => $period, 'subject' => $subject, 'rows' => $rows, 'totals' => [
            'net' => round((float) $rows->sum('net'), 2), 'commission' => round((float) $rows->sum('commission'), 2),
        ]];
    }
}
