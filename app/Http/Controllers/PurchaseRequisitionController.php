<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\PurchaseRequisition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Purchase Requisitions: the internal "what do we need" request that comes before a Purchase Order.
 * Standalone table (PurchaseRequisition/PurchaseRequisitionLine) — no ledger effect, no `transactions`
 * row, until PurchaseController converts an approved one into a real Purchase Order.
 */
class PurchaseRequisitionController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function requisitionFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'bail|required',
            'contact_id' => 'nullable|integer',
            'requisition_date' => 'bail|required|date',
            'note' => 'nullable|string',
            'status' => 'nullable|in:draft,pending',
            'lines' => 'bail|required|array|min:1',
            'lines.*.product_id' => 'bail|required|integer',
            'lines.*.variation_id' => 'nullable|integer',
            'lines.*.unit_id' => 'bail|required|integer',
            'lines.*.requested_quantity' => 'bail|required|numeric|min:0.01',
            'lines.*.note' => 'nullable|string',
        ];
    }

    public function index(Request $request)
    {
        $this->authorizeMenuPermission('/purchaserequisition');

        $query = PurchaseRequisition::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'contact:id,business_name,first_name,last_name', 'requestedBy:id,first_name,last_name'])
            ->matchingListFilters($request->only(['status', 'search', 'company_id', 'branch_id']));

        $requisitions = $this->paginateSorted($query, $request);

        $requisitions->getCollection()->transform(fn (PurchaseRequisition $requisition) => $requisition->presentForIndex());

        return response()->json(['data' => $requisitions, 'trash_count' => 0]);
    }

    /**
     * Approved, not-yet-converted requisitions, for the "create Purchase Order from Requisition" picker.
     */
    public function eligible(Request $request)
    {
        $companyId = Auth::user()?->hasRole('superadmin')
            ? PurchaseRequisition::resolveScopedId($request->company_id)
            : PurchaseRequisition::resolveScopedId(Auth::user()?->company_id ?? $request->company_id);

        return response()->json(PurchaseRequisition::eligible(
            $companyId,
            PurchaseRequisition::resolveScopedId($request->branch_id),
        ));
    }

    /**
     * A requisition's lines, shaped to prefill the Purchase Order form.
     */
    public function linesForConversion(int $id)
    {
        $requisition = PurchaseRequisition::findVisible($id);

        if ($requisition === null) {
            abort(404);
        }

        return response()->json([
            'id' => $requisition->id,
            'requisition_no' => $requisition->requisition_no,
            'lines' => $requisition->linesForConversion(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeMenuPermission('/purchaserequisition/add');

        $request->validate($this->requisitionFormRules());

        DB::beginTransaction();
        try {
            PurchaseRequisition::createRequisition($request);
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
        $requisition = PurchaseRequisition::findVisible((int) $id);

        if ($requisition === null) {
            abort(404);
        }

        return response()->json($requisition->presentForForm());
    }

    public function update(Request $request, $id)
    {
        $this->authorizeMenuPermission('/purchaserequisition/:id/edit');

        $request->validate($this->requisitionFormRules());

        DB::beginTransaction();
        try {
            PurchaseRequisition::updateRequisition($request, (int) $id);
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
        if (! deletepermission('/purchaserequisition/delete')) {
            return response()->json('406');
        }

        PurchaseRequisition::deleteRequisition((int) $id);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function bulk_delete(Request $request)
    {
        return $this->guardedBulkAction('/purchaserequisition/delete', 'Successfully Deleted', function () use ($request) {
            $ids = PurchaseRequisition::query()
                ->visibleToCurrentUser()
                ->whereIn('status', PurchaseRequisition::EDITABLE_STATUSES)
                ->whereIn('id', $request->all())
                ->pluck('id');

            PurchaseRequisition::whereIn('id', $ids)->delete();
        });
    }
}
