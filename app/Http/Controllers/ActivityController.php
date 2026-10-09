<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ActivityController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function activityFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'lead_id' => 'nullable|integer|exists:leads,id',
            'opportunity_id' => 'nullable|integer|exists:opportunities,id',
            'contact_id' => 'nullable|integer|exists:contacts,id',
            'type' => 'nullable|string|in:'.implode(',', Activity::TYPES),
            'subject' => 'bail|required|min:2|max:200',
            'description' => 'nullable|string|max:2000',
            'due_at' => 'nullable|date',
            'assigned_to' => 'nullable|integer|exists:users,id',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/activities');
        $query = $this->listQuery(Activity::query(), $request)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $activities = $this->paginateSorted($query, $this->withSafeSort($request));

        $activities->getCollection()->transform(fn (Activity $activity) => $this->withDisplayFields($activity));

        $trash_count = Activity::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $activities, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/activities/add');

        $request->validate($this->activityFormRules());

        DB::beginTransaction();
        try {
            Activity::createActivity($request);
            DB::commit();
        } catch (ValidationException|ModelNotFoundException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show($id): JsonResponse
    {
        $activity = Activity::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'lead:id,name', 'opportunity:id,name', 'contact:id,first_name,last_name,business_name', 'assignee:id,first_name,last_name', 'creator:id,first_name,last_name'])
            ->find($id);

        if ($activity === null) {
            abort(404);
        }

        return response()->json($activity);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/activities/:id/edit');

        $request->validate($this->activityFormRules());

        DB::beginTransaction();
        try {
            Activity::updateActivity($request, $id);
            DB::commit();
        } catch (ValidationException|ModelNotFoundException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id): JsonResponse
    {
        if (deletepermission('/activities/delete')) {
            Activity::deleteActivity($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/activities/delete', 'Successfully Deleted', function () use ($request) {
            Activity::query()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/activities/delete', 'Successfully Deleted', function () use ($request) {
            Activity::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/activities/restore', 'Successfully Restored', function () use ($request) {
            Activity::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->restore();
        });
    }

    public function trash(Request $request): JsonResponse
    {
        $query = $this->listQuery(Activity::onlyTrashed(), $request);

        $activities = $this->paginateSorted($query, $this->withSafeSort($request));

        $activities->getCollection()->transform(fn (Activity $activity) => $this->withDisplayFields($activity));

        return response()->json(['data' => $activities]);
    }

    /**
     * Mark an activity done, or reopen it if it already was (the list/detail page's single toggle
     * button).
     */
    public function complete($id): JsonResponse
    {
        $this->authorizeMenuPermission('/activities/:id/complete');

        $activity = Activity::query()->visibleToCurrentUser()->find($id);

        if ($activity === null) {
            abort(404);
        }

        $activity->toggleComplete();

        return response()->json(['message' => 'Successfully Saved', 'completed_at' => $activity->completed_at]);
    }

    /**
     * The activities attached to one Lead or one Opportunity or one Contact, newest first — the
     * timeline widget on their detail pages (Step 4/5's "Built" bar: a real timeline of real rows).
     */
    public function timeline(Request $request): JsonResponse
    {
        $request->validate([
            'lead_id' => 'nullable|integer',
            'opportunity_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
        ]);

        $activities = Activity::query()
            ->visibleToCurrentUser()
            ->with(['assignee:id,first_name,last_name', 'creator:id,first_name,last_name'])
            ->when($request->filled('lead_id'), fn ($q) => $q->where('lead_id', $request->integer('lead_id')))
            ->when($request->filled('opportunity_id'), fn ($q) => $q->where('opportunity_id', $request->integer('opportunity_id')))
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->integer('contact_id')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Activity $activity) => $this->withDisplayFields($activity));

        return response()->json(['data' => $activities]);
    }

    private function withDisplayFields(Activity $activity): Activity
    {
        $activity->assignee_name = $activity->assignee?->full_name;
        $activity->creator_name = $activity->creator?->full_name;
        $activity->lead_name = $activity->lead?->name;
        $activity->opportunity_name = $activity->opportunity?->name;
        $activity->is_completed = $activity->completed_at !== null;

        return $activity;
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function listQuery($query, Request $request)
    {
        $status = $request->status ?? 'all';
        $search = trim((string) ($request->search ?? ''));

        return $query
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'lead:id,name', 'opportunity:id,name', 'assignee:id,first_name,last_name'])
            ->when($status === 'completed', fn ($q) => $q->whereNotNull('completed_at'))
            ->when($status === 'pending', fn ($q) => $q->whereNull('completed_at'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($search !== '', function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%");
            });
    }

    /**
     * Sorting only by a real column, in a real direction, whatever the request says.
     */
    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), Activity::SORTABLE, true)) {
            $request->merge(['sort_by' => 'due_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'asc']);
        }

        return $request;
    }
}
