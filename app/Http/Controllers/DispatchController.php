<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Contact;
use App\Models\DispatchAssignment;
use App\Models\InstallmentPlan;
use App\Models\Rider;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Riders (master data) and the dispatch board: posted sales still to be delivered, with the rider each is handed to. Moving an
 * assignment moves the sale's own shipping status (out = shipped, delivered = delivered), a delivery step that does not touch the
 * books. A sale held by an unpaid layaway plan cannot be dispatched.
 */
class DispatchController extends Controller
{
    use ScopesToCompany;

    public function riders(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/rider');

        return response()->json(['data' => Rider::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))->orderBy('name')->get()]);
    }

    public function storeRider(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/rider/add');
        $companyId = $this->scopeCompanyId($request);

        $data = $request->validate(['name' => 'bail|required|string|min:2|max:120', 'phone' => 'nullable|string|max:40', 'vehicle_no' => 'nullable|string|max:40', 'is_active' => 'nullable|boolean', 'company_id' => 'nullable|integer', 'branch_id' => 'nullable|integer']);
        $rider = Rider::query()->create(collect($data)->only(['name', 'phone', 'vehicle_no', 'is_active'])->all() + ['company_id' => $companyId, 'branch_id' => $this->scopeBranchId($request, $companyId)]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $rider], 201);
    }

    public function updateRider(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/rider/:id/edit');

        $data = $request->validate(['name' => 'bail|required|string|min:2|max:120', 'phone' => 'nullable|string|max:40', 'vehicle_no' => 'nullable|string|max:40', 'is_active' => 'nullable|boolean']);
        $rider = Rider::query()->visibleToCurrentUser()->findOrFail($id);
        $rider->update($data);

        return response()->json(['message' => 'Successfully Saved', 'data' => $rider->fresh()]);
    }

    public function destroyRider(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/rider/delete');

        $rider = Rider::query()->visibleToCurrentUser()->findOrFail($id);

        if (DispatchAssignment::query()->where('rider_id', $rider->id)->whereIn('status', ['assigned', 'out'])->exists()) {
            throw ValidationException::withMessages(['rider' => ['This rider still has deliveries out.']]);
        }

        $rider->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function board(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dispatch');

        $companyId = $this->scopeCompanyId($request);

        $sales = Transaction::query()
            ->sells()
            ->where('company_id', $companyId)
            ->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)
            ->where(fn ($q) => $q->whereNull('shipping_status')->orWhereIn('shipping_status', ['ordered', 'packed', 'shipped']))
            ->whereDate('transaction_date', '>=', now()->subDays(60)->toDateString())
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'invoice_no', 'contact_id', 'transaction_date', 'final_amount', 'shipping_status', 'shipping_address', 'branch_id']);

        $assignments = DispatchAssignment::query()->whereIn('transaction_id', $sales->pluck('id'))->get()->keyBy('transaction_id');
        $riders = Rider::query()->whereIn('id', $assignments->pluck('rider_id'))->pluck('name', 'id');
        $names = Contact::query()->whereIn('id', $sales->pluck('contact_id'))->pluck('business_name', 'id');
        $held = InstallmentPlan::query()->whereIn('transaction_id', $sales->pluck('id'))->where('kind', 'layaway')->where('status', 'active')->pluck('transaction_id')->flip();

        return response()->json(['data' => $sales->map(function (Transaction $sale) use ($assignments, $riders, $names, $held): array {
            $assignment = $assignments->get($sale->id);

            return [
                'id' => $sale->id, 'invoice_no' => $sale->invoice_no, 'customer' => $names[$sale->contact_id] ?? null,
                'date' => $sale->transaction_date?->toDateString(), 'amount' => round((float) $sale->final_amount, 2),
                'address' => $sale->shipping_address, 'shipping_status' => $sale->shipping_status, 'held_by_layaway' => $held->has($sale->id),
                'assignment_id' => $assignment?->id, 'rider_id' => $assignment?->rider_id, 'rider' => $assignment === null ? null : ($riders[$assignment->rider_id] ?? null),
                'dispatch_status' => $assignment?->status ?? 'unassigned',
            ];
        })->values()]);
    }

    public function assign(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dispatch/assign');

        $data = $request->validate(['transaction_id' => 'required|integer', 'rider_id' => 'required|integer', 'note' => 'nullable|string|max:255']);

        $sale = Transaction::query()->sells()->visibleToCurrentUser()->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)->find($data['transaction_id']);
        $rider = Rider::query()->visibleToCurrentUser()->where('is_active', true)->find($data['rider_id']);

        if ($sale === null) {
            throw ValidationException::withMessages(['transaction_id' => ['Choose a posted sale you can see.']]);
        }

        if ($rider === null || (int) $rider->company_id !== (int) $sale->company_id) {
            throw ValidationException::withMessages(['rider_id' => ['Choose an active rider of this company.']]);
        }

        if (InstallmentPlan::query()->where('transaction_id', $sale->id)->where('kind', 'layaway')->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['transaction_id' => ['This sale is held until its layaway plan is paid up.']]);
        }

        $assignment = DispatchAssignment::query()->updateOrCreate(
            ['transaction_id' => $sale->id],
            ['company_id' => $sale->company_id, 'rider_id' => $rider->id, 'status' => 'assigned', 'assigned_at' => now(), 'out_at' => null, 'delivered_at' => null, 'note' => $data['note'] ?? null, 'assigned_by' => Auth::id()],
        );

        return response()->json(['message' => 'Successfully Saved', 'data' => $assignment], 201);
    }

    public function move(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/dispatch/assign');

        $data = $request->validate(['status' => 'required|in:'.implode(',', DispatchAssignment::STATUSES), 'note' => 'nullable|string|max:255']);

        $assignment = DispatchAssignment::query()->visibleToCurrentUser()->findOrFail($id);

        DB::transaction(function () use ($assignment, $data): void {
            $assignment->fill(['status' => $data['status'], 'note' => $data['note'] ?? $assignment->note]);

            if ($data['status'] === 'out') {
                $assignment->out_at = now();
            }

            if ($data['status'] === 'delivered') {
                $assignment->delivered_at = now();
            }

            $assignment->save();

            $shipping = ['out' => 'shipped', 'delivered' => 'delivered', 'assigned' => 'packed'][$data['status']] ?? null;

            if ($shipping !== null) {
                Transaction::query()->whereKey($assignment->transaction_id)->update(['shipping_status' => $shipping]);
            }
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $assignment->fresh()]);
    }
}
