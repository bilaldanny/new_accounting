<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OpportunityController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function opportunityFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'lead_id' => 'nullable|integer|exists:leads,id',
            'contact_id' => 'nullable|integer|exists:contacts,id',
            'name' => 'bail|required|min:3|max:200',
            'deal_value' => 'nullable|numeric|min:0',
            'expected_closing_date' => 'nullable|date',
            'pipeline_stage_id' => 'nullable|integer|exists:pipeline_stages,id',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'lost_reason' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/opportunities');
        $query = $this->listQuery(Opportunity::query(), $request)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $opportunities = $this->paginateSorted($query, $this->withSafeSort($request));

        $opportunities->getCollection()->transform(fn (Opportunity $opportunity) => $this->withDisplayFields($opportunity));

        $trash_count = Opportunity::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $opportunities, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/opportunities/add');

        $request->validate($this->opportunityFormRules());

        DB::beginTransaction();
        try {
            Opportunity::createOpportunity($request);
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
        $opportunity = Opportunity::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'lead:id,name,company_name', 'contact:id,first_name,last_name,business_name', 'pipelineStage:id,name,is_won,is_lost', 'assignee:id,first_name,last_name'])
            ->find($id);

        if ($opportunity === null) {
            abort(404);
        }

        return response()->json($opportunity);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/opportunities/:id/edit');

        $request->validate($this->opportunityFormRules());

        DB::beginTransaction();
        try {
            Opportunity::updateOpportunity($request, $id);
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
        if (deletepermission('/opportunities/delete')) {
            Opportunity::deleteOpportunity($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/opportunities/delete', 'Successfully Deleted', function () use ($request) {
            Opportunity::query()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/opportunities/delete', 'Successfully Deleted', function () use ($request) {
            Opportunity::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/opportunities/restore', 'Successfully Restored', function () use ($request) {
            Opportunity::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->restore();
        });
    }

    public function trash(Request $request): JsonResponse
    {
        $query = $this->listQuery(Opportunity::onlyTrashed(), $request);

        $opportunities = $this->paginateSorted($query, $this->withSafeSort($request));

        $opportunities->getCollection()->transform(fn (Opportunity $opportunity) => $this->withDisplayFields($opportunity));

        return response()->json(['data' => $opportunities]);
    }

    /**
     * The open opportunities of the user's company, for a dropdown (e.g. Activity's "Related
     * Opportunity" field).
     */
    public function fetch(Request $request): JsonResponse
    {
        $opportunities = Opportunity::query()
            ->visibleToCurrentUser()
            ->where('status', 'open')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->select('opportunities.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($opportunities);
    }

    /**
     * The Kanban board's data feed: every open, non-trashed opportunity (the board itself decides
     * which stage column each falls into by `pipeline_stage_id`), plus the active stages in order.
     */
    public function board(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/pipeline');

        $companyId = $request->filled('company_id')
            ? $request->integer('company_id')
            : (Auth::user()?->hasRole('superadmin') ? null : Auth::user()?->company_id);

        $stages = PipelineStage::query()
            ->visibleToCurrentUser()
            ->where('is_active', true)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $opportunities = Opportunity::query()
            ->visibleToCurrentUser()
            ->with(['assignee:id,first_name,last_name'])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Opportunity $opportunity) => $this->withDisplayFields($opportunity));

        return response()->json(['stages' => $stages, 'opportunities' => $opportunities]);
    }

    /**
     * Drag a card to a different column: moves the opportunity onto that pipeline stage, and its
     * status follows the stage's won/lost flag.
     */
    public function moveStage(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/opportunities/:id/edit');

        $request->validate([
            'pipeline_stage_id' => 'required|integer|exists:pipeline_stages,id',
        ]);

        $opportunity = Opportunity::query()->visibleToCurrentUser()->find($id);

        if ($opportunity === null) {
            abort(404);
        }

        DB::beginTransaction();
        try {
            $opportunity->moveToStage((int) $request->pipeline_stage_id);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved', 'status' => $opportunity->status]);
    }

    private function withDisplayFields(Opportunity $opportunity): Opportunity
    {
        $opportunity->company_name = $opportunity->company?->name;
        $opportunity->lead_name = $opportunity->lead?->name;
        $opportunity->assignee_name = $opportunity->assignee?->full_name;
        $opportunity->pipeline_stage_name = $opportunity->pipelineStage?->name;

        return $opportunity;
    }

    /**
     * @param  Builder<Opportunity>  $query
     * @return Builder<Opportunity>
     */
    private function listQuery($query, Request $request)
    {
        $status = $request->status ?? 'all';
        $search = trim((string) ($request->search ?? ''));

        return $query
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'lead:id,name', 'assignee:id,first_name,last_name', 'pipelineStage:id,name'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
    }

    /**
     * Sorting only by a real column, in a real direction, whatever the request says.
     */
    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), Opportunity::SORTABLE, true)) {
            $request->merge(['sort_by' => 'created_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
