<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesBulkImport;
use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class LeadController extends Controller
{
    use HandlesBulkImport, HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function leadFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'name' => 'bail|required|min:3|max:200',
            'company_name' => 'nullable|string|max:200',
            'email' => 'nullable|email|max:200',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'source' => 'nullable|string|max:100',
            'lead_source_id' => 'nullable|integer|exists:lead_sources,id',
            'status' => 'nullable|string|in:'.implode(',', Lead::STATUSES),
            'assigned_to' => 'nullable|integer|exists:users,id',
            'notes' => 'nullable|string|max:2000',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/leads');
        $query = $this->listQuery(Lead::query(), $request)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $leads = $this->paginateSorted($query, $this->withSafeSort($request));

        $leads->getCollection()->transform(function (Lead $lead) {
            $lead->company_name_display = $lead->company?->name;
            $lead->assignee_name = $lead->assignee?->full_name;
            $lead->lead_source_name = $lead->leadSource?->name ?? $lead->source;

            return $lead;
        });

        $trash_count = Lead::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $leads, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/leads/add');

        $request->validate($this->leadFormRules());

        DB::beginTransaction();
        try {
            Lead::createLead($request);
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

    public function import(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/leads/import');

        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string',
        ]);

        return $this->importRows($request, Lead::class, 'lead records');
    }

    public function show($id): JsonResponse
    {
        $lead = Lead::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'assignee:id,first_name,last_name', 'leadSource:id,name', 'convertedContact:id,first_name,last_name,business_name'])
            ->find($id);

        if ($lead === null) {
            abort(404);
        }

        return response()->json($lead);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/leads/:id/edit');

        $request->validate($this->leadFormRules());

        DB::beginTransaction();
        try {
            Lead::updateLead($request, $id);
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
        if (deletepermission('/leads/delete')) {
            Lead::deleteLead($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/leads/delete', 'Successfully Deleted', function () use ($request) {
            Lead::query()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/leads/delete', 'Successfully Deleted', function () use ($request) {
            Lead::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/leads/restore', 'Successfully Restored', function () use ($request) {
            Lead::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->restore();
        });
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/leads/:id/edit');

        $status = $request->status;

        if (! in_array($status, Lead::STATUSES, true)) {
            return response()->json(['errormessage' => 'Invalid status'], 422);
        }

        $leads = Lead::query()
            ->visibleToCurrentUser()
            ->whereIn('id', (array) $request->ids)
            ->get();

        if ($leads->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($leads as $lead) {
                $lead->status = $status;
                $lead->save();
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
        $query = $this->listQuery(Lead::onlyTrashed(), $request);

        $leads = $this->paginateSorted($query, $this->withSafeSort($request));

        $leads->getCollection()->transform(function (Lead $lead) {
            $lead->company_name_display = $lead->company?->name;
            $lead->assignee_name = $lead->assignee?->full_name;
            $lead->lead_source_name = $lead->leadSource?->name ?? $lead->source;

            return $lead;
        });

        return response()->json(['data' => $leads]);
    }

    /**
     * The leads of the user's company not yet converted, for a dropdown (e.g. Opportunity's
     * "Create from Lead" field).
     */
    public function fetch(Request $request): JsonResponse
    {
        $leads = Lead::query()
            ->visibleToCurrentUser()
            ->where('status', '!=', 'converted')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->select('leads.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($leads);
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function listQuery($query, Request $request)
    {
        $status = $request->status ?? 'all';
        $search = trim((string) ($request->search ?? ''));

        return $query
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'assignee:id,first_name,last_name', 'leadSource:id,name'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            });
    }

    /**
     * Sorting only by a real column, in a real direction, whatever the request says.
     */
    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), Lead::SORTABLE, true)) {
            $request->merge(['sort_by' => 'created_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
