<?php

namespace App\Http\Middleware;

use App\Services\Approvals\ApprovalRegistry;
use App\Services\Approvals\ApprovalWorkflows;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the existing approve / reject buttons behind a company's approval workflow when one matches the document:
 *
 *  - an approve records the user's action at the current level; only when the last level is satisfied does the request go on to
 *    the real approve of the document (which still checks its own permission);
 *  - a reject by an eligible approver is recorded and goes on to the real reject;
 *  - a user who is not an approver of the current level (or a delegate of one) is refused.
 *
 * A document that matches no active workflow, a company with no workflow and a database without the tables pass through
 * untouched: the single-step approval is exactly what it was.
 */
class ApprovalWorkflowGate
{
    public function __construct(private readonly ApprovalWorkflows $workflows) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $request->isMethod('POST') || $user->company_id === null || ! ApprovalWorkflows::available()
            || ! preg_match('#^api/([a-z-]+)/(\d+)/(approve|reject)$#', $request->path(), $match)) {
            return $next($request);
        }

        $definition = ApprovalRegistry::forPrefix($match[1]);

        if ($definition === null) {
            return $next($request);
        }

        $companyId = (int) $user->company_id;
        $type = (string) $definition['type'];
        $documentId = (int) $match[2];
        $document = ApprovalRegistry::describe($type, $documentId);

        if ($document === null || $document['company_id'] !== $companyId || ! hasMenuPermission((string) $definition['permission'])) {
            return $next($request);
        }

        $matched = $this->workflows->matching($companyId, $type, $document);

        if ($matched === null) {
            return $next($request);
        }

        $approval = $this->workflows->requestFor($companyId, $matched['workflow'], $type, $documentId, $document);

        if ($approval->status === 'approved') {
            return $next($request);
        }

        if ($approval->status !== 'pending') {
            return response()->json(['message' => 'This document was '.$approval->status.' in its approval workflow.', 'errors' => ['approval' => ['Workflow '.$approval->status.'.']]], 422);
        }

        $levels = DB::table('approval_workflow_levels')->where('approval_workflow_id', $approval->approval_workflow_id)->orderBy('level_no')->get();
        $level = $levels->firstWhere('level_no', $approval->current_level);

        if ($level === null) {
            return $next($request);
        }

        $can = $this->workflows->canAct($user, $companyId, $level);

        if (! $can['ok']) {
            return response()->json(['message' => "Level {$level->level_no} ({$level->label}) of the approval workflow is waiting for its approvers, not for you.", 'errors' => ['approval' => ['Not an approver at this level.']]], 403);
        }

        $acted = DB::table('approval_actions')->where('approval_request_id', $approval->id)->where('level_no', $level->level_no)
            ->where(fn ($q) => $q->where('user_id', $user->id)->when($can['on_behalf_of'] !== null, fn ($w) => $w->orWhere('on_behalf_of', $can['on_behalf_of'])))->exists();

        if ($acted) {
            return response()->json(['message' => 'You have already acted at this level.', 'errors' => ['approval' => ['Already acted.']]], 422);
        }

        $isReject = $match[3] === 'reject';
        DB::table('approval_actions')->insert([
            'approval_request_id' => $approval->id, 'level_no' => $level->level_no, 'user_id' => $user->id, 'on_behalf_of' => $can['on_behalf_of'],
            'action' => $isReject ? 'reject' : 'approve', 'comment' => mb_substr((string) $request->input('reason', $request->input('comment', '')), 0, 500) ?: null,
            'created_at' => now(),
        ]);

        $name = trim((string) ($user->first_name.' '.$user->last_name)) ?: (string) $user->email;

        if ($isReject) {
            DB::table('approval_requests')->where('id', $approval->id)->update(['status' => 'rejected', 'decided_at' => now(), 'updated_at' => now()]);
            $this->workflows->notifyDecision($approval, 'rejected', $name);

            return $next($request);
        }

        if (! $this->workflows->levelSatisfied($companyId, $approval, $level)) {
            return response()->json(['message' => "Recorded at level {$level->level_no} of {$levels->count()}; waiting for the other approvers of this level.", 'workflow' => true, 'level' => (int) $level->level_no, 'complete' => false]);
        }

        $next_level = $levels->firstWhere('level_no', '>', $level->level_no);

        if ($next_level !== null) {
            DB::table('approval_requests')->where('id', $approval->id)->update(['current_level' => $next_level->level_no, 'updated_at' => now()]);
            $this->workflows->notifyLevel($companyId, DB::table('approval_requests')->find($approval->id), $next_level);

            return response()->json(['message' => "Level {$level->level_no} of {$levels->count()} approved; waiting for level {$next_level->level_no} ({$next_level->label}).", 'workflow' => true, 'level' => (int) $level->level_no, 'complete' => false]);
        }

        DB::table('approval_requests')->where('id', $approval->id)->update(['status' => 'approved', 'decided_at' => now(), 'updated_at' => now()]);
        $this->workflows->notifyDecision($approval, 'approved', $name);

        return $next($request);
    }
}
