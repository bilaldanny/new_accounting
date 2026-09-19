<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class WarehouseController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function warehouseFormRules(): array
    {
        return [
            'branch_id' => 'bail|required',
            'name' => 'bail|required|min:3|max:200',
            'zipcode' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:50',
            'fax' => 'nullable|string|max:50',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = Warehouse::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'country:id,name', 'state:id,name', 'city:id,name'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'phone', 'address'], 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            });

        $warehouses = $this->paginateSorted($query, $request);

        $warehouses->getCollection()->transform(function (Warehouse $warehouse) {
            $warehouse->company_name = $warehouse->company?->name;
            $warehouse->branch_name = $warehouse->branch?->name;
            $warehouse->country_name = $warehouse->country?->name;
            $warehouse->state_name = $warehouse->state?->name;
            $warehouse->city_name = $warehouse->city?->name;

            return $warehouse;
        });

        $trash_count = Warehouse::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $warehouses, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/warehouse/add');

        $request->validate($this->warehouseFormRules());

        DB::beginTransaction();
        try {
            Warehouse::createWarehouse($request);
            DB::commit();
        } catch (ValidationException $e) {
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
        $warehouse = Warehouse::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name'])
            ->find($id);

        if ($warehouse === null) {
            abort(404);
        }

        return response()->json($warehouse);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/warehouse/:id/edit');

        $request->validate($this->warehouseFormRules());

        DB::beginTransaction();
        try {
            Warehouse::updateWarehouse($request, $id);
            DB::commit();
        } catch (ValidationException $e) {
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
        if (deletepermission('/warehouse/delete')) {
            Warehouse::deleteWarehouse($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/warehouse/delete', 'Successfully Deleted', function () use ($request) {
            Warehouse::query()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/warehouse/delete', 'Successfully Deleted', function () use ($request) {
            Warehouse::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission('/warehouse/restore')) {
            DB::beginTransaction();
            try {
                Warehouse::onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/warehouse/:id/edit');

        $warehouses = Warehouse::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if ($warehouses->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($warehouses as $warehouse) {
                if (isset($request->status)) {
                    $warehouse->is_active = $request->status;
                } else {
                    $warehouse->is_active = ! $warehouse->is_active;
                }
                $warehouse->save();
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
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = Warehouse::onlyTrashed()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'phone', 'address'], 'like', "%{$search}%");
                });
            });

        $warehouses = $this->paginateSorted($query, $request);

        $warehouses->getCollection()->transform(function (Warehouse $warehouse) {
            $warehouse->company_name = $warehouse->company?->name;
            $warehouse->branch_name = $warehouse->branch?->name;

            return $warehouse;
        });

        return response()->json(['data' => $warehouses]);
    }

    public function fetch(Request $request): JsonResponse
    {
        $warehouses = Warehouse::query()
            ->visibleToCurrentUser()
            ->where('is_active', true)
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->select('warehouses.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($warehouses);
    }
}
