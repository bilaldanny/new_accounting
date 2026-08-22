<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\ImportResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    protected function productFormRules(): array
    {
        return [
            'name' => 'bail|required|string|min:2|max:200',
            'unit_id' => 'bail|required',
            'brand_id' => 'bail|required',
            'category_id' => 'bail|required',
            'itemtype_id' => 'bail|required',
            'type' => 'bail|required|in:single,variable',
            'subcategory_id' => 'nullable',
            'warranty_id' => 'nullable',
            'alert_qty' => 'nullable|numeric|min:0',
            'weight' => 'nullable|numeric|min:0',
            'sku' => 'nullable|string|max:100',
            'product_desc' => 'nullable|string',
            'product_image' => 'nullable',
            'productdetail' => 'bail|required|array|min:1',
            'productdetail.*.variation_name' => 'bail|required|string|max:200',
            'productdetail.*.default_purchase_price' => 'nullable|numeric|min:0',
            'productdetail.*.profit_percent' => 'nullable|numeric|min:0',
            'productdetail.*.default_sell_price' => 'nullable|numeric|min:0',
            'productdetail.*.largequantity' => 'nullable|numeric|min:0',
            'productdetail.*.smallquantity' => 'nullable|numeric|min:0',
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

        $query = Product::query()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'category:id,name',
                'subcategory:id,name',
                'itemtype:id,name',
                'brand:id,name',
                'unit:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'sku'], 'like', "%{$search}%");
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
            ->when($request->filled('brand_id'), function ($q) use ($request) {
                $q->where('brand_id', $request->brand_id);
            })
            ->when($request->filled('type') && $request->type !== 'all', function ($q) use ($request) {
                $q->where('type', $request->type);
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $products = $query->paginate($show_record);

        if ($cur_page > $products->lastPage()) {
            Paginator::currentPageResolver(function () use ($products) {
                return $products->lastPage();
            });
            $products = $query->paginate($show_record);
        }

        $products->getCollection()->transform(function (Product $product) {
            $product->company_name = $product->company?->name;
            $product->category_name = $product->category?->name;
            $product->subcategory_name = $product->subcategory?->name;
            $product->itemtype_name = $product->itemtype?->name;
            $product->brand_name = $product->brand?->name;
            $product->unit_name = $product->unit?->name;
            $product->category_label = trim(implode(' / ', array_filter([
                $product->category_name,
                $product->subcategory_name,
            ]))) ?: null;
            $product->product_image_url = Product::imageUrl($product->product_image);

            return $product;
        });

        $trash_count = Product::onlyTrashed()->count();

        return response()->json(['data' => $products, 'trash_count' => $trash_count]);
    }

    public function checkName(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'except_id' => 'nullable|integer',
            'company_id' => 'nullable',
        ]);

        return response()->json([
            'name_taken' => Product::nameExists(
                $request->string('name')->toString(),
                $request->integer('except_id') ?: null,
                Product::resolveScopedId($request->company_id),
            ),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate($this->productFormRules());

        DB::beginTransaction();
        try {
            Product::createProduct($request);
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
            'rows.*.name' => 'bail|required|string|min:2|max:200',
            'rows.*.unit_id' => 'nullable',
            'rows.*.unit' => 'nullable',
            'rows.*.brand_id' => 'nullable',
            'rows.*.brand' => 'nullable',
            'rows.*.category_id' => 'nullable',
            'rows.*.category' => 'nullable',
            'rows.*.itemtype_id' => 'nullable',
            'rows.*.item_type' => 'nullable',
            'rows.*.itemtype' => 'nullable',
            'rows.*.type' => 'nullable|in:single,variable',
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

                if (Product::upsertFromImport($row) === 'created') {
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
            'product records'
        );
    }

    public function show($id)
    {
        $product = Product::query()
            ->visibleToCurrentUser()
            ->with('productdetail')
            ->find((int) $id);

        if ($product === null) {
            abort(404);
        }

        $product->product_image_url = Product::imageUrl($product->product_image);

        return response()->json($product);
    }

    public function update(Request $request, $id)
    {
        $request->validate($this->productFormRules());

        DB::beginTransaction();
        try {
            Product::updateProduct($request, (int) $id);
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
            $product = Product::findVisibleToCurrentUser((int) $id);

            if ($product === null) {
                abort(404);
            }

            Product::deleteProduct((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Product::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                foreach ($ids as $productId) {
                    Product::deleteProduct((int) $productId);
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
                $ids = Product::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                Product::whereIn('id', $ids)->forceDelete();
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
        $products = Product::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if (isset($products)) {
            DB::beginTransaction();
            try {
                foreach ($products as $product) {
                    if (isset($request->status)) {
                        $product->active = $request->status;
                    } else {
                        if ($product->active == false) {
                            $product->active = 'true';
                        } else {
                            $product->active = 'false';
                        }
                    }
                    $product->save();
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
                $ids = Product::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Product::whereIn('id', $ids)->restore();
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
            $product = Product::query()
                ->visibleToCurrentUser()
                ->with('productdetail')
                ->find((int) $request->id);

            if ($product === null) {
                abort(404);
            }

            $duplicator = $product->replicate();
            $duplicator->name = $this->duplicateProductName($product->name, $product->company_id);
            $duplicator->sku = Product::generateSku(Product::resolveScopedId($product->company_id));
            $duplicator->save();

            foreach ($product->productdetail as $index => $detail) {
                $cloned = $detail->replicate();
                $cloned->product_id = $duplicator->id;
                $cloned->name = $duplicator->name.' '.$detail->variation_name;
                $cloned->sku = Product::variationSku($duplicator->sku, $index + 1);
                $cloned->save();
            }

            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    private function duplicateProductName(string $name, mixed $companyId = null): string
    {
        $companyId = Product::resolveScopedId($companyId);
        $candidate = $name.' Copy';
        $suffix = 1;

        while (Product::nameExists($candidate, null, $companyId)) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
    }

    public function fetch(Request $request)
    {
        $products = Product::query()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->select('products.*', 'name as text')
            ->get();

        return response()->json($products);
    }

    public function trash(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Product::onlyTrashed()
            ->visibleToCurrentUser()
            ->with([
                'category:id,name',
                'subcategory:id,name',
                'itemtype:id,name',
                'brand:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['name', 'sku'], 'like', "%{$search}%");
                });
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $products = $query->paginate($show_record);

        if ($cur_page > $products->lastPage()) {
            Paginator::currentPageResolver(function () use ($products) {
                return $products->lastPage();
            });
            $products = $query->paginate($show_record);
        }

        $products->getCollection()->transform(function (Product $product) {
            $product->category_label = trim(implode(' / ', array_filter([
                $product->category?->name,
                $product->subcategory?->name,
            ]))) ?: null;
            $product->itemtype_name = $product->itemtype?->name;
            $product->brand_name = $product->brand?->name;

            return $product;
        });

        return response()->json(['data' => $products]);
    }
}
