<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class StockTransferController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function stockTransferFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'bail|required',
            'tobranch_id' => 'bail|required|different:branch_id',
            'transaction_date' => 'bail|required',
            'invoice_no' => 'nullable|string|max:100',
            'additional_note' => 'nullable|string',
            'status' => 'nullable|in:pending,in_transit,completed',
            'total_item' => 'nullable|integer|min:0',
            'purchaselines' => 'bail|required|array|min:1',
            'purchaselines.*.product_id' => 'bail|required',
            'purchaselines.*.variation_id' => 'bail|required',
            'purchaselines.*.unit_id' => 'bail|required',
            'purchaselines.*.quantity' => 'bail|required|numeric|min:1',
            'purchaselines.*.packing_qty' => 'nullable|numeric|min:0',
        ];
    }

    public function index(Request $request)
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = Transaction::query()
            ->transfers()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'tobranch:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where('invoice_no', 'like', "%{$search}%");
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->when($request->filled('tobranch_id'), function ($q) use ($request) {
                $q->where('tobranch_id', $request->tobranch_id);
            });

        $transfers = $this->paginateSorted($query, $request);

        $transfers->getCollection()->transform(function (Transaction $transfer) {
            return $transfer->presentForIndex();
        });

        $trash_count = Transaction::onlyTrashed()->transfers()->visibleToCurrentUser()->count();

        return response()->json(['data' => $transfers, 'trash_count' => $trash_count]);
    }

    public function searchProducts(Request $request)
    {
        $request->validate([
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'nullable',
            'search' => 'nullable|string|max:200',
            'category_id' => 'nullable|integer',
            'subcategory_id' => 'nullable|integer',
            'itemtype_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
        ]);

        $companyId = Auth::user()?->hasRole('superadmin')
            ? Transaction::resolveScopedId($request->company_id)
            : Transaction::resolveScopedId(Auth::user()?->company_id ?? $request->company_id);

        return response()->json(Transaction::searchProducts(
            $companyId,
            Transaction::resolveScopedId($request->branch_id),
            $request->input('search'),
            $request->only(['category_id', 'subcategory_id', 'itemtype_id', 'product_id']),
        ));
    }

    public function store(Request $request)
    {
        $this->authorizeMenuPermission('/stocktransfer/add');

        $request->validate($this->stockTransferFormRules());

        DB::beginTransaction();
        try {
            Transaction::createTransfer($request);
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

    public function show($id)
    {
        $transfer = Transaction::query()
            ->transfers()
            ->visibleToCurrentUser()
            ->with([
                'purchaselines.product:id,name,sku,unit_id,itemtype_id',
                'purchaselines.productdetail:id,product_id,name,sku,smallquantity,largequantity',
                'purchaselines.unit:id,name,short_name',
                'company:id,name',
                'branch:id,name',
                'tobranch:id,name',
            ])
            ->find((int) $id);

        if ($transfer === null) {
            abort(404);
        }

        $payload = $transfer->toArray();
        $payload['purchaselines'] = $transfer->formattedTransferLines();
        $payload['transaction_date'] = $transfer->transaction_date?->format('Y-m-d');

        return response()->json($payload);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeMenuPermission('/stocktransfer/:id/edit');

        $request->validate($this->stockTransferFormRules());

        DB::beginTransaction();
        try {
            Transaction::updateTransfer($request, (int) $id);
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
        if (deletepermission('/stocktransfer/delete')) {
            Transaction::deleteTransfer((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        return $this->guardedBulkAction('/stocktransfer/delete', 'Successfully Deleted', function () use ($request) {
            $ids = Transaction::query()
                ->transfers()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->pluck('id');

            Transaction::whereIn('id', $ids)->delete();
        });
    }

    public function bulk_delete_per(Request $request)
    {
        return $this->guardedBulkAction('/stocktransfer/delete', 'Successfully Deleted', function () use ($request) {
            $ids = Transaction::query()
                ->onlyTrashed()
                ->transfers()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->pluck('id');

            Transaction::whereIn('id', $ids)->forceDelete();
        });
    }

    public function restore_records(Request $request)
    {
        if (deletepermission('/stocktransfer/restore')) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->onlyTrashed()
                    ->transfers()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Transaction::whereIn('id', $ids)->restore();

                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request)
    {
        $this->authorizeMenuPermission('/stocktransfer/:id/edit');
        $transfers = Transaction::query()
            ->transfers()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if ($transfers->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($transfers as $transfer) {
                if (isset($request->status)) {
                    $transfer->status = $request->status;
                    $transfer->save();
                }
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function trash(Request $request)
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = Transaction::onlyTrashed()
            ->transfers()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'tobranch:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where('invoice_no', 'like', "%{$search}%");
            });

        $transfers = $this->paginateSorted($query, $request);

        $transfers->getCollection()->transform(function (Transaction $transfer) {
            return $transfer->presentForIndex();
        });

        return response()->json(['data' => $transfers]);
    }
}
