<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Cashier;
use App\Models\CommissionAgent;
use App\Models\CommissionTier;
use App\Models\SalesTarget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Commission calculation, targets and KPI tracking. A month's net sales of a subject are its posted sales less the sales returns
 * made against them. For a commission agent the commission is the net sales at the agent's base percentage, or at the rate of the
 * highest tier whose threshold the month's net sales reach (a tier for the agent, else a company tier). A user (the salesperson who
 * saved the sale) or a cashier has no rate: only the target and the achievement are shown. Read-only over the sales; nothing is posted.
 */
class SalesKpiController extends Controller
{
    use ScopesToCompany;

    public function statement(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/saleskpi');

        $data = $request->validate(['period' => 'nullable|date_format:Y-m', 'subject' => 'nullable|in:agent,user,cashier', 'company_id' => 'nullable|integer']);

        $companyId = $this->scopeCompanyId($request);
        $period = $data['period'] ?? now()->format('Y-m');
        $subject = $data['subject'] ?? 'agent';
        $from = $period.'-01';
        $to = date('Y-m-t', strtotime($from));
        $column = ['agent' => 'commission_agent_id', 'user' => 'created_by', 'cashier' => 'cashier_id'][$subject];

        if ($subject === 'cashier' && ! Schema::hasColumn('transactions', 'cashier_id')) {
            return response()->json(['period' => $period, 'subject' => $subject, 'data' => []]);
        }

        $sales = DB::table('transactions')
            ->where('company_id', $companyId)->where('type', Transaction::TYPE_SELL)->whereNull('deleted_at')
            ->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)->whereNotNull($column)
            ->whereDate('transaction_date', '>=', $from)->whereDate('transaction_date', '<=', $to)
            ->groupBy($column)->selectRaw("{$column} as subject_id, sum(final_amount) as gross, count(*) as sales")->get()->keyBy('subject_id');

        $returns = DB::table('transactions as r')
            ->join('transactions as s', 's.id', '=', 'r.parent_id')
            ->where('r.company_id', $companyId)->where('r.type', Transaction::TYPE_SELL_RETURN)->whereNull('r.deleted_at')
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

        return response()->json(['period' => $period, 'subject' => $subject, 'data' => $rows, 'totals' => [
            'net' => round((float) $rows->sum('net'), 2), 'commission' => round((float) $rows->sum('commission'), 2),
        ]]);
    }

    public function targets(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/saleskpi');

        return response()->json(['data' => SalesTarget::query()->visibleToCurrentUser()
            ->when($request->filled('period'), fn ($q) => $q->where('period', $request->input('period')))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderByDesc('period')->limit(500)->get()]);
    }

    public function setTarget(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/saleskpi/edit');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'subject_type' => 'required|in:'.implode(',', SalesTarget::SUBJECTS),
            'subject_id' => 'required|integer',
            'period' => 'required|date_format:Y-m',
            'target_amount' => 'required|numeric|min:0',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $exists = match ($data['subject_type']) {
            'agent' => CommissionAgent::query()->where('company_id', $companyId)->whereKey($data['subject_id'])->exists(),
            'cashier' => Cashier::query()->where('company_id', $companyId)->whereKey($data['subject_id'])->exists(),
            default => User::query()->where('company_id', $companyId)->whereKey($data['subject_id'])->exists(),
        };

        if (! $exists) {
            throw ValidationException::withMessages(['subject_id' => ['Choose a '.$data['subject_type'].' of this company.']]);
        }

        $target = SalesTarget::query()->updateOrCreate(
            ['company_id' => $companyId, 'subject_type' => $data['subject_type'], 'subject_id' => $data['subject_id'], 'period' => $data['period']],
            ['target_amount' => $data['target_amount'], 'created_by' => Auth::id()],
        );

        return response()->json(['message' => 'Successfully Saved', 'data' => $target], 201);
    }

    public function tiers(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/saleskpi');

        return response()->json(['data' => CommissionTier::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderBy('threshold_amount')->get()]);
    }

    public function storeTier(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/saleskpi/edit');

        $data = $request->validate(['company_id' => 'nullable|integer', 'commission_agent_id' => 'nullable|integer', 'threshold_amount' => 'required|numeric|min:0', 'rate_percent' => 'required|numeric|gt:0|max:100']);
        $companyId = $this->scopeCompanyId($request);

        if (isset($data['commission_agent_id']) && ! CommissionAgent::query()->where('company_id', $companyId)->whereKey($data['commission_agent_id'])->exists()) {
            throw ValidationException::withMessages(['commission_agent_id' => ['Choose a commission agent of this company.']]);
        }

        $tier = CommissionTier::query()->create(collect($data)->only(['commission_agent_id', 'threshold_amount', 'rate_percent'])->all() + ['company_id' => $companyId]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $tier], 201);
    }

    public function destroyTier(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/saleskpi/edit');

        CommissionTier::query()->visibleToCurrentUser()->findOrFail($id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }
}
