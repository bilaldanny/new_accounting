<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\PortalRequest;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings > Portal Requests: what customers and suppliers sent in through the portal. A customer's quotation or
 * order request is approved into a quotation or a draft sale (nothing is posted until staff finish that document the
 * normal way); a supplier's bill is approved to say it was accepted for entry, or rejected with a reason.
 */
class PortalRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/portalrequests');

        $status = (string) $request->query('status', 'pending');

        $requests = $this->visible()
            ->with('contact:id,business_name,first_name,last_name,code,user_type')
            ->when(in_array($status, PortalRequest::STATUSES, true), fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (PortalRequest $portalRequest): array => $portalRequest->present() + [
                'contact_name' => $portalRequest->contact?->displayName(),
                'contact_code' => $portalRequest->contact?->code,
            ]);

        return response()->json(['data' => $requests]);
    }

    public function document(int $id): StreamedResponse
    {
        $this->authorizeMenuPermission('/portalrequests');

        $portalRequest = $this->visible()->findOrFail($id);

        abort_unless(filled($portalRequest->file_path) && Storage::disk(PortalRequest::DISK)->exists($portalRequest->file_path), 404);

        return Storage::disk(PortalRequest::DISK)->download($portalRequest->file_path, $portalRequest->file_name);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/portalrequests/approve');

        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        $portalRequest = $this->visible()->with('contact')->findOrFail($id);
        $this->assertPending($portalRequest);

        DB::transaction(function () use ($portalRequest, $data): void {
            $transactionId = $portalRequest->kind === 'bill' ? null : $this->createSale($portalRequest)->id;

            $portalRequest->update([
                'status' => 'approved',
                'transaction_id' => $transactionId,
                'review_note' => $data['note'] ?? null,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Request approved.', 'data' => $portalRequest->fresh()->present()]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/portalrequests/approve');

        $data = $request->validate(['note' => 'required|string|min:3|max:1000']);

        $portalRequest = $this->visible()->findOrFail($id);
        $this->assertPending($portalRequest);

        $portalRequest->update([
            'status' => 'rejected',
            'review_note' => $data['note'],
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return response()->json(['message' => 'Request rejected.', 'data' => $portalRequest->present()]);
    }

    /**
     * @return Builder<PortalRequest>
     */
    private function visible(): Builder
    {
        $user = Auth::user();
        $query = PortalRequest::query();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if (! $user?->company_id) {
            return $query->whereRaw('0 = 1');
        }

        $query->where('company_id', $user->company_id);

        if ($user->branch_id && ! $user->hasRole('companyadmin')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    private function assertPending(PortalRequest $portalRequest): void
    {
        if ($portalRequest->status !== 'pending') {
            throw ValidationException::withMessages(['status' => ['This request has already been dealt with.']]);
        }
    }

    /**
     * A quotation request becomes a quotation, an order request a draft sale, both for the contact's own branch and
     * through the same Transaction::createSell the sale form uses, so numbering and tax come out the same. Neither status
     * is posted to the ledger.
     */
    private function createSale(PortalRequest $portalRequest): Transaction
    {
        /** @var Contact $contact */
        $contact = $portalRequest->contact;
        $lines = [];
        $total = 0.0;

        foreach ((array) $portalRequest->lines as $line) {
            $product = Product::query()->where('company_id', $portalRequest->company_id)->find($line['product_id'] ?? 0);

            if ($product === null) {
                throw ValidationException::withMessages(['lines' => ['A product in this request no longer exists.']]);
            }

            $quantity = (float) $line['quantity'];
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $discount = (float) ($line['discount_percent'] ?? 0);
            $net = round($unitPrice * (1 - $discount / 100), 2);
            $subtotal = round($net * $quantity, 2);
            $total += $subtotal;

            $lines[] = [
                'product_id' => $product->id,
                'variation_id' => $line['variation_id'] ?? null,
                'itemtype_id' => $product->itemtype_id,
                'unit_id' => $product->unit_id,
                'quantity' => $quantity,
                'packing_qty' => 1,
                'unit_price' => $unitPrice,
                'discount_percent' => $discount,
                'unit_price_after_discount' => $net,
                'row_subtotal' => $subtotal,
            ];
        }

        $note = trim('Portal request '.$portalRequest->reference.($portalRequest->note ? ': '.$portalRequest->note : ''));

        return Transaction::createSell(new Request([
            'company_id' => $portalRequest->company_id,
            'branch_id' => $portalRequest->branch_id,
            'contact_id' => $portalRequest->contact_id,
            'transaction_date' => now()->toDateString(),
            'pay_term' => $contact->pay_term,
            'pay_type' => $contact->pay_type,
            'status' => $portalRequest->kind === 'quotation' ? 'quotation' : 'draft',
            'discount_type' => 'none',
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'additional_note' => $note,
            'final_amount' => round($total, 2),
            'total_item' => count($lines),
            'is_direct' => false,
            'selllines' => $lines,
        ]));
    }
}
