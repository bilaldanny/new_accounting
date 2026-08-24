<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PurchaseController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    protected function purchaseFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'bail|required',
            'contact_id' => 'bail|required',
            'transaction_date' => 'bail|required',
            'invoice_no' => 'nullable|string|max:100',
            'sup_ref_no' => 'nullable|string|max:100',
            'pay_term' => 'nullable|numeric|min:0',
            'pay_type' => 'nullable|in:day,month,year',
            'discount_type' => 'nullable|in:none,fixed,percentage',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_charges' => 'nullable|numeric|min:0',
            'shipping_details' => 'nullable|string|max:255',
            'shipping_note' => 'nullable|string',
            'additional_note' => 'nullable|string',
            'final_amount' => 'nullable|numeric|min:0',
            'total_item' => 'nullable|integer|min:0',
            'is_direct' => 'nullable|boolean',
            'direct_contact_id' => 'required_if:is_direct,1,true|nullable',
            'attachment' => 'nullable',
            'status' => 'nullable|in:received,pending,ordered,draft,final,approved',
            'payment_status' => 'nullable|in:paid,due,partial',
            'purchaselines' => 'bail|required|array|min:1',
            'purchaselines.*.product_id' => 'bail|required',
            'purchaselines.*.variation_id' => 'bail|required',
            'purchaselines.*.unit_id' => 'bail|required',
            'purchaselines.*.quantity' => 'bail|required|numeric|min:1',
            'purchaselines.*.packing_qty' => 'nullable|numeric|min:0',
            'purchaselines.*.pp_without_discount' => 'nullable|numeric|min:0',
            'purchaselines.*.discount_percent' => 'nullable|numeric|min:0',
            'purchaselines.*.purchase_rate' => 'nullable|numeric|min:0',
            'purchaselines.*.profit_percent' => 'nullable|numeric|min:0',
            'purchaselines.*.default_sell_price' => 'nullable|numeric|min:0',
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

        $query = Transaction::query()
            ->purchases()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'contact:id,business_name',
                'childReceivingNotes' => fn ($query) => $query->select('id', 'parent_id')->latest('id'),
                'purchasereturn' => fn ($query) => $query->select('id', 'parent_id')->latest('id'),
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($request->filled('payment_status') && $request->payment_status !== 'all', function ($q) use ($request) {
                $q->where('payment_status', $request->payment_status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['invoice_no', 'sup_ref_no'], 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contact) use ($search) {
                            $contact->where('business_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            })
            ->when($request->filled('contact_id'), function ($q) use ($request) {
                $q->where('contact_id', $request->contact_id);
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $purchases = $query->paginate($show_record);

        if ($cur_page > $purchases->lastPage()) {
            Paginator::currentPageResolver(function () use ($purchases) {
                return $purchases->lastPage();
            });
            $purchases = $query->paginate($show_record);
        }

        $purchases->getCollection()->transform(function (Transaction $purchase) {
            return $purchase->presentForIndex();
        });

        $trash_count = Transaction::onlyTrashed()->purchases()->visibleToCurrentUser()->count();

        return response()->json(['data' => $purchases, 'trash_count' => $trash_count]);
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
        $request->validate($this->purchaseFormRules());

        DB::beginTransaction();
        try {
            Transaction::createPurchase($request);
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

    public function show($id)
    {
        $purchase = Transaction::query()
            ->purchases()
            ->visibleToCurrentUser()
            ->with([
                'purchaselines.product:id,name,sku,unit_id,itemtype_id',
                'purchaselines.productdetail:id,product_id,name,sku,smallquantity,largequantity',
                'purchaselines.unit:id,name,short_name',
                'contact:id,business_name,pay_term,pay_type',
                'company:id,name',
                'branch:id,name',
            ])
            ->find((int) $id);

        if ($purchase === null) {
            abort(404);
        }

        $payload = $purchase->toArray();
        $payload['purchaselines'] = $purchase->formattedPurchaseLines();
        $payload['attachment_url'] = Transaction::imageUrl($purchase->attachment);
        $payload['transaction_date'] = $purchase->transaction_date?->format('Y-m-d');

        return response()->json($payload);
    }

    public function update(Request $request, $id)
    {
        $request->validate($this->purchaseFormRules());

        DB::beginTransaction();
        try {
            Transaction::updatePurchase($request, (int) $id);
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
            Transaction::deletePurchase((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->purchases()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Transaction::whereIn('id', $ids)->delete();
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
                $ids = Transaction::query()
                    ->onlyTrashed()
                    ->purchases()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                Transaction::whereIn('id', $ids)->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function restore_records(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->onlyTrashed()
                    ->purchases()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Transaction::whereIn('id', $ids)->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request)
    {
        $purchases = Transaction::query()
            ->purchases()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if ($purchases->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($purchases as $purchase) {
                if (isset($request->status)) {
                    $purchase->status = $request->status;
                    $purchase->save();
                }
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function duplicate(Request $request)
    {
        DB::beginTransaction();
        try {
            $purchase = Transaction::query()
                ->purchases()
                ->visibleToCurrentUser()
                ->with('purchaselines')
                ->find((int) $request->id);

            if ($purchase === null) {
                abort(404);
            }

            $duplicator = $purchase->replicate();
            $duplicator->invoice_no = Transaction::generateInvoiceNo(Transaction::resolveScopedId($purchase->company_id));
            $duplicator->status = 'pending';
            $duplicator->payment_status = 'due';
            $duplicator->created_by = Auth::id();
            $duplicator->updated_by = null;
            $duplicator->save();

            foreach ($purchase->purchaselines as $line) {
                $cloned = $line->replicate();
                $cloned->transaction_id = $duplicator->id;
                $cloned->qunatity_sold = 0;
                $cloned->quantity_returned = 0;
                $cloned->quantity_adjustment = 0;
                $cloned->save();
            }

            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    public function trash(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Transaction::onlyTrashed()
            ->purchases()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'contact:id,business_name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['invoice_no', 'sup_ref_no'], 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contact) use ($search) {
                            $contact->where('business_name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $purchases = $query->paginate($show_record);

        if ($cur_page > $purchases->lastPage()) {
            Paginator::currentPageResolver(function () use ($purchases) {
                return $purchases->lastPage();
            });
            $purchases = $query->paginate($show_record);
        }

        $purchases->getCollection()->transform(function (Transaction $purchase) {
            return $purchase->presentForIndex();
        });

        return response()->json(['data' => $purchases]);
    }
}
