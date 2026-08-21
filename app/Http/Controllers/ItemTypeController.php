<?php

namespace App\Http\Controllers;

use App\Models\ItemType;
use App\Support\ImportResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ItemTypeController extends Controller
{
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
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

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
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $itemTypes = $query->paginate($show_record);

        if ($cur_page > $itemTypes->lastPage()) {
            Paginator::currentPageResolver(function () use ($itemTypes) {
                return $itemTypes->lastPage();
            });
            $itemTypes = $query->paginate($show_record);
        }

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

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function import(Request $request)
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string|min:3|max:200',
        ]);

        DB::beginTransaction();

        try {
            $created = 0;
            $updated = 0;

            foreach ($request->rows as $index => $row) {
                if (! is_array($row)) {
                    throw ValidationException::withMessages([
                        'rows' => ['Row '.($index + 1).' is invalid.'],
                    ]);
                }

                if (ItemType::upsertFromImport($row) === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            }

            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return ImportResponse::success(
            count($request->rows),
            $created,
            $updated,
            'item type records'
        );
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

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id)
    {
        if (deletepermission()) {
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
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = ItemType::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                foreach ($ids as $itemTypeId) {
                    ItemType::deleteItemType((int) $itemTypeId);
                }

                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function bulk_delete_per(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = ItemType::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                ItemType::whereIn('id', $ids)->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request)
    {
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

                return response()->json(['errormessage' => $e]);
            }
        } else {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function restore_records(Request $request)
    {
        if (deletepermission()) {
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

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function duplicate(Request $request)
    {
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

            return response()->json(['errormessage' => $e]);
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
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

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
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $itemTypes = $query->paginate($show_record);

        if ($cur_page > $itemTypes->lastPage()) {
            Paginator::currentPageResolver(function () use ($itemTypes) {
                return $itemTypes->lastPage();
            });
            $itemTypes = $query->paginate($show_record);
        }

        return response()->json(['data' => $itemTypes]);
    }
}
