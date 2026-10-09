<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\PriceList;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PriceListController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function priceListFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'bail|required',
            'brand_id' => 'bail|nullable|integer|exists:brands,id|required_without:contact_id',
            'contact_id' => 'bail|nullable|integer|exists:contacts,id|required_without:brand_id',
            'date' => 'nullable|date',
            'discount' => 'nullable|numeric|min:0',
            'status' => ['nullable', Rule::in(PriceList::STATUSES)],
            'pricelistdetails' => 'bail|required|array|min:1',
            'pricelistdetails.*.product_id' => 'bail|required|integer|exists:products,id',
            'pricelistdetails.*.unit_id' => 'nullable|integer',
            'pricelistdetails.*.purchase_price' => 'nullable|numeric|min:0',
            'pricelistdetails.*.sell_price' => 'nullable|numeric|min:0',
            'pricelistdetails.*.profit_margin' => 'nullable|numeric',
            'pricelistdetails.*.discount' => 'nullable|numeric|min:0',
            'pricelistdetails.*.tiers' => 'nullable|array|max:20',
            'pricelistdetails.*.tiers.*.min_qty' => 'required|numeric|gt:0',
            'pricelistdetails.*.tiers.*.sell_price' => 'required|numeric|min:0',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/pricelist');
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = PriceList::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'brand:id,name', 'contact:id,first_name,last_name,business_name'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->whereHas('brand', function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->when($request->filled('brand_id'), function ($q) use ($request) {
                $q->where('brand_id', $request->brand_id);
            })
            ->when($request->filled('contact_id'), function ($q) use ($request) {
                $q->where('contact_id', $request->contact_id);
            });

        $priceLists = $this->paginateSorted($query, $request);

        $priceLists->getCollection()->transform(function (PriceList $priceList) {
            return $priceList->presentForIndex();
        });

        $trash_count = PriceList::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $priceLists, 'trash_count' => $trash_count]);
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'nullable',
            'brand_id' => 'nullable|integer',
            'search' => 'nullable|string|max:200',
        ]);

        $companyId = Auth::user()?->hasRole('superadmin')
            ? Transaction::resolveScopedId($request->company_id)
            : Transaction::resolveScopedId(Auth::user()?->company_id ?? $request->company_id);

        return response()->json(Transaction::searchProducts(
            $companyId,
            Transaction::resolveScopedId($request->branch_id),
            $request->input('search'),
            $request->only(['brand_id']),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/pricelist/add');

        $request->validate($this->priceListFormRules());

        DB::beginTransaction();
        try {
            PriceList::createPriceList($request, (array) $request->input('pricelistdetails', []));
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
        $priceList = PriceList::query()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'brand:id,name',
                'contact:id,first_name,last_name,business_name',
                'pricelistdetails.product:id,name,sku',
                'pricelistdetails.productdetail:id,product_id,name,sku',
                'pricelistdetails.unit:id,name,short_name',
                'pricelistdetails.tiers',
            ])
            ->find($id);

        if ($priceList === null) {
            abort(404);
        }

        $payload = $priceList->toArray();
        $payload['date'] = $priceList->date?->format('Y-m-d');
        $payload['pricelistdetails'] = $priceList->pricelistdetails->map(function ($line) {
            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'variation_id' => $line->variation_id,
                'unit_id' => $line->unit_id,
                'product_name' => $line->product?->name,
                'name' => $line->productdetail?->name ?: $line->product?->name,
                'sku' => $line->productdetail?->sku ?: $line->product?->sku,
                'unit_name' => $line->unit?->short_name ?: $line->unit?->name,
                'purchase_price' => (float) $line->purchase_price,
                'sell_price' => (float) $line->sell_price,
                'profit_margin' => (float) $line->profit_margin,
                'discount' => (float) $line->discount,
                'tiers' => $line->tiers->map(fn ($tier): array => ['min_qty' => $tier->min_qty, 'sell_price' => $tier->sell_price])->all(),
                'tiers_text' => $line->tiers->map(fn ($tier): string => rtrim(rtrim(number_format($tier->min_qty, 2, '.', ''), '0'), '.').':'.rtrim(rtrim(number_format($tier->sell_price, 2, '.', ''), '0'), '.'))->implode(', '),
                'units' => [],
            ];
        })->values()->all();

        return response()->json($payload);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/pricelist/:id/edit');

        $request->validate($this->priceListFormRules());

        DB::beginTransaction();
        try {
            PriceList::updatePriceList($request, (int) $id, (array) $request->input('pricelistdetails', []));
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
        if (deletepermission('/pricelist/delete')) {
            PriceList::deletePriceList($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/pricelist/delete', 'Successfully Deleted', function () use ($request) {
            PriceList::query()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/pricelist/delete', 'Successfully Deleted', function () use ($request) {
            PriceList::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission('/pricelist/restore')) {
            DB::beginTransaction();
            try {
                PriceList::onlyTrashed()
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

    public function trash(Request $request): JsonResponse
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = PriceList::onlyTrashed()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'brand:id,name'])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->whereHas('brand', function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%");
                });
            });

        $priceLists = $this->paginateSorted($query, $request);

        $priceLists->getCollection()->transform(function (PriceList $priceList) {
            return $priceList->presentForIndex();
        });

        return response()->json(['data' => $priceLists]);
    }
}
