<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\LeadSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class LeadSourceController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function leadSourceFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'name' => 'bail|required|min:2|max:100',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/leadsources');
        $query = $this->listQuery(LeadSource::query(), $request)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $leadSources = $this->paginateSorted($query, $this->withSafeSort($request));

        $leadSources->getCollection()->transform(function (LeadSource $leadSource) {
            $leadSource->company_name = $leadSource->company?->name;

            return $leadSource;
        });

        $trash_count = LeadSource::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $leadSources, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/leadsources/add');

        $request->validate($this->leadSourceFormRules());

        DB::beginTransaction();
        try {
            LeadSource::createLeadSource($request);
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
        $leadSource = LeadSource::query()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->find($id);

        if ($leadSource === null) {
            abort(404);
        }

        return response()->json($leadSource);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/leadsources/:id/edit');

        $request->validate($this->leadSourceFormRules());

        DB::beginTransaction();
        try {
            LeadSource::updateLeadSource($request, $id);
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
        if (deletepermission('/leadsources/delete')) {
            LeadSource::deleteLeadSource($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/leadsources/delete', 'Successfully Deleted', function () use ($request) {
            LeadSource::query()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/leadsources/delete', 'Successfully Deleted', function () use ($request) {
            LeadSource::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/leadsources/restore', 'Successfully Restored', function () use ($request) {
            LeadSource::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->restore();
        });
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/leadsources/:id/edit');

        $leadSources = LeadSource::query()
            ->visibleToCurrentUser()
            ->whereIn('id', (array) $request->ids)
            ->get();

        if ($leadSources->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($leadSources as $leadSource) {
                $leadSource->is_active = isset($request->status) ? (bool) $request->status : ! $leadSource->is_active;
                $leadSource->save();
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
        $query = $this->listQuery(LeadSource::onlyTrashed(), $request);

        $leadSources = $this->paginateSorted($query, $this->withSafeSort($request));

        $leadSources->getCollection()->transform(function (LeadSource $leadSource) {
            $leadSource->company_name = $leadSource->company?->name;

            return $leadSource;
        });

        return response()->json(['data' => $leadSources]);
    }

    /**
     * The active lead sources of the user's company, for a dropdown.
     */
    public function fetch(Request $request): JsonResponse
    {
        $leadSources = LeadSource::query()
            ->visibleToCurrentUser()
            ->where('is_active', true)
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->select('lead_sources.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($leadSources);
    }

    /**
     * @param  Builder<LeadSource>  $query
     * @return Builder<LeadSource>
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
        if (! in_array($request->input('sort_by'), LeadSource::SORTABLE, true)) {
            $request->merge(['sort_by' => 'created_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
