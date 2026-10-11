<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPortalContact;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PortalRequest;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\Transaction;
use App\Services\ContactLedger;
use App\Services\PriceResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a signed-in customer may see and send through the portal: their own invoices (list, detail for printing),
 * their statement, and quotation / order requests. Every query is built from the contact the account is tied to
 * (ResolvesPortalContact), never from an id in the request.
 */
class PortalCustomerController extends Controller
{
    use ResolvesPortalContact;

    public function invoices(Request $request): JsonResponse
    {
        $contact = $this->portalContact($request, 'customer');

        $page = $this->invoiceQuery($contact)->orderByDesc('transaction_date')->orderByDesc('id')->paginate(15);
        $paid = $this->paidByTransaction($page->getCollection()->pluck('id')->all());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Transaction $invoice): array => $this->presentInvoice($invoice, (float) ($paid[$invoice->id] ?? 0)))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function invoice(Request $request, int $id): JsonResponse
    {
        $contact = $this->portalContact($request, 'customer');
        $invoice = $this->invoiceQuery($contact)->findOrFail($id);
        $paid = $this->paidByTransaction([$invoice->id]);

        $lines = DB::table('sell_lines')
            ->leftJoin('products', 'products.id', '=', 'sell_lines.product_id')
            ->leftJoin('units', 'units.id', '=', 'sell_lines.unit_id')
            ->where('sell_lines.transaction_id', $invoice->id)
            ->orderBy('sell_lines.id')
            ->get(['products.name', 'products.sku', 'units.short_name as unit', 'sell_lines.quantity', 'sell_lines.unit_price', 'sell_lines.discount_percent', 'sell_lines.subtotal'])
            ->map(fn ($line): array => [
                'name' => $line->name,
                'sku' => $line->sku,
                'unit' => $line->unit,
                'quantity' => round((float) $line->quantity, 3),
                'unit_price' => round((float) $line->unit_price, 2),
                'discount_percent' => round((float) $line->discount_percent, 2),
                'subtotal' => round((float) $line->subtotal, 2),
            ])
            ->all();

        $company = Company::query()->find($contact->company_id, ['id', 'name', 'address', 'phone', 'email']);

        return response()->json($this->presentInvoice($invoice, (float) ($paid[$invoice->id] ?? 0)) + [
            'company' => ['name' => $company?->name, 'address' => $company?->address, 'phone' => $company?->phone, 'email' => $company?->email],
            'customer' => ['name' => $contact->displayName(), 'address' => $contact->address, 'mobile' => $contact->mobile],
            'lines' => $lines,
            'total_before_tax' => round((float) $invoice->total_before_tax, 2),
            'tax_amount' => round((float) $invoice->tax_amount, 2),
            'discount_type' => $invoice->discount_type,
            'discount_amount' => round((float) $invoice->discount_amount, 2),
            'shipping_charges' => round((float) $invoice->shipping_charges, 2),
            'payments' => DB::table('payments')
                ->where('transaction_id', $invoice->id)
                ->where('is_return', false)
                ->orderBy('paid_on')
                ->get(['paid_on', 'amount', 'method', 'payment_ref_no'])
                ->map(fn ($payment): array => [
                    'date' => $payment->paid_on === null ? null : substr((string) $payment->paid_on, 0, 10),
                    'amount' => round((float) $payment->amount, 2),
                    'method' => $payment->method,
                    'reference' => $payment->payment_ref_no,
                ])
                ->all(),
        ]);
    }

    /**
     * The customer's ledger for a period, as the staff see it: opening balance, each posted entry with a running
     * balance, and the closing balance.
     */
    public function statement(Request $request, ContactLedger $ledger): JsonResponse
    {
        $contact = $this->portalContact($request, 'customer');

        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from']) : now()->startOfYear();
        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();

        $result = $ledger->forContact($contact, $from, $to, null, true);
        $running = round((float) $result['openingbalance'], 2);

        $rows = collect($result['taccount'])->map(function (array $row) use (&$running): array {
            $debit = round((float) ($row['debit'] ?? 0), 2);
            $credit = round((float) ($row['credit'] ?? 0), 2);
            $running = round($running + ($row['acc_nature'] === 'cr' ? $credit - $debit : $debit - $credit), 2);

            return [
                'date' => $row['voucher_date'],
                'reference' => $row['voucher_no'],
                'description' => trim(strip_tags((string) $row['description'])),
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $running,
            ];
        })->all();

        return response()->json([
            'contact' => ['name' => $contact->displayName(), 'code' => $contact->code],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening_balance' => round((float) $result['openingbalance'], 2),
            'closing_balance' => round((float) $result['closingbalance'], 2),
            'rows' => $rows,
        ]);
    }

    /**
     * Products the customer may ask for, with the price the company would quote them (their own price list first).
     */
    public function products(Request $request, PriceResolver $prices): JsonResponse
    {
        $contact = $this->portalContact($request, 'customer');
        $search = trim((string) $request->query('search', ''));

        $products = Product::query()
            ->where('company_id', $contact->company_id)
            ->where('active', 1)
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'sku']);

        return response()->json(['data' => $products->map(function (Product $product) use ($contact, $prices): array {
            $price = $prices->resolve((int) $contact->company_id, (int) $contact->branch_id, (int) $contact->id, $product->id, null, 1);

            return ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'price' => round((float) $price['price'], 2)];
        })->all()]);
    }

    public function requests(Request $request): JsonResponse
    {
        $contact = $this->portalContact($request, 'customer');

        return response()->json(['data' => PortalRequest::query()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->whereIn('kind', ['quotation', 'order'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (PortalRequest $portalRequest): array => $portalRequest->present())
            ->all()]);
    }

    public function storeRequest(Request $request, PriceResolver $prices): JsonResponse
    {
        $contact = $this->portalContact($request, 'customer');

        $data = $request->validate([
            'kind' => 'required|in:quotation,order',
            'note' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1|max:50',
            'lines.*.product_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|min:0.001|max:1000000',
        ]);

        $contact->assertAvailableForTransaction();

        $products = Product::query()
            ->where('company_id', $contact->company_id)
            ->where('active', 1)
            ->whereIn('id', collect($data['lines'])->pluck('product_id')->unique()->all())
            ->get(['id', 'name', 'sku'])
            ->keyBy('id');

        $lines = [];

        foreach ($data['lines'] as $index => $line) {
            $product = $products->get((int) $line['product_id']);

            if ($product === null) {
                throw ValidationException::withMessages(["lines.{$index}.product_id" => ['This product is not available.']]);
            }

            $variation = ProductDetail::query()->where('product_id', $product->id)->orderBy('id')->first();
            $price = $prices->resolve((int) $contact->company_id, (int) $contact->branch_id, (int) $contact->id, $product->id, $variation?->id, (float) $line['quantity']);

            $lines[] = [
                'product_id' => $product->id,
                'variation_id' => $variation?->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'quantity' => round((float) $line['quantity'], 3),
                'unit_price' => round((float) $price['price'], 2),
                'discount_percent' => round((float) $price['discount_percent'], 2),
            ];
        }

        $portalRequest = PortalRequest::query()->create([
            'company_id' => $contact->company_id,
            'branch_id' => $contact->branch_id,
            'contact_id' => $contact->id,
            'kind' => $data['kind'],
            'status' => 'pending',
            'note' => $data['note'] ?? null,
            'lines' => $lines,
            'requested_by' => $request->user()->id,
        ]);
        $portalRequest->assignReference();

        return response()->json([
            'message' => 'Your request has been sent. We will confirm it shortly.',
            'data' => $portalRequest->fresh()->present(),
        ], 201);
    }

    public function cancelRequest(Request $request, int $id): JsonResponse
    {
        $contact = $this->portalContact($request, 'customer');

        $portalRequest = PortalRequest::query()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->whereIn('kind', ['quotation', 'order'])
            ->findOrFail($id);

        if ($portalRequest->status !== 'pending') {
            return response()->json(['message' => 'Only a request that is still pending can be cancelled.'], 422);
        }

        $portalRequest->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Request cancelled.', 'data' => $portalRequest->present()]);
    }

    /**
     * @return Builder<Transaction>
     */
    private function invoiceQuery(Contact $contact)
    {
        return Transaction::query()
            ->where('type', Transaction::TYPE_SELL)
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, float|string>
     */
    private function paidByTransaction(array $ids): array
    {
        return DB::table('payments')
            ->whereIn('transaction_id', $ids)
            ->where('is_return', false)
            ->groupBy('transaction_id')
            ->pluck(DB::raw('sum(amount)'), 'transaction_id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvoice(Transaction $invoice, float $paid): array
    {
        $amount = round((float) $invoice->final_amount, 2);
        $paid = round($paid, 2);

        return [
            'id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'date' => $invoice->transaction_date?->toDateString(),
            'status' => $invoice->status,
            'payment_status' => $invoice->payment_status,
            'amount' => $amount,
            'paid' => $paid,
            'due' => round(max($amount - $paid, 0), 2),
        ];
    }
}
