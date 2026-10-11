<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\SaleQuotationDetail;
use App\Models\SalesOrder;
use App\Models\Transaction;
use App\Services\SaleDocumentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quotations and proforma invoices: the sales in the `quotation` status, with how long the offer is valid, a proforma flag and
 * what they were converted to (a sales order, or a final invoice made from the same lines). A quotation past its date is shown
 * as expired and cannot be converted until it is given a new date.
 */
class QuotationController extends Controller
{
    public function __construct(private readonly SaleDocumentBuilder $builder) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/quotation');

        $quotes = Transaction::query()
            ->sells()
            ->visibleToCurrentUser()
            ->where('status', 'quotation')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100), ['id', 'company_id', 'branch_id', 'contact_id', 'invoice_no', 'transaction_date', 'final_amount']);

        $details = SaleQuotationDetail::query()->whereIn('transaction_id', $quotes->getCollection()->pluck('id'))->get()->keyBy('transaction_id');
        $names = Contact::query()->whereIn('id', $quotes->getCollection()->pluck('contact_id'))->pluck('business_name', 'id');
        $today = now()->toDateString();

        $quotes->getCollection()->transform(function (Transaction $quote) use ($details, $names, $today): Transaction {
            $detail = $details->get($quote->id);
            $until = $detail?->valid_until?->toDateString();
            $state = $detail?->converted_to_type !== null ? 'converted' : ($until !== null && $until < $today ? 'expired' : 'open');
            $quote->setAttribute('valid_until', $until);
            $quote->setAttribute('is_proforma', (bool) ($detail?->is_proforma));
            $quote->setAttribute('state', $state);
            $quote->setAttribute('converted_to', $detail?->converted_to_type === null ? null : ['type' => $detail->converted_to_type, 'id' => $detail->converted_to_id]);
            $quote->setAttribute('contact_name', $names[$quote->contact_id] ?? null);

            return $quote;
        });

        return response()->json(['data' => $quotes]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/quotation/:id/edit');

        $data = $request->validate(['valid_until' => 'nullable|date_format:Y-m-d', 'is_proforma' => 'nullable|boolean']);

        $quote = $this->quotation($id);
        $detail = SaleQuotationDetail::query()->firstOrNew(['transaction_id' => $quote->id]);
        $detail->fill(['company_id' => $quote->company_id] + collect($data)->only(['valid_until', 'is_proforma'])->all())->save();

        return response()->json(['message' => 'Successfully Saved', 'data' => $detail]);
    }

    /**
     * Converts the quotation: `order` makes a confirmed-or-pending sales order from its lines, `invoice` makes a final sale from
     * them. The quotation is kept and marked with what it became.
     */
    public function convert(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/quotation/convert');

        $data = $request->validate(['to' => 'required|in:order,invoice']);

        $quote = $this->quotation($id)->load('selllines');
        $detail = SaleQuotationDetail::query()->firstOrNew(['transaction_id' => $quote->id], ['company_id' => $quote->company_id]);

        if ($detail->converted_to_type !== null) {
            throw ValidationException::withMessages(['to' => ['This quotation has already been converted.']]);
        }

        if ($detail->valid_until !== null && $detail->valid_until->toDateString() < now()->toDateString()) {
            throw ValidationException::withMessages(['to' => ['This quotation expired on '.$detail->valid_until->toDateString().'. Give it a new valid-until date first.']]);
        }

        $rows = $quote->selllines->map(fn ($line): array => [
            'product_id' => $line->product_id, 'variation_id' => $line->variation_id, 'quantity' => (float) $line->quantity,
            'unit_price' => (float) $line->unit_price, 'discount' => (float) $line->discount_percent,
        ])->all();

        $result = DB::transaction(function () use ($data, $quote, $detail, $rows): array {
            if ($data['to'] === 'order') {
                $built = $this->builder->lines((int) $quote->company_id, $quote->branch_id === null ? null : (int) $quote->branch_id, (int) $quote->contact_id, $rows);
                $order = SalesOrder::query()->create([
                    'company_id' => $quote->company_id, 'branch_id' => $quote->branch_id, 'contact_id' => $quote->contact_id, 'order_no' => 'TMP',
                    'order_date' => now()->toDateString(), 'status' => 'pending_approval', 'quotation_id' => $quote->id,
                    'note' => 'From quotation '.$quote->invoice_no, 'total' => $built['total'], 'created_by' => Auth::id(),
                ]);
                $order->forceFill([
                    'order_no' => 'SO-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                    'status' => CompanySetting::autoApproves((int) $quote->company_id, 'sell') ? 'confirmed' : 'pending_approval',
                ])->saveQuietly();

                foreach ($built['lines'] as $line) {
                    $order->lines()->create([
                        'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'], 'unit_id' => $line['unit_id'], 'itemtype_id' => $line['itemtype_id'],
                        'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount' => $line['discount_percent'], 'invoiced_qty' => 0,
                    ]);
                }

                $detail->fill(['converted_to_type' => 'order', 'converted_to_id' => $order->id])->save();

                return ['type' => 'order', 'id' => $order->id, 'number' => $order->order_no];
            }

            $built = $this->builder->lines((int) $quote->company_id, $quote->branch_id === null ? null : (int) $quote->branch_id, (int) $quote->contact_id, $rows);
            $sale = $this->builder->create([
                'company_id' => $quote->company_id, 'branch_id' => $quote->branch_id, 'contact_id' => $quote->contact_id,
                'note' => 'From quotation '.$quote->invoice_no,
            ], $built['lines'], $built['total'], 'final');
            $detail->fill(['converted_to_type' => 'invoice', 'converted_to_id' => $sale->id])->save();

            return ['type' => 'invoice', 'id' => $sale->id, 'number' => $sale->invoice_no];
        });

        return response()->json(['message' => 'Quotation converted', 'data' => $result], 201);
    }

    private function quotation(int $id): Transaction
    {
        return Transaction::query()->sells()->visibleToCurrentUser()->where('status', 'quotation')->findOrFail($id);
    }
}
