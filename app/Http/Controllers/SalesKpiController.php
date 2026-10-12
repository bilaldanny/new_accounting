<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Cashier;
use App\Models\CommissionAgent;
use App\Models\CommissionTier;
use App\Models\SalesTarget;
use App\Models\User;
use App\Services\SalesKpiStatement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $built = app(SalesKpiStatement::class)->build($companyId, $period, $subject);

        return response()->json(['period' => $period, 'subject' => $subject, 'data' => $built['rows'], 'totals' => $built['totals']]);
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
