<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Zones, racks, shelves and bins inside a warehouse: master data only. A location belongs to one warehouse of the
 * user's company and sits under a coarser one (zone, rack, shelf, bin).
 */
class WarehouseLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/warehouselocation');

        $locations = WarehouseLocation::query()
            ->visibleToCurrentUser()
            ->with(['warehouse:id,name,deleted_at', 'parent:id,name'])
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->input('warehouse_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->input('search').'%')->orWhere('code', 'like', '%'.$request->input('search').'%')))
            ->orderBy('warehouse_id')->orderBy('code')
            ->paginate(min((int) ($request->input('show_record') ?: 50), 300));

        $locations->getCollection()->transform(fn (WarehouseLocation $location) => $location->present());

        return response()->json(['data' => $locations]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/warehouselocation/add');

        $warehouse = $this->warehouse($request);
        $data = $this->validated($request, $warehouse);

        $location = WarehouseLocation::query()->create($data + ['company_id' => $warehouse->company_id, 'warehouse_id' => $warehouse->id]);

        return response()->json(['message' => 'Location saved', 'data' => $location->load(['warehouse:id,name', 'parent:id,name'])->present()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/warehouselocation/:id/edit');

        $location = WarehouseLocation::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $this->validated($request, $location->warehouse, $location);

        if ($location->children()->exists() && $data['type'] !== $location->type) {
            throw ValidationException::withMessages(['type' => ['This location has sub-locations, so its kind cannot change.']]);
        }

        $location->update($data);

        return response()->json(['message' => 'Location saved', 'data' => $location->refresh()->load(['warehouse:id,name', 'parent:id,name'])->present()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/warehouselocation/delete');

        $location = WarehouseLocation::query()->visibleToCurrentUser()->findOrFail($id);

        if ($location->children()->exists()) {
            throw ValidationException::withMessages(['location' => ['This location has sub-locations. Move or delete them first.']]);
        }

        $location->delete();

        return response()->json(['message' => 'Location deleted']);
    }

    /**
     * The warehouse a new location goes in: one the user can see, active.
     */
    private function warehouse(Request $request): Warehouse
    {
        $request->validate(['warehouse_id' => 'required|integer']);

        $warehouse = Warehouse::query()->visibleToCurrentUser()->find($request->integer('warehouse_id'));

        if ($warehouse === null) {
            throw ValidationException::withMessages(['warehouse_id' => ['Choose one of your warehouses.']]);
        }

        return $warehouse;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Warehouse $warehouse, ?WarehouseLocation $current = null): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(WarehouseLocation::TYPES)],
            'code' => ['required', 'string', 'max:40', Rule::unique('warehouse_locations', 'code')->where('warehouse_id', $warehouse->id)->ignore($current?->id)],
            'name' => 'required|string|max:150',
            'parent_id' => ['nullable', 'integer', Rule::exists('warehouse_locations', 'id')->where('warehouse_id', $warehouse->id)->whereNull('deleted_at')],
            'active' => 'nullable|boolean',
        ]);

        if (! empty($data['parent_id'])) {
            $parent = WarehouseLocation::query()->findOrFail($data['parent_id']);

            if ($current !== null && $this->isInside($parent, $current->id)) {
                throw ValidationException::withMessages(['parent_id' => ['A location cannot sit under itself or one of its own sub-locations.']]);
            }

            if (! WarehouseLocation::fitsUnder($data['type'], $parent->type)) {
                throw ValidationException::withMessages(['type' => ['A '.$data['type'].' cannot sit under a '.$parent->type.': a zone holds racks, a rack holds shelves, a shelf holds bins.']]);
            }
        }

        return $data + ['parent_id' => null, 'active' => true];
    }

    private function isInside(WarehouseLocation $location, int $id): bool
    {
        $node = $location;

        for ($depth = 0; $depth < 20 && $node !== null; $depth++) {
            if ((int) $node->id === $id) {
                return true;
            }

            $node = $node->parent_id ? WarehouseLocation::query()->find($node->parent_id) : null;
        }

        return false;
    }
}
