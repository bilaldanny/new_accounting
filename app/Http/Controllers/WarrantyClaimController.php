<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\Transaction;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * After-sales warranty claims. A claim is filed against a product (and normally the sale it came with); whether it falls
 * inside the warranty, and whether the warranty rules allow it, is decided when it is filed. It then moves
 * open > in_review > approved / rejected > resolved. Nothing here posts to the books: a refund or replacement is entered
 * through the usual sale return / stock screens and referred to in the resolution note.
 */
class WarrantyClaimController extends Controller
{
    use ScopesToCompany;

    private const FLOW = [
        'open' => ['in_review', 'approved', 'rejected'],
        'in_review' => ['approved', 'rejected'],
        'approved' => ['resolved'],
        'rejected' => [],
        'resolved' => [],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/warrantyclaim');

        $claims = WarrantyClaim::query()
            ->visibleToCurrentUser()
            ->with(['product:id,name,sku', 'contact:id,business_name,first_name'])
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($sub) => $sub->where('claim_no', 'like', $term)->orWhere('serial_no', 'like', $term)->orWhere('issue', 'like', $term));
            })
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return response()->json(['data' => $claims]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/warrantyclaim');

        return response()->json(WarrantyClaim::query()->visibleToCurrentUser()->with(['product:id,name,sku,warranty_id', 'contact:id,business_name,first_name'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/warrantyclaim/add');

        $companyId = $this->scopeCompanyId($request);

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'product_id' => 'required|integer',
            'variation_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'transaction_id' => 'nullable|integer',
            'serial_no' => 'nullable|string|max:100',
            'issue' => 'required|string|min:3|max:2000',
            'claimed_on' => 'required|date_format:Y-m-d',
        ]);

        $product = Product::query()->where('company_id', $companyId)->find($data['product_id']);

        if ($product === null) {
            throw ValidationException::withMessages(['product_id' => ['Choose a product of this company.']]);
        }

        $warranty = $product->warranty_id ? Warranty::query()->where('company_id', $companyId)->find($product->warranty_id) : null;

        if ($warranty === null) {
            throw ValidationException::withMessages(['product_id' => ['This product has no warranty.']]);
        }

        if (! empty($data['variation_id']) && ! ProductDetail::query()->where('product_id', $product->id)->whereKey($data['variation_id'])->exists()) {
            throw ValidationException::withMessages(['variation_id' => ['That variation does not belong to this product.']]);
        }

        if (! empty($data['contact_id']) && ! Contact::query()->where('company_id', $companyId)->whereKey($data['contact_id'])->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a contact of this company.']]);
        }

        $sale = null;

        if (! empty($data['transaction_id'])) {
            $sale = Transaction::query()->sells()->where('company_id', $companyId)->find($data['transaction_id']);

            if ($sale === null || ! $sale->selllines()->where('product_id', $product->id)->exists()) {
                throw ValidationException::withMessages(['transaction_id' => ['Choose a sale of this company that includes this product.']]);
            }
        }

        if (Warranty::supportsAfterSalesRules() && $warranty->require_invoice && $sale === null) {
            throw ValidationException::withMessages(['transaction_id' => ['This warranty needs the sale invoice the product came with.']]);
        }

        if ($sale !== null && Warranty::supportsAfterSalesRules() && $warranty->max_claims !== null) {
            $used = WarrantyClaim::query()->where('company_id', $companyId)->where('transaction_id', $sale->id)
                ->where('product_id', $product->id)->where('status', '!=', 'rejected')->count();

            if ($used >= (int) $warranty->max_claims) {
                throw ValidationException::withMessages(['transaction_id' => ["This warranty allows {$warranty->max_claims} claim(s) per sale and they have been used."]]);
            }
        }

        $expires = $sale === null ? null : $this->expiryFor($sale->transaction_date, $warranty);
        $claimedOn = Carbon::parse($data['claimed_on'])->startOfDay();

        $claim = WarrantyClaim::query()->create([
            'company_id' => $companyId,
            'branch_id' => $this->scopeBranchId($request, $companyId) ?? $sale?->branch_id,
            'claim_no' => $this->nextNumber($companyId),
            'product_id' => $product->id,
            'variation_id' => $data['variation_id'] ?? null,
            'contact_id' => $data['contact_id'] ?? $sale?->contact_id,
            'transaction_id' => $sale?->id,
            'serial_no' => $data['serial_no'] ?? null,
            'issue' => $data['issue'],
            'claimed_on' => $claimedOn->toDateString(),
            'warranty_expires_on' => $expires?->toDateString(),
            'in_warranty' => $expires !== null && $claimedOn->lte($expires),
            'status' => 'open',
            'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $claim], 201);
    }

    /**
     * Moves a claim along its flow. Approving a claim that is outside the warranty needs a note saying why.
     */
    public function transition(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/warrantyclaim/:id/edit');

        $data = $request->validate([
            'status' => ['required', Rule::in(WarrantyClaim::STATUSES)],
            'resolution' => ['nullable', Rule::in(WarrantyClaim::RESOLUTIONS)],
            'resolution_note' => 'nullable|string|max:2000',
        ]);

        $claim = WarrantyClaim::query()->visibleToCurrentUser()->findOrFail($id);

        if (! in_array($data['status'], self::FLOW[$claim->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => ["A {$claim->status} claim cannot become {$data['status']}."]]);
        }

        $note = trim((string) ($data['resolution_note'] ?? ''));

        if ($data['status'] === 'approved' && ! $claim->in_warranty && mb_strlen($note) < 3) {
            throw ValidationException::withMessages(['resolution_note' => ['This claim is outside the warranty: say why it is being approved.']]);
        }

        if ($data['status'] === 'rejected' && mb_strlen($note) < 3) {
            throw ValidationException::withMessages(['resolution_note' => ['Say why the claim is rejected.']]);
        }

        if ($data['status'] === 'resolved' && empty($data['resolution'])) {
            throw ValidationException::withMessages(['resolution' => ['Choose how the claim was resolved.']]);
        }

        $claim->status = $data['status'];
        $claim->resolution = $data['resolution'] ?? $claim->resolution;
        $claim->resolution_note = $note !== '' ? $note : $claim->resolution_note;
        $claim->resolved_on = $data['status'] === 'resolved' ? now()->toDateString() : $claim->resolved_on;
        $claim->save();

        return response()->json(['message' => 'Successfully Saved', 'data' => $claim->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/warrantyclaim/delete');

        $claim = WarrantyClaim::query()->visibleToCurrentUser()->findOrFail($id);

        if ($claim->status !== 'open') {
            throw ValidationException::withMessages(['status' => ['Only a claim that has not been reviewed can be deleted.']]);
        }

        $claim->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    private function expiryFor(mixed $saleDate, Warranty $warranty): Carbon
    {
        $start = Carbon::parse($saleDate)->startOfDay();
        $duration = (int) $warranty->duration;

        return match ($warranty->type) {
            'year' => $start->addYears($duration),
            'month' => $start->addMonths($duration),
            default => $start->addDays($duration),
        };
    }

    private function nextNumber(int $companyId): string
    {
        $next = (int) WarrantyClaim::query()->where('company_id', $companyId)->max('id') + 1;

        do {
            $number = 'WC-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (WarrantyClaim::query()->where('company_id', $companyId)->where('claim_no', $number)->exists());

        return $number;
    }
}
