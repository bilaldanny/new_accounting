<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Transporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TransporterController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function transporterFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'name' => 'bail|required|min:3|max:200',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'address' => 'nullable|string|max:500',
            'vehicle_no' => 'nullable|string|max:50',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery(Transporter::query(), $request)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $transporters = $this->paginateSorted($query, $this->withSafeSort($request));

        $transporters->getCollection()->transform(function (Transporter $transporter) {
            $transporter->company_name = $transporter->company?->name;

            return $transporter;
        });

        $trash_count = Transporter::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $transporters, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/transporter/add');

        $request->validate($this->transporterFormRules());

        DB::beginTransaction();
        try {
            Transporter::createTransporter($request);
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
        $transporter = Transporter::query()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->find($id);

        if ($transporter === null) {
            abort(404);
        }

        return response()->json($transporter);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/transporter/:id/edit');

        $request->validate($this->transporterFormRules());

        DB::beginTransaction();
        try {
            Transporter::updateTransporter($request, $id);
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
        if (deletepermission('/transporter/delete')) {
            Transporter::deleteTransporter($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/transporter/delete', 'Successfully Deleted', function () use ($request) {
            Transporter::query()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/transporter/delete', 'Successfully Deleted', function () use ($request) {
            Transporter::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/transporter/restore', 'Successfully Restored', function () use ($request) {
            Transporter::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->restore();
        });
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/transporter/:id/edit');

        $transporters = Transporter::query()
            ->visibleToCurrentUser()
            ->whereIn('id', (array) $request->ids)
            ->get();

        if ($transporters->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($transporters as $transporter) {
                $transporter->is_active = isset($request->status) ? (bool) $request->status : ! $transporter->is_active;
                $transporter->save();
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
        $query = $this->listQuery(Transporter::onlyTrashed(), $request);

        $transporters = $this->paginateSorted($query, $this->withSafeSort($request));

        $transporters->getCollection()->transform(function (Transporter $transporter) {
            $transporter->company_name = $transporter->company?->name;

            return $transporter;
        });

        return response()->json(['data' => $transporters]);
    }

    /**
     * The active transporters of the user's company, for a dropdown.
     */
    public function fetch(Request $request): JsonResponse
    {
        $transporters = Transporter::query()
            ->visibleToCurrentUser()
            ->where('is_active', true)
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->select('transporters.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($transporters);
    }

    /**
     * @param  Builder<Transporter>  $query
     * @return Builder<Transporter>
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
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('vehicle_no', 'like', "%{$search}%");
                });
            });
    }

    /**
     * Sorting only by a real column, in a real direction, whatever the request says.
     */
    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), Transporter::SORTABLE, true)) {
            $request->merge(['sort_by' => 'created_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
