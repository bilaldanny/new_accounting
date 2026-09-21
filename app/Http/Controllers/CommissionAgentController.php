<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\CommissionAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CommissionAgentController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function commissionAgentFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'name' => 'bail|required|min:3|max:200',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'commission_percent' => 'required|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery(CommissionAgent::query(), $request)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $commissionAgents = $this->paginateSorted($query, $this->withSafeSort($request));

        $commissionAgents->getCollection()->transform(function (CommissionAgent $commissionAgent) {
            $commissionAgent->company_name = $commissionAgent->company?->name;

            return $commissionAgent;
        });

        $trash_count = CommissionAgent::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $commissionAgents, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/commissionagent/add');

        $request->validate($this->commissionAgentFormRules());

        DB::beginTransaction();
        try {
            CommissionAgent::createCommissionAgent($request);
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
        $commissionAgent = CommissionAgent::query()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->find($id);

        if ($commissionAgent === null) {
            abort(404);
        }

        return response()->json($commissionAgent);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/commissionagent/:id/edit');

        $request->validate($this->commissionAgentFormRules());

        DB::beginTransaction();
        try {
            CommissionAgent::updateCommissionAgent($request, $id);
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
        if (deletepermission('/commissionagent/delete')) {
            CommissionAgent::deleteCommissionAgent($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/commissionagent/delete', 'Successfully Deleted', function () use ($request) {
            CommissionAgent::query()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/commissionagent/delete', 'Successfully Deleted', function () use ($request) {
            CommissionAgent::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/commissionagent/restore', 'Successfully Restored', function () use ($request) {
            CommissionAgent::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->restore();
        });
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/commissionagent/:id/edit');

        $commissionAgents = CommissionAgent::query()
            ->visibleToCurrentUser()
            ->whereIn('id', (array) $request->ids)
            ->get();

        if ($commissionAgents->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($commissionAgents as $commissionAgent) {
                $commissionAgent->is_active = isset($request->status) ? (bool) $request->status : ! $commissionAgent->is_active;
                $commissionAgent->save();
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
        $query = $this->listQuery(CommissionAgent::onlyTrashed(), $request);

        $commissionAgents = $this->paginateSorted($query, $this->withSafeSort($request));

        $commissionAgents->getCollection()->transform(function (CommissionAgent $commissionAgent) {
            $commissionAgent->company_name = $commissionAgent->company?->name;

            return $commissionAgent;
        });

        return response()->json(['data' => $commissionAgents]);
    }

    /**
     * The active commission agents of the user's company, for a dropdown.
     */
    public function fetch(Request $request): JsonResponse
    {
        $commissionAgents = CommissionAgent::query()
            ->visibleToCurrentUser()
            ->where('is_active', true)
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->select('commission_agents.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($commissionAgents);
    }

    /**
     * @param  Builder<CommissionAgent>  $query
     * @return Builder<CommissionAgent>
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
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            });
    }

    /**
     * Sorting only by a real column, in a real direction, whatever the request says.
     */
    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), CommissionAgent::SORTABLE, true)) {
            $request->merge(['sort_by' => 'created_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
