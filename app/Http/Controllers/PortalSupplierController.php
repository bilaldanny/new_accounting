<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPortalContact;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PortalRequest;
use App\Models\Transaction;
use App\Support\Base64Upload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a signed-in supplier may see and send through the portal: the purchase orders placed with them and where
 * each stands, and the documents of bills they issue, which wait as pending requests for staff to check and enter.
 * Every query is built from the contact the account is tied to (ResolvesPortalContact).
 */
class PortalSupplierController extends Controller
{
    use ResolvesPortalContact;

    public const MAX_KILOBYTES = 5120;

    public function purchaseOrders(Request $request): JsonResponse
    {
        $contact = $this->portalContact($request, 'supplier');

        $page = $this->orderQuery($contact)->orderByDesc('transaction_date')->orderByDesc('id')->paginate(15);
        $ids = $page->getCollection()->pluck('id')->all();
        $progress = DB::table('purchase_lines')
            ->whereIn('transaction_id', $ids)
            ->groupBy('transaction_id')
            ->selectRaw('transaction_id, sum(quantity) as ordered, sum(quantity_received) as received')
            ->get()
            ->keyBy('transaction_id');

        return response()->json([
            'data' => $page->getCollection()->map(function (Transaction $order) use ($progress): array {
                $row = $progress->get($order->id);

                return $this->presentOrder($order) + [
                    'ordered_quantity' => round((float) ($row->ordered ?? 0), 3),
                    'received_quantity' => round((float) ($row->received ?? 0), 3),
                ];
            })->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function purchaseOrder(Request $request, int $id): JsonResponse
    {
        $contact = $this->portalContact($request, 'supplier');
        $order = $this->orderQuery($contact)->findOrFail($id);

        $lines = DB::table('purchase_lines')
            ->leftJoin('products', 'products.id', '=', 'purchase_lines.product_id')
            ->leftJoin('units', 'units.id', '=', 'purchase_lines.unit_id')
            ->where('purchase_lines.transaction_id', $order->id)
            ->orderBy('purchase_lines.id')
            ->get(['products.name', 'products.sku', 'units.short_name as unit', 'purchase_lines.quantity', 'purchase_lines.quantity_received', 'purchase_lines.purchase_rate'])
            ->map(fn ($line): array => [
                'name' => $line->name,
                'sku' => $line->sku,
                'unit' => $line->unit,
                'quantity' => round((float) $line->quantity, 3),
                'received' => round((float) $line->quantity_received, 3),
                'rate' => round((float) $line->purchase_rate, 2),
            ])
            ->all();

        $company = Company::query()->find($contact->company_id, ['id', 'name']);

        return response()->json($this->presentOrder($order) + [
            'company' => ['name' => $company?->name],
            'lines' => $lines,
        ]);
    }

    public function bills(Request $request): JsonResponse
    {
        $contact = $this->portalContact($request, 'supplier');

        return response()->json(['data' => PortalRequest::query()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->where('kind', 'bill')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (PortalRequest $bill): array => $bill->present())
            ->all()]);
    }

    public function storeBill(Request $request): JsonResponse
    {
        $contact = $this->portalContact($request, 'supplier');

        $data = $request->validate([
            'bill_no' => 'required|string|max:100',
            'bill_date' => 'required|date',
            'amount' => 'required|numeric|gt:0|max:9999999999',
            'purchase_id' => 'nullable|integer',
            'note' => 'nullable|string|max:1000',
            'file' => 'bail|required|file|max:'.self::MAX_KILOBYTES,
        ]);

        $contact->assertAvailableForTransaction();

        $billNo = trim($data['bill_no']);

        if (PortalRequest::query()->where('company_id', $contact->company_id)->where('contact_id', $contact->id)
            ->where('kind', 'bill')->whereIn('status', ['pending', 'approved'])->where('bill_no', $billNo)->exists()) {
            throw ValidationException::withMessages(['bill_no' => ['You have already sent a bill with this number.']]);
        }

        $purchaseId = null;

        if (filled($data['purchase_id'] ?? null)) {
            $purchaseId = $this->orderQuery($contact)->whereKey((int) $data['purchase_id'])->value('id');

            if ($purchaseId === null) {
                throw ValidationException::withMessages(['purchase_id' => ['Choose one of your purchase orders.']]);
            }
        }

        $file = $request->file('file');
        $extension = Base64Upload::extensionForMime((string) $file->getMimeType());

        if ($extension === null) {
            throw ValidationException::withMessages(['file' => ['Upload the bill as a PDF, JPG or PNG file.']]);
        }

        Company::query()->find($contact->company_id)?->assertCanAddStorageBytes((int) $file->getSize());

        $path = $file->storeAs('portal-bills/'.$contact->company_id.'/'.$contact->id, Str::ulid().'.'.$extension, PortalRequest::DISK);

        $bill = PortalRequest::query()->create([
            'company_id' => $contact->company_id,
            'branch_id' => $contact->branch_id,
            'contact_id' => $contact->id,
            'kind' => 'bill',
            'status' => 'pending',
            'note' => $data['note'] ?? null,
            'purchase_id' => $purchaseId,
            'bill_no' => $billNo,
            'bill_date' => $data['bill_date'],
            'amount' => $data['amount'],
            'file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 250),
            'file_path' => (string) $path,
            'file_mime' => (string) $file->getMimeType(),
            'file_size' => (int) $file->getSize(),
            'requested_by' => $request->user()->id,
        ]);
        $bill->assignReference();

        return response()->json([
            'message' => 'Your bill has been sent for checking.',
            'data' => $bill->fresh()->present(),
        ], 201);
    }

    public function billFile(Request $request, int $id): StreamedResponse
    {
        $contact = $this->portalContact($request, 'supplier');

        $bill = PortalRequest::query()
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->where('kind', 'bill')
            ->findOrFail($id);

        abort_unless(filled($bill->file_path) && Storage::disk(PortalRequest::DISK)->exists($bill->file_path), 404);

        return Storage::disk(PortalRequest::DISK)->download($bill->file_path, $bill->file_name);
    }

    /**
     * @return Builder<Transaction>
     */
    private function orderQuery(Contact $contact)
    {
        return Transaction::query()
            ->where('type', Transaction::TYPE_PURCHASE)
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->whereNotIn('status', ['draft']);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOrder(Transaction $order): array
    {
        return [
            'id' => $order->id,
            'invoice_no' => $order->invoice_no,
            'date' => $order->transaction_date?->toDateString(),
            'status' => $order->status,
            'status_label' => Str::headline((string) $order->status),
            'payment_status' => $order->payment_status,
            'amount' => round((float) $order->final_amount, 2),
        ];
    }
}
