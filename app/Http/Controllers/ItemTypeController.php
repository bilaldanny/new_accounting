<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesBulkImport;
use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\ItemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ItemTypeController extends Controller
{
    use HandlesBulkImport, HandlesIndexAndBulkDelete;

    /**
     * @return array<string, string>
     */
    protected function itemTypeFormRules(): array
    {
        return [
            'name' => 'bail|required|string|min:3|max:200',
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
        ];
    }

    public function index(Request $request)
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = ItemType::query()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $itemTypes = $this->paginateSorted($query, $request);

        $itemTypes->getCollection()->transform(function (ItemType $itemType) {
            $itemType->company_name = $itemType->company?->name;

            return $itemType;
        });

        $trash_count = ItemType::onlyTrashed()->count();

        return response()->json(['data' => $itemTypes, 'trash_count' => $trash_count]);
    }

    public function checkName(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'except_id' => 'nullable|integer',
            'company_id' => 'nullable',
        ]);

        return response()->json([
            'name_taken' => ItemType::nameExists(
                $request->string('name')->toString(),
                $request->integer('except_id') ?: null,
                ItemType::resolveScopedId($request->company_id),
            ),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeMenuPermission('/itemtype/add');

        $request->validate($this->itemTypeFormRules());

        DB::beginTransaction();
        try {
            ItemType::createItemType($request);
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

    public function import(Request $request)
    {
        $this->authorizeMenuPermission('/itemtype/import');

        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string|min:3|max:200',
        ]);

        return $this->importRows($request, ItemType::class, 'item type records');
    }

    public function show($id)
    {
        $itemType = ItemType::findVisibleToCurrentUser((int) $id);

        if ($itemType === null) {
            abort(404);
        }

        return response()->json($itemType);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeMenuPermission('/itemtype/:id/edit');

        $request->validate($this->itemTypeFormRules());

        DB::beginTransaction();
        try {
            ItemType::updateItemType($request, (int) $id);
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

    public function destroy($id)
    {
        if (deletepermission('/itemtype/delete')) {
            $itemType = ItemType::findVisibleToCurrentUser((int) $id);

            if ($itemType === null) {
                abort(404);
            }

            ItemType::deleteItemType((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        return $this->guardedBulkAction('/itemtype/delete', 'Successfully Deleted', function () use ($request) {
            $ids = ItemType::query()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->pluck('id');

            foreach ($ids as $itemTypeId) {
                ItemType::deleteItemType((int) $itemTypeId);
            }
        });
    }

    public function bulk_delete_per(Request $request)
    {
        return $this->guardedBulkAction('/itemtype/delete', 'Successfully Deleted', function () use ($request) {
            $ids = ItemType::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->pluck('id');

            ItemType::whereIn('id', $ids)->forceDelete();
        });
    }

    public function updatestatus(Request $request)
    {
        $this->authorizeMenuPermission('/itemtype/:id/edit');
        $itemTypes = ItemType::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if (isset($itemTypes)) {
            DB::beginTransaction();
            try {
                foreach ($itemTypes as $itemType) {
                    if (isset($request->status)) {
                        $itemType->active = $request->status;
                    } else {
                        if ($itemType->active == false) {
                            $itemType->active = 'true';
                        } else {
                            $itemType->active = 'false';
                        }
                    }
                    $itemType->save();
                }
                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        } else {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function restore_records(Request $request)
    {
        if (deletepermission('/itemtype/restore')) {
            DB::beginTransaction();
            try {
                $ids = ItemType::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                ItemType::whereIn('id', $ids)->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function duplicate(Request $request)
    {
        $this->authorizeMenuPermission('/itemtype/add');

        DB::beginTransaction();
        try {
            $itemType = ItemType::findVisibleToCurrentUser((int) $request->id);

            if ($itemType === null) {
                abort(404);
            }

            $duplicator = $itemType->replicate();
            $duplicator->name = $this->duplicateItemTypeName($itemType->name, $itemType->company_id);
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }
    }

    private function duplicateItemTypeName(string $name, mixed $companyId = null): string
    {
        $companyId = ItemType::resolveScopedId($companyId);
        $candidate = $name.' Copy';
        $suffix = 1;

        while (ItemType::nameExists($candidate, null, $companyId)) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
    }

    public function fetch(Request $request)
    {
        $itemTypes = ItemType::query()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->select('item_types.*', 'name as text')
            ->get();

        return response()->json($itemTypes);
    }

    public function trash(Request $request)
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = ItemType::onlyTrashed()
            ->visibleToCurrentUser()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%");
                });
            });

        $itemTypes = $this->paginateSorted($query, $request);

        return response()->json(['data' => $itemTypes]);
    }
}
