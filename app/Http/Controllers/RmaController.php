<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Contact;
use App\Models\Product;
use App\Models\RmaRequest;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Return merchandise authorisation with suppliers: ask, record the supplier's authorisation number, inspect, ship, record
 * the supplier's receipt and the credit. The status only moves along RmaRequest::FLOW. The goods leave the books through
 * the purchase return document, which can be linked once made (`purchase_return_id`).
 */
class RmaController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/rma');

        $rows = RmaRequest::query()
            ->visibleToCurrentUser()
            ->with('lines.product:id,name,sku')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/rma/add');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'contact_id' => 'required|integer',
            'transaction_id' => 'nullable|integer',
            'reason' => 'nullable|string|max:255',
            'lines' => 'required|array|min:1|max:100',
            'lines.*.product_id' => 'required|integer',
            'lines.*.variation_id' => 'nullable|integer',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.defect' => 'nullable|string|max:255',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        if (! Contact::query()->where('company_id', $companyId)->whereKey($data['contact_id'])->whereIn('user_type', ['supplier', 'both'])->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a supplier of this company.']]);
        }

        if (isset($data['transaction_id']) && ! Transaction::query()->where('company_id', $companyId)->where('type', Transaction::TYPE_PURCHASE)->where('contact_id', $data['contact_id'])->whereKey($data['transaction_id'])->exists()) {
            throw ValidationException::withMessages(['transaction_id' => ['Choose a purchase from this supplier.']]);
        }

        $products = Product::query()->where('company_id', $companyId)->whereIn('id', array_column($data['lines'], 'product_id'))->pluck('id');

        foreach ($data['lines'] as $index => $line) {
            if (! $products->contains((int) $line['product_id'])) {
                throw ValidationException::withMessages(["lines.{$index}.product_id" => ['Choose a product of this company.']]);
            }
        }

        $rma = DB::transaction(function () use ($data, $companyId, $branchId): RmaRequest {
            $rma = RmaRequest::query()->create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'contact_id' => $data['contact_id'], 'transaction_id' => $data['transaction_id'] ?? null,
                'rma_no' => 'TMP', 'status' => 'requested', 'reason' => $data['reason'] ?? null, 'created_by' => Auth::id(),
            ]);
            $rma->forceFill(['rma_no' => 'RMA-'.str_pad((string) $rma->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();

            foreach ($data['lines'] as $line) {
                $rma->lines()->create($line);
            }

            return $rma;
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $rma->load('lines')], 201);
    }

    /**
     * Moves the request one step and records what that step carries (the supplier number, inspection note, dates, the
     * purchase return made for it).
     */
    public function transition(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/rma/:id/edit');

        $data = $request->validate([
            'status' => 'required|in:'.implode(',', RmaRequest::STATUSES),
            'supplier_rma_no' => 'nullable|string|max:100',
            'inspection_note' => 'nullable|string|max:2000',
            'purchase_return_id' => 'nullable|integer',
            'on' => 'nullable|date_format:Y-m-d',
        ]);

        $rma = RmaRequest::query()->visibleToCurrentUser()->findOrFail($id);

        if (! in_array($data['status'], RmaRequest::FLOW[$rma->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => ["A request that is {$rma->status} cannot move to {$data['status']}."]]);
        }

        if ($data['status'] === 'authorized' && blank($data['supplier_rma_no'] ?? $rma->supplier_rma_no)) {
            throw ValidationException::withMessages(['supplier_rma_no' => ['Enter the authorisation number the supplier gave.']]);
        }

        if (isset($data['purchase_return_id']) && ! Transaction::query()->where('company_id', $rma->company_id)->where('type', Transaction::TYPE_PURCHASE_RETURN)->whereKey($data['purchase_return_id'])->exists()) {
            throw ValidationException::withMessages(['purchase_return_id' => ['Choose a purchase return of this company.']]);
        }

        $day = $data['on'] ?? now()->toDateString();
        $dates = ['authorized' => 'authorized_on', 'shipped' => 'shipped_on', 'received' => 'received_on'];

        $rma->fill([
            'status' => $data['status'],
            'supplier_rma_no' => $data['supplier_rma_no'] ?? $rma->supplier_rma_no,
            'inspection_note' => $data['inspection_note'] ?? $rma->inspection_note,
            'purchase_return_id' => $data['purchase_return_id'] ?? $rma->purchase_return_id,
        ]);

        if (isset($dates[$data['status']])) {
            $rma->{$dates[$data['status']]} = $day;
        }

        $rma->save();

        return response()->json(['message' => 'Successfully Saved', 'data' => $rma->fresh('lines')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/rma/delete');

        $rma = RmaRequest::query()->visibleToCurrentUser()->findOrFail($id);

        if ($rma->status !== 'requested') {
            throw ValidationException::withMessages(['status' => ['Only a request that has not been authorised can be deleted.']]);
        }

        $rma->lines()->delete();
        $rma->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }
}
