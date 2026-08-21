<?php

namespace App\Http\Controllers;

use App\Models\Variation;
use App\Support\ImportResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class VariationController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    protected function variationFormRules(): array
    {
        return [
            'category_id' => 'bail|required',
            'itemtype_id' => 'bail|required',
            'subcategory_id' => 'nullable',
            'values' => 'nullable|array',
            'values.*.name' => 'nullable|string|max:200',
            'values.*.active' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0',
            'active' => 'nullable|boolean',
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
        ];
    }

    protected function normalizeVariationRequest(Request $request): void
    {
        if ($request->has('active')) {
            $request->merge([
                'active' => Variation::normalizeRequestBool($request->input('active')),
            ]);
        }

        if (is_array($request->input('values'))) {
            $request->merge([
                'values' => collect($request->input('values'))
                    ->map(function ($value) {
                        if (! is_array($value)) {
                            return $value;
                        }

                        if (array_key_exists('active', $value)) {
                            $value['active'] = Variation::normalizeRequestBool($value['active']);
                        }

                        return $value;
                    })
                    ->all(),
            ]);
        }
    }

    public function index(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Variation::query()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'category:id,name',
                'subcategory:id,name',
                'itemtype:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('values', 'like', "%{$search}%")
                        ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('subcategory', fn ($subcategoryQuery) => $subcategoryQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('itemtype', fn ($itemtypeQuery) => $itemtypeQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('category_id'), function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            })
            ->when($request->filled('subcategory_id'), function ($q) use ($request) {
                $q->where('subcategory_id', $request->subcategory_id);
            })
            ->when($request->filled('itemtype_id'), function ($q) use ($request) {
                $q->where('itemtype_id', $request->itemtype_id);
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $variations = $query->paginate($show_record);

        if ($cur_page > $variations->lastPage()) {
            Paginator::currentPageResolver(function () use ($variations) {
                return $variations->lastPage();
            });
            $variations = $query->paginate($show_record);
        }

        $variations->getCollection()->transform(function (Variation $variation) {
            $categoryName = $variation->category?->name;
            $subcategoryName = $variation->subcategory?->name;

            $variation->company_name = $variation->company?->name;
            $variation->category_name = $subcategoryName
                ? trim($categoryName.' / '.$subcategoryName, ' /')
                : $categoryName;
            $variation->itemtype_name = $variation->itemtype?->name;
            $variation->values_display = Variation::valuesDisplay($variation->values);

            return $variation;
        });

        $trash_count = Variation::onlyTrashed()->count();

        return response()->json(['data' => $variations, 'trash_count' => $trash_count]);
    }

    public function store(Request $request)
    {
        $this->normalizeVariationRequest($request);
        $request->validate($this->variationFormRules());

        DB::beginTransaction();
        try {
            Variation::createVariation($request);
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
            'rows.*.category' => 'nullable',
            'rows.*.category_id' => 'nullable',
            'rows.*.subcategory' => 'nullable',
            'rows.*.subcategory_id' => 'nullable',
            'rows.*.item_type' => 'nullable',
            'rows.*.itemtype' => 'nullable',
            'rows.*.itemtype_id' => 'nullable',
            'rows.*.values' => 'bail|required',
            'rows.*.priority' => 'nullable|integer|min:0',
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

                if (Variation::upsertFromImport($row) === 'created') {
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
            'variation records'
        );
    }

    public function show($id)
    {
        $variation = Variation::findVisibleToCurrentUser((int) $id);

        if ($variation === null) {
            abort(404);
        }

        if ($variation->values === null || $variation->values === []) {
            $variation->values = [
                ['name' => '', 'active' => true],
            ];
        }

        return response()->json($variation);
    }

    public function update(Request $request, $id)
    {
        $this->normalizeVariationRequest($request);
        $request->validate($this->variationFormRules());

        DB::beginTransaction();
        try {
            Variation::updateVariation($request, (int) $id);
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
            $variation = Variation::findVisibleToCurrentUser((int) $id);

            if ($variation === null) {
                abort(404);
            }

            Variation::deleteVariation((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Variation::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                foreach ($ids as $variationId) {
                    Variation::deleteVariation((int) $variationId);
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
                $ids = Variation::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                Variation::whereIn('id', $ids)->forceDelete();
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
        $variations = Variation::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if (isset($variations)) {
            DB::beginTransaction();
            try {
                foreach ($variations as $variation) {
                    if (isset($request->status)) {
                        $variation->active = $request->status;
                    } else {
                        if ($variation->active == false) {
                            $variation->active = 'true';
                        } else {
                            $variation->active = 'false';
                        }
                    }
                    $variation->save();
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
                $ids = Variation::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Variation::whereIn('id', $ids)->restore();
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
            $variation = Variation::findVisibleToCurrentUser((int) $request->id);

            if ($variation === null) {
                abort(404);
            }

            $duplicator = $variation->replicate();
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    public function fetch(Request $request)
    {
        $variations = Variation::query()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('category_id'), function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            })
            ->when($request->filled('subcategory_id'), function ($q) use ($request) {
                $q->where('subcategory_id', $request->subcategory_id);
            })
            ->when($request->filled('itemtype_id'), function ($q) use ($request) {
                $q->where('itemtype_id', $request->itemtype_id);
            })
            ->get();

        return response()->json($variations);
    }

    public function trash(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Variation::onlyTrashed()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'category:id,name',
                'subcategory:id,name',
                'itemtype:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('values', 'like', "%{$search}%")
                        ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('itemtype', fn ($itemtypeQuery) => $itemtypeQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $variations = $query->paginate($show_record);

        if ($cur_page > $variations->lastPage()) {
            Paginator::currentPageResolver(function () use ($variations) {
                return $variations->lastPage();
            });
            $variations = $query->paginate($show_record);
        }

        $variations->getCollection()->transform(function (Variation $variation) {
            $categoryName = $variation->category?->name;
            $subcategoryName = $variation->subcategory?->name;

            $variation->company_name = $variation->company?->name;
            $variation->category_name = $subcategoryName
                ? trim($categoryName.' / '.$subcategoryName, ' /')
                : $categoryName;
            $variation->itemtype_name = $variation->itemtype?->name;
            $variation->values_display = Variation::valuesDisplay($variation->values);

            return $variation;
        });

        return response()->json(['data' => $variations]);
    }
}
