<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\User;
use App\Services\FollowUpReminders;
use App\Services\LeadAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRM automation: lead allocation rules (round-robin pools, by lead source, by branch), the manual allocation of leads that
 * are still unassigned, and running the follow-up reminders now (they also run hourly from the scheduler).
 */
class CrmAutomationController extends Controller
{
    use ScopesToCompany;

    public function rules(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/leads');

        return response()->json(['data' => DB::table('lead_allocation_rules')->where('company_id', $this->scopeCompanyId($request))->orderBy('priority')->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row + ['user_ids' => json_decode((string) $row->user_ids, true)])->all()]);
    }

    public function saveRule(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/leads/:id/edit');

        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'strategy' => ['required', Rule::in(['round_robin', 'by_source', 'by_branch'])],
            'lead_source_id' => 'nullable|integer', 'branch_id' => 'nullable|integer',
            'user_ids' => 'required|array|min:1|max:50', 'user_ids.*' => 'integer',
            'priority' => 'nullable|integer|min:1|max:1000', 'is_active' => 'nullable|boolean',
        ]);
        $companyId = $this->scopeCompanyId($request);

        if (User::query()->where('company_id', $companyId)->whereIn('id', $data['user_ids'])->count() !== count(array_unique($data['user_ids']))) {
            throw ValidationException::withMessages(['user_ids' => ['Every user in the pool must belong to this company.']]);
        }

        if ($data['strategy'] === 'by_branch' && (empty($data['branch_id']) || ! Branch::query()->where('company_id', $companyId)->whereKey($data['branch_id'])->exists())) {
            throw ValidationException::withMessages(['branch_id' => ['Choose a branch of this company.']]);
        }

        if ($data['strategy'] === 'by_source' && (empty($data['lead_source_id']) || ! DB::table('lead_sources')->where('company_id', $companyId)->where('id', $data['lead_source_id'])->exists())) {
            throw ValidationException::withMessages(['lead_source_id' => ['Choose a lead source of this company.']]);
        }

        $row = [
            'name' => $data['name'], 'strategy' => $data['strategy'], 'lead_source_id' => $data['strategy'] === 'by_source' ? $data['lead_source_id'] : null,
            'branch_id' => $data['strategy'] === 'by_branch' ? $data['branch_id'] : null, 'user_ids' => json_encode(array_values(array_unique($data['user_ids']))),
            'priority' => $data['priority'] ?? 100, 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now(),
        ];

        if ($id === null) {
            $id = DB::table('lead_allocation_rules')->insertGetId($row + ['company_id' => $companyId, 'cursor' => 0, 'created_at' => now()]);
        } else {
            abort_if(DB::table('lead_allocation_rules')->where('company_id', $companyId)->where('id', $id)->update($row) === 0 && ! DB::table('lead_allocation_rules')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
        }

        return response()->json(['message' => 'Successfully Saved', 'id' => $id], 201);
    }

    public function deleteRule(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/leads/:id/edit');

        abort_if(DB::table('lead_allocation_rules')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->delete() === 0, 404);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * Allocates the company's unassigned open leads by the active rules.
     */
    public function allocateUnassigned(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/leads/:id/edit');

        $companyId = $this->scopeCompanyId($request);
        $allocator = app(LeadAllocator::class);
        $count = 0;

        Lead::query()->where('company_id', $companyId)->whereNull('assigned_to')->orderBy('id')->get()->each(function (Lead $lead) use ($allocator, &$count): void {
            $allocator->assign($lead);

            if ($lead->assigned_to !== null) {
                $lead->save();
                $count++;
            }
        });

        return response()->json(['message' => "{$count} lead(s) allocated.", 'allocated' => $count]);
    }

    public function runReminders(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/activities');

        return response()->json(['data' => app(FollowUpReminders::class)->run((int) $request->input('hours', 24), $this->scopeCompanyId($request))]);
    }
}
