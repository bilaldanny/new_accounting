<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Transaction;
use App\Services\SaleDocumentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sales orders as a document of their own (see App\Models\SalesOrder). Approval follows the company's sale approval setting:
 * with auto-approval on, a new order is confirmed at once; otherwise it waits for someone holding `/salesorder/approve`.
 * Invoicing makes an ordinary sale through SaleDocumentBuilder (so the credit limit, stock check and posting are the sale's own).
 */
class SalesOrderController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly SaleDocumentBuilder $builder) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/salesorder');

        $rows = SalesOrder::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->integer('contact_id')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        $names = Contact::query()->whereIn('id', $rows->getCollection()->pluck('contact_id'))->pluck('business_name', 'id');
        $rows->getCollection()->transform(function (SalesOrder $order) use ($names): SalesOrder {
            $order->setAttribute('contact_name', $names[$order->contact_id] ?? null);

            return $order;
        });

        return response()->json(['data' => $rows]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/salesorder');

        $order = SalesOrder::query()->visibleToCurrentUser()->with('lines.product:id,name,sku')->findOrFail($id);
        $invoices = DB::table('sales_order_invoices as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('l.sales_order_id', $order->id)->whereNull('t.deleted_at')->get(['t.id', 't.invoice_no', 't.status', 't.final_amount']);

        return response()->json($order->toArray() + ['invoices' => $invoices]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/salesorder/add');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'contact_id' => 'required|integer',
            'order_date' => 'required|date_format:Y-m-d',
            'expected_date' => 'nullable|date_format:Y-m-d|after_or_equal:order_date',
            'note' => 'nullable|string|max:1000',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_charges' => 'nullable|numeric|min:0',
            'as_draft' => 'nullable|boolean',
            'quotation_id' => 'nullable|integer',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.product_id' => 'required|integer',
            'lines.*.variation_id' => 'nullable|integer',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);
        $customer = Contact::query()->where('company_id', $companyId)->whereIn('user_type', ['customer', 'both'])->find($data['contact_id']);

        if ($customer === null) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a customer of this company.']]);
        }

        $customer->assertAvailableForTransaction();
        $built = $this->builder->lines($companyId, $branchId, (int) $customer->id, $data['lines']);

        $order = DB::transaction(function () use ($data, $companyId, $branchId, $customer, $built): SalesOrder {
            $order = SalesOrder::query()->create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'contact_id' => $customer->id, 'order_no' => 'TMP',
                'order_date' => $data['order_date'], 'expected_date' => $data['expected_date'] ?? null,
                'status' => ($data['as_draft'] ?? false) ? 'draft' : (CompanySetting::autoApproves($companyId, 'sell') ? 'confirmed' : 'pending_approval'),
                'quotation_id' => $data['quotation_id'] ?? null, 'note' => $data['note'] ?? null,
                'discount_amount' => $data['discount_amount'] ?? 0, 'shipping_charges' => $data['shipping_charges'] ?? 0,
                'total' => round($built['total'] - (float) ($data['discount_amount'] ?? 0) + (float) ($data['shipping_charges'] ?? 0), 2),
                'created_by' => Auth::id(),
            ]);
            $order->forceFill(['order_no' => 'SO-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();

            foreach ($built['lines'] as $line) {
                $order->lines()->create([
                    'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'], 'unit_id' => $line['unit_id'], 'itemtype_id' => $line['itemtype_id'],
                    'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount' => $line['discount_percent'], 'invoiced_qty' => 0,
                ]);
            }

            return $order;
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $order->load('lines')], 201);
    }

    /**
     * Sends a draft for approval (or confirms it when the company approves automatically).
     */
    public function submit(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/salesorder/:id/edit');

        $order = SalesOrder::query()->visibleToCurrentUser()->findOrFail($id);

        if ($order->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only a draft can be submitted.']]);
        }

        $order->update(['status' => CompanySetting::autoApproves((int) $order->company_id, 'sell') ? 'confirmed' : 'pending_approval']);

        return response()->json(['message' => 'Successfully Saved', 'data' => $order->fresh()]);
    }

    public function approve(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/salesorder/approve');

        $order = SalesOrder::query()->visibleToCurrentUser()->findOrFail($id);

        if ($order->status !== 'pending_approval') {
            throw ValidationException::withMessages(['status' => ['Only an order waiting for approval can be approved.']]);
        }

        $order->update(['status' => 'confirmed', 'approved_by' => Auth::id(), 'approved_at' => now()]);

        return response()->json(['message' => 'Successfully Approved', 'data' => $order->fresh()]);
    }

    public function cancel(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/salesorder/:id/edit');

        $order = SalesOrder::query()->visibleToCurrentUser()->with('lines')->findOrFail($id);

        if (in_array($order->status, ['invoiced', 'cancelled'], true) || $order->lines->sum('invoiced_qty') > 0) {
            throw ValidationException::withMessages(['status' => ['An order that has been invoiced (even in part) or is cancelled cannot be cancelled.']]);
        }

        $order->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Successfully Saved', 'data' => $order->fresh()]);
    }

    /**
     * Invoices all that is left of a confirmed order, or the quantities named, as an ordinary sale.
     */
    public function invoice(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/salesorder/invoice');

        $data = $request->validate([
            'status' => 'nullable|in:draft,final',
            'date' => 'nullable|date_format:Y-m-d',
            'lines' => 'nullable|array|max:200',
            'lines.*.line_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|gt:0',
        ]);

        $order = SalesOrder::query()->visibleToCurrentUser()->with('lines')->findOrFail($id);

        if (! in_array($order->status, ['confirmed', 'partially_invoiced'], true)) {
            throw ValidationException::withMessages(['status' => ['Only a confirmed order can be invoiced.']]);
        }

        $wanted = collect($data['lines'] ?? [])->keyBy('line_id');
        $rows = [];
        $invoiced = [];

        foreach ($order->lines as $line) {
            $quantity = $wanted->isEmpty() ? $line->remaining() : (float) ($wanted[$line->id]['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            if ($quantity > $line->remaining() + 0.000001) {
                throw ValidationException::withMessages(['lines' => ['A quantity is more than is left on the order line ('.$line->remaining().').']]);
            }

            $rows[] = ['product_id' => $line->product_id, 'variation_id' => $line->variation_id, 'quantity' => $quantity, 'unit_price' => $line->unit_price, 'discount' => $line->discount];
            $invoiced[$line->id] = $quantity;
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['lines' => ['Nothing is left to invoice on this order.']]);
        }

        $sale = DB::transaction(function () use ($order, $data, $rows, $invoiced): Transaction {
            $built = $this->builder->lines((int) $order->company_id, $order->branch_id === null ? null : (int) $order->branch_id, (int) $order->contact_id, $rows);
            $full = $order->lines->every(fn (SalesOrderLine $line): bool => abs($line->remaining() - ($invoiced[$line->id] ?? 0)) < 0.000001);

            $sale = $this->builder->create([
                'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'contact_id' => $order->contact_id,
                'transaction_date' => $data['date'] ?? now()->toDateString(), 'note' => 'Sales order '.$order->order_no,
                // the order's discount and shipping go on the invoice that completes it
                'discount_amount' => $full ? $order->discount_amount : 0, 'shipping_charges' => $full ? $order->shipping_charges : 0,
            ], $built['lines'], $built['total'], $data['status'] ?? 'final');

            foreach ($invoiced as $lineId => $quantity) {
                SalesOrderLine::query()->whereKey($lineId)->increment('invoiced_qty', $quantity);
            }

            DB::table('sales_order_invoices')->insert(['sales_order_id' => $order->id, 'transaction_id' => $sale->id, 'created_at' => now(), 'updated_at' => now()]);

            $left = SalesOrderLine::query()->where('sales_order_id', $order->id)->get()->sum(fn (SalesOrderLine $line): float => $line->remaining());
            $order->update(['status' => $left <= 0.000001 ? 'invoiced' : 'partially_invoiced']);

            return $sale;
        });

        return response()->json(['message' => 'Invoice created', 'data' => $order->fresh('lines'), 'invoice' => ['id' => $sale->id, 'invoice_no' => $sale->invoice_no, 'status' => $sale->status]], 201);
    }
}
