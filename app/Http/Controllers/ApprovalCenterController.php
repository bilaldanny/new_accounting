<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Approvals\ApprovalRegistry;
use App\Services\Approvals\ApprovalWorkflows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Approval Center: workflows (levels, sequential and parallel approvers, trigger rules), delegation, the inbox of documents
 * waiting for the signed-in user and the outbox of their own documents, the history of every action, and the approval of
 * discount codes. Approving a document is still done with its own approve button; the workflow decides whose turn it is.
 */
class ApprovalCenterController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly ApprovalWorkflows $workflows) {}

    // ------------------------------------------------------------------------------------------------ workflows

    public function types(): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter');

        return response()->json(['data' => collect(ApprovalRegistry::all())->map(fn (array $d, string $type): array => ['type' => $type, 'label' => $d['label']])->values()]);
    }

    public function workflows(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter');

        $companyId = $this->scopeCompanyId($request);
        $levels = DB::table('approval_workflow_levels')->get()->groupBy('approval_workflow_id');

        return response()->json(['data' => DB::table('approval_workflows')->where('company_id', $companyId)->orderBy('document_type')->orderBy('priority')->get()
            ->map(fn (object $w): array => (array) $w + ['levels' => ($levels[$w->id] ?? collect())->sortBy('level_no')->map(fn (object $l): array => [
                'level_no' => (int) $l->level_no, 'label' => $l->label, 'mode' => $l->mode, 'approvers' => json_decode((string) $l->approvers, true),
            ])->values()->all()])->all()]);
    }

    public function saveWorkflow(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter/manage');

        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'document_type' => ['required', Rule::in(array_keys(ApprovalRegistry::all()))],
            'min_amount' => 'nullable|numeric|min:0', 'max_amount' => 'nullable|numeric|min:0|gte:min_amount',
            'branch_id' => 'nullable|integer', 'creator_role_id' => 'nullable|integer', 'department_id' => 'nullable|integer',
            'priority' => 'nullable|integer|min:1|max:1000', 'is_active' => 'nullable|boolean',
            'levels' => 'required|array|min:1|max:10',
            'levels.*.label' => 'required|string|max:80',
            'levels.*.mode' => ['required', Rule::in(['any', 'all'])],
            'levels.*.approvers' => 'required|array|min:1|max:20',
            'levels.*.approvers.*.type' => ['required', Rule::in(['user', 'role'])],
            'levels.*.approvers.*.id' => 'required|integer',
        ]);

        $companyId = $this->scopeCompanyId($request);

        if (! empty($data['branch_id']) && ! Branch::query()->where('company_id', $companyId)->whereKey($data['branch_id'])->exists()) {
            throw ValidationException::withMessages(['branch_id' => ['Choose a branch of this company.']]);
        }

        foreach ($data['levels'] as $index => $level) {
            foreach ($level['approvers'] as $approver) {
                $exists = $approver['type'] === 'user'
                    ? User::query()->where('company_id', $companyId)->whereKey($approver['id'])->exists()
                    : Role::query()->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->whereKey($approver['id'])->exists();

                if (! $exists) {
                    throw ValidationException::withMessages(["levels.{$index}.approvers" => ['An approver is not a user or role of this company.']]);
                }
            }
        }

        $row = [
            'name' => $data['name'], 'document_type' => $data['document_type'], 'min_amount' => $data['min_amount'] ?? null, 'max_amount' => $data['max_amount'] ?? null,
            'branch_id' => $data['branch_id'] ?? null, 'creator_role_id' => $data['creator_role_id'] ?? null, 'department_id' => $data['department_id'] ?? null,
            'priority' => $data['priority'] ?? 100, 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now(),
        ];

        DB::transaction(function () use (&$id, $row, $data, $companyId): void {
            if ($id === null) {
                $id = DB::table('approval_workflows')->insertGetId($row + ['company_id' => $companyId, 'created_at' => now()]);
            } else {
                abort_unless(DB::table('approval_workflows')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
                DB::table('approval_workflows')->where('id', $id)->update($row);
                DB::table('approval_workflow_levels')->where('approval_workflow_id', $id)->delete();
            }

            foreach (array_values($data['levels']) as $index => $level) {
                DB::table('approval_workflow_levels')->insert([
                    'approval_workflow_id' => $id, 'level_no' => $index + 1, 'label' => $level['label'], 'mode' => $level['mode'],
                    'approvers' => json_encode($level['approvers']), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['message' => 'Successfully Saved', 'id' => $id], 201);
    }

    public function deleteWorkflow(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter/manage');

        $companyId = $this->scopeCompanyId($request);
        abort_unless(DB::table('approval_workflows')->where('company_id', $companyId)->where('id', $id)->exists(), 404);

        if (DB::table('approval_requests')->where('approval_workflow_id', $id)->exists()) {
            DB::table('approval_workflows')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);

            return response()->json(['message' => 'The workflow has requests, so it was switched off instead of deleted.']);
        }

        DB::table('approval_workflow_levels')->where('approval_workflow_id', $id)->delete();
        DB::table('approval_workflows')->where('id', $id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    // ------------------------------------------------------------------------------------------------ inbox / outbox / history

    public function inbox(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter');

        $user = Auth::user();
        $companyId = $this->scopeCompanyId($request);
        $rows = [];

        foreach (ApprovalRegistry::all() as $type => $definition) {
            if (! DB::table('approval_workflows')->where('company_id', $companyId)->where('document_type', $type)->where('is_active', true)->exists()) {
                continue;
            }

            $query = DB::table($definition['table'])->where('company_id', $companyId);
            $pending = $definition['pending']($query)->orderBy('id')->limit(300)->get();

            foreach ($pending as $row) {
                $document = ApprovalRegistry::describe($type, (int) $row->id);
                $matched = $document === null ? null : $this->workflows->matching($companyId, $type, $document);

                if ($matched === null) {
                    continue;
                }

                $approval = DB::table('approval_requests')->where('company_id', $companyId)->where('document_type', $type)->where('document_id', $row->id)->first();
                $current = $approval?->current_level ?? 1;

                if ($approval !== null && $approval->status !== 'pending') {
                    continue;
                }

                $level = $matched['levels']->firstWhere('level_no', $current);

                if ($level === null || ! $this->workflows->canAct($user, $companyId, $level)['ok']) {
                    continue;
                }

                if ($approval !== null && DB::table('approval_actions')->where('approval_request_id', $approval->id)->where('level_no', $current)->where('user_id', $user->id)->exists()) {
                    continue;
                }

                $rows[] = [
                    'type' => $type, 'label' => $definition['label'], 'id' => (int) $row->id, 'number' => $document['number'], 'amount' => $document['amount'],
                    'level' => (int) $current, 'of' => $matched['levels']->count(), 'level_label' => $level->label, 'workflow' => $matched['workflow']->name,
                    'approve_url' => '/api/'.$definition['prefix'].'/'.$row->id.'/approve', 'created_at' => $row->created_at ?? null,
                ];
            }
        }

        return response()->json(['data' => $rows]);
    }

    public function outbox(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter');

        $rows = DB::table('approval_requests')->where('company_id', $this->scopeCompanyId($request))->where('requested_by', Auth::id())
            ->orderByDesc('id')->limit(100)->get(['id', 'document_type', 'document_id', 'document_no', 'amount', 'status', 'current_level', 'approval_workflow_id', 'decided_at', 'created_at'])
            ->map(function (object $r): array {
                $levels = DB::table('approval_workflow_levels')->where('approval_workflow_id', $r->approval_workflow_id)->count();

                return (array) $r + ['levels' => $levels, 'label' => ApprovalRegistry::all()[$r->document_type]['label'] ?? $r->document_type];
            })->all();

        return response()->json(['data' => $rows]);
    }

    public function history(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter');

        $data = $request->validate(['document_type' => 'nullable|string|max:30', 'document_id' => 'nullable|integer']);
        $companyId = $this->scopeCompanyId($request);

        $rows = DB::table('approval_actions as a')->join('approval_requests as r', 'r.id', '=', 'a.approval_request_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')->leftJoin('users as b', 'b.id', '=', 'a.on_behalf_of')
            ->where('r.company_id', $companyId)
            ->when(! empty($data['document_type']), fn ($q) => $q->where('r.document_type', $data['document_type']))
            ->when(! empty($data['document_id']), fn ($q) => $q->where('r.document_id', $data['document_id']))
            ->orderByDesc('a.id')->limit(300)
            ->get(['a.id', 'r.document_type', 'r.document_id', 'r.document_no', 'a.level_no', 'a.action', 'a.comment', 'a.created_at', 'u.email as user_email', 'b.email as on_behalf_of_email']);

        return response()->json(['data' => $rows]);
    }

    // ------------------------------------------------------------------------------------------------ delegation

    public function delegations(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter');

        $user = Auth::user();
        $rows = DB::table('approval_delegations')->where('company_id', $this->scopeCompanyId($request))
            ->when(! hasMenuPermission('/approvalcenter/manage'), fn ($q) => $q->where(fn ($w) => $w->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id)))
            ->orderByDesc('id')->get();

        return response()->json(['data' => $rows]);
    }

    public function saveDelegation(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter');

        $data = $request->validate([
            'from_user_id' => 'nullable|integer', 'to_user_id' => 'required|integer', 'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'reason' => 'nullable|string|max:190',
        ]);
        $companyId = $this->scopeCompanyId($request);
        $from = (int) ($data['from_user_id'] ?? Auth::id());

        if ($from !== (int) Auth::id() && ! hasMenuPermission('/approvalcenter/manage')) {
            throw ValidationException::withMessages(['from_user_id' => ['You can only delegate your own approvals.']]);
        }

        if ($from === (int) $data['to_user_id']) {
            throw ValidationException::withMessages(['to_user_id' => ['Choose someone else to cover you.']]);
        }

        if (User::query()->where('company_id', $companyId)->whereIn('id', [$from, $data['to_user_id']])->count() !== 2) {
            throw ValidationException::withMessages(['to_user_id' => ['Both users must belong to this company.']]);
        }

        $id = DB::table('approval_delegations')->insertGetId([
            'company_id' => $companyId, 'from_user_id' => $from, 'to_user_id' => $data['to_user_id'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
            'reason' => $data['reason'] ?? null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'id' => $id], 201);
    }

    public function endDelegation(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter');

        $row = DB::table('approval_delegations')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->first();
        abort_if($row === null, 404);
        abort_if((int) $row->from_user_id !== (int) Auth::id() && ! hasMenuPermission('/approvalcenter/manage'), 403);

        DB::table('approval_delegations')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);

        return response()->json(['message' => 'Delegation ended']);
    }

    // ------------------------------------------------------------------------------------------------ discount codes

    public function settings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter');

        $row = DB::table('approval_settings')->where('company_id', $this->scopeCompanyId($request))->first();

        return response()->json(['data' => ['discount_code_approval' => (bool) ($row->discount_code_approval ?? false)]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter/manage');

        $data = $request->validate(['discount_code_approval' => 'required|boolean']);
        DB::table('approval_settings')->updateOrInsert(['company_id' => $this->scopeCompanyId($request)], ['discount_code_approval' => $data['discount_code_approval'], 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function pendingDiscounts(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter');

        return response()->json(['data' => DB::table('discounts')->where('company_id', $this->scopeCompanyId($request))->whereNull('deleted_at')->where('approval_status', 'pending')
            ->orderBy('id')->get(['id', 'name', 'code', 'discount_type', 'value', 'created_at'])]);
    }

    public function decideDiscount(Request $request, int $id, string $decision): JsonResponse
    {
        $this->authorizeMenuPermission('/approvalcenter/manage');

        abort_unless(in_array($decision, ['approve', 'reject'], true), 404);
        $changed = DB::table('discounts')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->where('approval_status', 'pending')
            ->update(['approval_status' => $decision === 'approve' ? 'approved' : 'rejected', 'approved_by' => Auth::id(), 'updated_at' => now()]);
        abort_if($changed === 0, 404);

        return response()->json(['message' => 'Discount code '.($decision === 'approve' ? 'approved' : 'rejected')]);
    }
}
