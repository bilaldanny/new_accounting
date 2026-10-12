<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\ProjectProfitability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Milestones and tasks of the projects. A project already carries its budget and the revenue and cost tagged to it (Projects page,
 * ProjectProfitability); this adds the plan: milestones with a due date and the amount they bill, tasks with an assignee and hours,
 * and the progress and billing figures read from them. Reading needs `/project/work`; changing the plan `/project/work/manage`;
 * moving a task or logging hours `/project/work`. Nothing here posts to the ledger: a billed milestone is a note that an invoice was
 * made, the invoice itself tags the project the usual way.
 */
class ProjectWorkController extends Controller
{
    use ScopesToCompany;

    public const MILESTONE_STATUSES = ['pending', 'done', 'billed'];

    public const TASK_STATUSES = ['todo', 'doing', 'done'];

    public function show(Request $request, int $projectId): JsonResponse
    {
        $this->authorizeMenuPermission('/project/work');
        $companyId = $this->scopeCompanyId($request);
        $project = $this->project($companyId, $projectId);
        $milestones = DB::table('project_milestones')->where('company_id', $companyId)->where('project_id', $projectId)->orderBy('due_date')->orderBy('id')->get();
        $tasks = DB::table('project_tasks as t')->leftJoin('users as u', 'u.id', '=', 't.assignee_id')->where('t.company_id', $companyId)->where('t.project_id', $projectId)->orderBy('t.id')
            ->get(['t.id', 't.milestone_id', 't.title', 't.assignee_id', 'u.first_name', 'u.last_name', 't.due_date', 't.status', 't.estimated_hours', 't.spent_hours', 't.completed_on']);
        $money = app(ProjectProfitability::class)->build($companyId, $projectId);
        $row = $money['rows']->first();
        $done = $tasks->where('status', 'done')->count();

        return response()->json(['data' => [
            'project' => ['id' => (int) $project->id, 'code' => $project->code, 'name' => $project->name, 'status' => $project->status, 'budget_revenue' => (float) $project->budget_revenue, 'budget_cost' => (float) $project->budget_cost],
            'milestones' => $milestones->map(function (object $m) use ($tasks): array {
                $own = $tasks->where('milestone_id', $m->id);

                return (array) $m + ['tasks' => $own->count(), 'tasks_done' => $own->where('status', 'done')->count(), 'overdue' => $m->status === 'pending' && $m->due_date !== null && substr((string) $m->due_date, 0, 10) < now()->toDateString()];
            })->values(),
            'tasks' => $tasks->map(fn (object $t): array => (array) $t + ['assignee' => trim(($t->first_name ?? '').' '.($t->last_name ?? '')), 'overdue' => $t->status !== 'done' && $t->due_date !== null && substr((string) $t->due_date, 0, 10) < now()->toDateString()])->values(),
            'progress' => ['tasks' => $tasks->count(), 'done' => $done, 'percent' => $tasks->count() > 0 ? round($done / $tasks->count() * 100, 1) : 0.0,
                'estimated_hours' => round((float) $tasks->sum('estimated_hours'), 2), 'spent_hours' => round((float) $tasks->sum('spent_hours'), 2)],
            'billing' => ['planned' => round((float) $milestones->sum('amount'), 2), 'billed' => round((float) $milestones->where('status', 'billed')->sum('amount'), 2),
                'done_not_billed' => round((float) $milestones->where('status', 'done')->sum('amount'), 2), 'budget_revenue' => (float) $project->budget_revenue],
            'financials' => $row,
        ]]);
    }

    public function saveMilestone(Request $request, int $projectId, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/project/work/manage');
        $companyId = $this->scopeCompanyId($request);
        $this->project($companyId, $projectId);
        $data = $request->validate(['name' => 'required|string|max:150', 'due_date' => 'nullable|date_format:Y-m-d', 'amount' => 'nullable|numeric|min:0|max:1000000000', 'company_id' => 'nullable|integer']);
        $row = ['name' => strip_tags($data['name']), 'due_date' => $data['due_date'] ?? null, 'amount' => round((float) ($data['amount'] ?? 0), 2), 'updated_at' => now()];

        if ($id === null) {
            $id = (int) DB::table('project_milestones')->insertGetId($row + ['company_id' => $companyId, 'project_id' => $projectId, 'status' => 'pending', 'created_by' => Auth::id(), 'created_at' => now()]);
        } else {
            $this->milestone($companyId, $projectId, $id);
            DB::table('project_milestones')->where('id', $id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function milestoneStatus(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/project/work');
        $companyId = $this->scopeCompanyId($request);
        $milestone = $this->milestone($companyId, $projectId, $id);
        $data = $request->validate(['status' => ['required', Rule::in(self::MILESTONE_STATUSES)], 'billing_note' => 'nullable|string|max:200', 'company_id' => 'nullable|integer']);

        if ($data['status'] === 'billed' && $milestone->status === 'pending') {
            throw ValidationException::withMessages(['status' => ['A milestone is done before it is billed.']]);
        }

        DB::table('project_milestones')->where('id', $id)->update([
            'status' => $data['status'], 'completed_on' => $data['status'] === 'pending' ? null : ($milestone->completed_on ?? now()->toDateString()),
            'billing_note' => isset($data['billing_note']) ? strip_tags($data['billing_note']) : $milestone->billing_note, 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Milestone updated']);
    }

    public function deleteMilestone(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/project/work/manage');
        $companyId = $this->scopeCompanyId($request);
        $this->milestone($companyId, $projectId, $id);

        if (DB::table('project_tasks')->where('milestone_id', $id)->exists()) {
            throw ValidationException::withMessages(['milestone' => ['Move or delete the tasks of this milestone first.']]);
        }

        DB::table('project_milestones')->where('id', $id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function saveTask(Request $request, int $projectId, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/project/work/manage');
        $companyId = $this->scopeCompanyId($request);
        $this->project($companyId, $projectId);
        $data = $request->validate([
            'title' => 'required|string|max:150', 'milestone_id' => 'nullable|integer', 'assignee_id' => 'nullable|integer', 'due_date' => 'nullable|date_format:Y-m-d',
            'estimated_hours' => 'nullable|numeric|min:0|max:100000', 'company_id' => 'nullable|integer',
        ]);

        if (! empty($data['milestone_id'])) {
            $this->milestone($companyId, $projectId, (int) $data['milestone_id']);
        }

        if (! empty($data['assignee_id']) && ! DB::table('users')->where('company_id', $companyId)->where('id', (int) $data['assignee_id'])->exists()) {
            throw ValidationException::withMessages(['assignee_id' => ['Choose a user of this company.']]);
        }

        $row = [
            'title' => strip_tags($data['title']), 'milestone_id' => ! empty($data['milestone_id']) ? (int) $data['milestone_id'] : null, 'assignee_id' => ! empty($data['assignee_id']) ? (int) $data['assignee_id'] : null,
            'due_date' => $data['due_date'] ?? null, 'estimated_hours' => round((float) ($data['estimated_hours'] ?? 0), 2), 'updated_at' => now(),
        ];

        if ($id === null) {
            $id = (int) DB::table('project_tasks')->insertGetId($row + ['company_id' => $companyId, 'project_id' => $projectId, 'status' => 'todo', 'created_by' => Auth::id(), 'created_at' => now()]);
        } else {
            $this->task($companyId, $projectId, $id);
            DB::table('project_tasks')->where('id', $id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function taskStatus(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/project/work');
        $companyId = $this->scopeCompanyId($request);
        $task = $this->task($companyId, $projectId, $id);
        $data = $request->validate(['status' => ['nullable', Rule::in(self::TASK_STATUSES)], 'add_hours' => 'nullable|numeric|min:0|max:1000', 'company_id' => 'nullable|integer']);
        $status = $data['status'] ?? $task->status;

        DB::table('project_tasks')->where('id', $id)->update([
            'status' => $status, 'spent_hours' => round((float) $task->spent_hours + (float) ($data['add_hours'] ?? 0), 2),
            'completed_on' => $status === 'done' ? ($task->completed_on ?? now()->toDateString()) : null, 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Task updated']);
    }

    public function deleteTask(Request $request, int $projectId, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/project/work/manage');
        $companyId = $this->scopeCompanyId($request);
        $this->task($companyId, $projectId, $id);
        DB::table('project_tasks')->where('id', $id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    // ---------------------------------------------------------------- helpers

    private function project(int $companyId, int $projectId): object
    {
        $project = DB::table('projects')->where('company_id', $companyId)->where('id', $projectId)->first();
        abort_if($project === null, 404);

        return $project;
    }

    private function milestone(int $companyId, int $projectId, int $id): object
    {
        $row = DB::table('project_milestones')->where('company_id', $companyId)->where('project_id', $projectId)->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }

    private function task(int $companyId, int $projectId, int $id): object
    {
        $row = DB::table('project_tasks')->where('company_id', $companyId)->where('project_id', $projectId)->where('id', $id)->first();
        abort_if($row === null, 404);

        return $row;
    }
}
