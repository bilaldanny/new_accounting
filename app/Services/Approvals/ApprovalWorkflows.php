<?php

namespace App\Services\Approvals;

use App\Models\User;
use App\Services\AppNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval workflows (Approval Center). A workflow is chosen for a document by its rule (type, amount range, branch, creator role),
 * lowest priority number first; its levels run in order, and a level is satisfied by one of its approvers (mode `any`) or by every
 * approver listed (mode `all`, the parallel case; a role entry is satisfied by any user holding that role). An approver may be
 * covered by a delegate while away. Nothing here approves the document itself: the existing approve endpoint does that once the
 * last level is satisfied (see Http\Middleware\ApprovalWorkflowGate), so a document that matches no workflow keeps the single
 * approval step it always had.
 */
class ApprovalWorkflows
{
    public function __construct(private readonly AppNotifier $notifier) {}

    public static function available(): bool
    {
        return Schema::hasTable('approval_workflows') && Schema::hasTable('approval_requests');
    }

    /**
     * The first active workflow of the company whose rule fits the document, with its levels.
     *
     * @param  array{amount: float, branch_id: int|null, creator_id: int|null}  $document
     * @return array{workflow: object, levels: Collection<int, object>}|null
     */
    public function matching(int $companyId, string $type, array $document): ?array
    {
        if (! self::available()) {
            return null;
        }

        $creator = $document['creator_id'] === null ? null : DB::table('users')->where('id', $document['creator_id'])->first(['role_id', 'department_id']);
        $creatorRole = $creator->role_id ?? null;
        $creatorDepartment = $creator->department_id ?? null;

        foreach (DB::table('approval_workflows')->where('company_id', $companyId)->where('document_type', $type)->where('is_active', true)->orderBy('priority')->orderBy('id')->get() as $workflow) {
            $fits = ($workflow->min_amount === null || $document['amount'] >= (float) $workflow->min_amount)
                && ($workflow->max_amount === null || $document['amount'] <= (float) $workflow->max_amount)
                && ($workflow->branch_id === null || (int) $workflow->branch_id === (int) $document['branch_id'])
                && ($workflow->creator_role_id === null || (int) $workflow->creator_role_id === (int) $creatorRole)
                && ($workflow->department_id === null || (int) $workflow->department_id === (int) $creatorDepartment);

            if (! $fits) {
                continue;
            }

            $levels = DB::table('approval_workflow_levels')->where('approval_workflow_id', $workflow->id)->orderBy('level_no')->get();

            if ($levels->isNotEmpty()) {
                return ['workflow' => $workflow, 'levels' => $levels];
            }
        }

        return null;
    }

    /**
     * The request row of a document, opened at level 1 the first time it is asked for.
     */
    public function requestFor(int $companyId, object $workflow, string $type, int $documentId, array $document): object
    {
        $existing = DB::table('approval_requests')->where('company_id', $companyId)->where('document_type', $type)->where('document_id', $documentId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $id = DB::table('approval_requests')->insertGetId([
            'company_id' => $companyId, 'approval_workflow_id' => $workflow->id, 'document_type' => $type, 'document_id' => $documentId,
            'document_no' => $document['number'], 'amount' => $document['amount'], 'branch_id' => $document['branch_id'], 'requested_by' => $document['creator_id'],
            'status' => 'pending', 'current_level' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('approval_requests')->find($id);
    }

    /**
     * Every user a level names: listed users, and users holding a listed role.
     *
     * @return list<int>
     */
    public function approverIds(int $companyId, object $level): array
    {
        $ids = [];

        foreach ((array) json_decode((string) $level->approvers, true) as $entry) {
            if (($entry['type'] ?? '') === 'user') {
                $ids[] = (int) $entry['id'];
            } elseif (($entry['type'] ?? '') === 'role') {
                $ids = array_merge($ids, DB::table('users')->where('company_id', $companyId)->where('role_id', (int) $entry['id'])->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all());
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether a user may act at a level, and for whom (a delegate acts for the approver who delegated to them).
     *
     * @return array{ok: bool, on_behalf_of: int|null}
     */
    public function canAct(User $user, int $companyId, object $level): array
    {
        $approvers = $this->approverIds($companyId, $level);

        if (in_array((int) $user->id, $approvers, true)) {
            return ['ok' => true, 'on_behalf_of' => null];
        }

        $delegators = $this->delegators((int) $user->id, $companyId);

        foreach ($delegators as $from) {
            if (in_array($from, $approvers, true)) {
                return ['ok' => true, 'on_behalf_of' => $from];
            }
        }

        return ['ok' => false, 'on_behalf_of' => null];
    }

    /**
     * The users who have delegated their approvals to this user today.
     *
     * @return list<int>
     */
    public function delegators(int $userId, int $companyId): array
    {
        if (! Schema::hasTable('approval_delegations')) {
            return [];
        }

        $today = now()->toDateString();

        return DB::table('approval_delegations')->where('company_id', $companyId)->where('to_user_id', $userId)->where('is_active', true)
            ->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->pluck('from_user_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Whether the approvals recorded for a level satisfy it.
     */
    public function levelSatisfied(int $companyId, object $request, object $level): bool
    {
        $actions = DB::table('approval_actions')->where('approval_request_id', $request->id)->where('level_no', $level->level_no)->where('action', 'approve')->get();

        if ($actions->isEmpty()) {
            return false;
        }

        if ($level->mode === 'any') {
            return true;
        }

        $actors = $actions->map(fn (object $a): int => (int) ($a->on_behalf_of ?: $a->user_id))->unique()->all();
        $roles = DB::table('users')->whereIn('id', $actors)->pluck('role_id', 'id');

        foreach ((array) json_decode((string) $level->approvers, true) as $entry) {
            $met = ($entry['type'] ?? '') === 'user'
                ? in_array((int) $entry['id'], $actors, true)
                : $roles->contains(fn ($role): bool => (int) $role === (int) $entry['id']);

            if (! $met) {
                return false;
            }
        }

        return true;
    }

    public function notifyLevel(int $companyId, object $request, object $level): void
    {
        $recipients = $this->approverIds($companyId, $level);
        $delegates = DB::table('approval_delegations')->where('company_id', $companyId)->whereIn('from_user_id', $recipients)->where('is_active', true)
            ->whereDate('starts_on', '<=', now()->toDateString())->whereDate('ends_on', '>=', now()->toDateString())->pluck('to_user_id')->all();
        $label = ApprovalRegistry::all()[$request->document_type]['label'] ?? $request->document_type;

        foreach (array_unique(array_merge($recipients, array_map('intval', $delegates))) as $userId) {
            if ((int) $userId !== (int) $request->requested_by) {
                $this->notifier->notify((int) $userId, 'approval_waiting', "{$label} {$request->document_no} waits for your approval (level {$level->level_no}: {$level->label})", null, '/approvalcenter', true);
            }
        }

        DB::table('approval_requests')->where('id', $request->id)->update(['notified_level' => $level->level_no, 'updated_at' => now()]);
    }

    public function notifyDecision(object $request, string $decision, ?string $by): void
    {
        if ($request->requested_by === null) {
            return;
        }

        $label = ApprovalRegistry::all()[$request->document_type]['label'] ?? $request->document_type;
        $this->notifier->notify((int) $request->requested_by, 'approval_'.$decision, "{$label} {$request->document_no} was {$decision}".($by ? " by {$by}" : ''), null, '/approvalcenter', true);
    }

    /**
     * Opens a request for every waiting document that matches a workflow and tells the approvers of its current level, once per
     * level. Run hourly (approvals:notify-pending); approvers also see the document in their inbox without it.
     *
     * @return array{opened: int, notified: int}
     */
    public function openPending(?int $companyId = null): array
    {
        $result = ['opened' => 0, 'notified' => 0];

        if (! self::available()) {
            return $result;
        }

        $companies = DB::table('approval_workflows')->where('is_active', true)->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->distinct()->pluck('company_id');

        foreach ($companies as $company) {
            foreach (ApprovalRegistry::all() as $type => $definition) {
                if (! DB::table('approval_workflows')->where('company_id', $company)->where('document_type', $type)->where('is_active', true)->exists()) {
                    continue;
                }

                $pending = $definition['pending'](DB::table($definition['table'])->where('company_id', $company))->orderBy('id')->limit(300)->get();

                foreach ($pending as $row) {
                    $document = ApprovalRegistry::describe($type, (int) $row->id);
                    $matched = $document === null ? null : $this->matching((int) $company, $type, $document);

                    if ($matched === null) {
                        continue;
                    }

                    $existed = DB::table('approval_requests')->where('company_id', $company)->where('document_type', $type)->where('document_id', $row->id)->exists();
                    $request = $this->requestFor((int) $company, $matched['workflow'], $type, (int) $row->id, $document);
                    $result['opened'] += $existed ? 0 : 1;
                    $level = $matched['levels']->firstWhere('level_no', $request->current_level);

                    if ($request->status === 'pending' && $level !== null && (int) $request->notified_level < (int) $level->level_no) {
                        $this->notifyLevel((int) $company, $request, $level);
                        $result['notified']++;
                    }
                }
            }
        }

        return $result;
    }
}
