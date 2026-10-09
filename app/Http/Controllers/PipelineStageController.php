<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PipelineStageController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function pipelineStageFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'name' => 'bail|required|min:2|max:100',
            'sort_order' => 'nullable|integer|min:0',
            'color' => 'nullable|string|max:20',
            'is_won' => 'nullable|boolean',
            'is_lost' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/pipelinestages');
        $query = $this->listQuery(PipelineStage::query(), $request)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $stages = $this->paginateSorted($query, $this->withSafeSort($request));

        $stages->getCollection()->transform(function (PipelineStage $stage) {
            $stage->company_name = $stage->company?->name;

            return $stage;
        });

        $trash_count = PipelineStage::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $stages, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/pipelinestages/add');

        $request->validate($this->pipelineStageFormRules());

        DB::beginTransaction();
        try {
            PipelineStage::createPipelineStage($request);
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
        $stage = PipelineStage::query()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->find($id);

        if ($stage === null) {
            abort(404);
        }

        return response()->json($stage);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/pipelinestages/:id/edit');

        $request->validate($this->pipelineStageFormRules());

        DB::beginTransaction();
        try {
            PipelineStage::updatePipelineStage($request, $id);
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
        if (deletepermission('/pipelinestages/delete')) {
            PipelineStage::deletePipelineStage($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/pipelinestages/delete', 'Successfully Deleted', function () use ($request) {
            PipelineStage::query()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/pipelinestages/delete', 'Successfully Deleted', function () use ($request) {
            PipelineStage::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/pipelinestages/restore', 'Successfully Restored', function () use ($request) {
            PipelineStage::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->restore();
        });
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/pipelinestages/:id/edit');

        $stages = PipelineStage::query()
            ->visibleToCurrentUser()
            ->whereIn('id', (array) $request->ids)
            ->get();

        if ($stages->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($stages as $stage) {
                $stage->is_active = isset($request->status) ? (bool) $request->status : ! $stage->is_active;
                $stage->save();
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function trash(Request $request): JsonResponse
    {
        $query = $this->listQuery(PipelineStage::onlyTrashed(), $request);

        $stages = $this->paginateSorted($query, $this->withSafeSort($request));

        $stages->getCollection()->transform(function (PipelineStage $stage) {
            $stage->company_name = $stage->company?->name;

            return $stage;
        });

        return response()->json(['data' => $stages]);
    }

    /**
     * The active pipeline stages of the user's company, ordered for the Kanban board columns.
     */
    public function fetch(Request $request): JsonResponse
    {
        $stages = PipelineStage::query()
            ->visibleToCurrentUser()
            ->where('is_active', true)
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->select('pipeline_stages.*')
            ->selectRaw('name as text')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json($stages);
    }

    /**
     * @param  Builder<PipelineStage>  $query
     * @return Builder<PipelineStage>
     */
    private function listQuery($query, Request $request)
    {
        $status = $request->status ?? 'all';
        $search = trim((string) ($request->search ?? ''));

        return $query
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
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
        if (! in_array($request->input('sort_by'), PipelineStage::SORTABLE, true)) {
            $request->merge(['sort_by' => 'sort_order']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'asc']);
        }

        return $request;
    }
}
