<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SellController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    protected function sellFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'bail|required',
            'contact_id' => 'bail|required',
            'transaction_date' => 'bail|required',
            'invoice_no' => 'nullable|string|max:100',
            'pay_term' => 'nullable|numeric|min:0',
            'pay_type' => 'nullable|in:day,month,year',
            'discount_type' => 'nullable|in:none,fixed,percentage',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_charges' => 'nullable|numeric|min:0',
            'shipping_details' => 'nullable|string|max:255',
            'shipping_address' => 'nullable|string',
            'shipping_note' => 'nullable|string',
            'shipping_status' => 'nullable|in:ordered,packed,shipped,delivered,cancelled',
            'delivered_to' => 'nullable|string|max:255',
            'billty_no' => 'nullable|string|max:255',
            'bilty_no' => 'nullable|string|max:255',
            'billty_date' => 'nullable|date',
            'bilty_date' => 'nullable|date',
            'packing' => 'nullable|string|max:255',
            'billty_image' => 'nullable',
            'bilty_image' => 'nullable',
            'additional_note' => 'nullable|string',
            'final_amount' => 'nullable|numeric|min:0',
            'total_item' => 'nullable|integer|min:0',
            'is_direct' => 'nullable|boolean',
            'direct_contact_id' => 'required_if:is_direct,1,true|nullable',
            'status' => 'nullable|in:final,draft,quotation,approved',
            'payment_status' => 'nullable|in:paid,due,partial',
            'selllines' => 'bail|required|array|min:1',
            'selllines.*.product_id' => 'bail|required',
            'selllines.*.variation_id' => 'bail|required',
            'selllines.*.unit_id' => 'bail|required',
            'selllines.*.quantity' => 'bail|required|numeric|min:1',
            'selllines.*.packing_qty' => 'nullable|numeric|min:0',
            'selllines.*.unit_price' => 'nullable|numeric|min:0',
            'selllines.*.discount_percent' => 'nullable|numeric|min:0',
            'selllines.*.unit_price_after_discount' => 'nullable|numeric|min:0',
            'selllines.*.row_subtotal' => 'nullable|numeric|min:0',
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
            ->sells()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'contact:id,business_name',
                'childIssueNotes' => fn ($query) => $query->select('id', 'parent_id')->latest('id'),
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($request->filled('payment_status') && $request->payment_status !== 'all', function ($q) use ($request) {
                $q->where('payment_status', $request->payment_status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('invoice_no', 'like', "%{$search}%")
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

        $sells = $query->paginate($show_record);

        if ($cur_page > $sells->lastPage()) {
            Paginator::currentPageResolver(function () use ($sells) {
                return $sells->lastPage();
            });
            $sells = $query->paginate($show_record);
        }

        $sells->getCollection()->transform(function (Transaction $sell) {
            return $sell->presentForIndex();
        });

        $trash_count = Transaction::onlyTrashed()->sells()->visibleToCurrentUser()->count();

        return response()->json(['data' => $sells, 'trash_count' => $trash_count]);
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
        $this->authorizeMenuPermission('/sell/add');

        $request->validate($this->sellFormRules());

        DB::beginTransaction();
        try {
            Transaction::createSell($request);
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
        $sell = Transaction::query()
            ->sells()
            ->visibleToCurrentUser()
            ->with([
                'selllines.product:id,name,sku,unit_id,itemtype_id',
                'selllines.productdetail:id,product_id,name,sku,smallquantity,largequantity',
                'selllines.unit:id,name,short_name',
                'contact:id,business_name,pay_term,pay_type,credit_limit,address,mobile',
                'directContact:id,business_name',
                'company:id,name,address',
                'branch:id,name',
            ])
            ->find((int) $id);

        if ($sell === null) {
            abort(404);
        }

        $payload = $sell->toArray();
        $payload['selllines'] = $sell->formattedSellLines();
        $payload['billty_image_url'] = Transaction::billtyImageUrl($sell->billty_image);
        $payload['transaction_date'] = $sell->transaction_date?->format('Y-m-d');
        $payload['billty_date'] = $sell->billty_date?->format('Y-m-d');
        $payload['customer_name'] = $sell->contact?->business_name;
        $payload['credit_limit'] = $sell->contact?->credit_limit ?? 0;
        $payload['company_name'] = $sell->company?->name;
        $payload['company_address'] = $sell->company?->address;
        $payload['branch_name'] = $sell->branch?->name;
        $payload['business_name'] = $sell->contact?->business_name;
        $payload['address'] = $sell->contact?->address;
        $payload['mobile'] = $sell->contact?->mobile;
        $payload['status_label'] = $sell->presentForIndex()->status_label;
        $payload['payment_status_label'] = $sell->payment_status_label;
        $payload['transaction_date_label'] = $sell->transaction_date_label;
        $payload['formatted_amount'] = $sell->formatted_amount;

        return response()->json($payload);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeMenuPermission('/sell/:id/edit');

        $request->validate($this->sellFormRules());

        DB::beginTransaction();
        try {
            Transaction::updateSell($request, (int) $id);
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
        if (deletepermission('/sell/delete')) {
            Transaction::deleteSell((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        if (deletepermission('/sell/delete')) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->sells()
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
        if (deletepermission('/sell/delete')) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->onlyTrashed()
                    ->sells()
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
        if (deletepermission('/sell/restore')) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->onlyTrashed()
                    ->sells()
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
        $this->authorizeMenuPermission('/sell/:id/edit');
        $sells = Transaction::query()
            ->sells()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if ($sells->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($sells as $sell) {
                if (isset($request->status)) {
                    $sell->status = $request->status;
                    $sell->save();
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
        $this->authorizeMenuPermission('/sell/add');

        DB::beginTransaction();
        try {
            $sell = Transaction::query()
                ->sells()
                ->visibleToCurrentUser()
                ->with('selllines')
                ->find((int) $request->id);

            if ($sell === null) {
                abort(404);
            }

            $duplicator = $sell->replicate();
            $duplicator->invoice_no = Transaction::generateSellInvoiceNo(Transaction::resolveScopedId($sell->company_id));
            $duplicator->status = 'draft';
            $duplicator->payment_status = 'due';
            $duplicator->created_by = Auth::id();
            $duplicator->updated_by = null;
            $duplicator->save();

            foreach ($sell->selllines as $line) {
                $cloned = $line->replicate();
                $cloned->transaction_id = $duplicator->id;
                $cloned->quantity_issue = 0;
                $cloned->quantity_returned = 0;
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
            ->sells()
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
                    $sub->where('invoice_no', 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contact) use ($search) {
                            $contact->where('business_name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $sells = $query->paginate($show_record);

        if ($cur_page > $sells->lastPage()) {
            Paginator::currentPageResolver(function () use ($sells) {
                return $sells->lastPage();
            });
            $sells = $query->paginate($show_record);
        }

        $sells->getCollection()->transform(function (Transaction $sell) {
            return $sell->presentForIndex();
        });

        return response()->json(['data' => $sells]);
    }
}
