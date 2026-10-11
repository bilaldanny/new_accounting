<?php

namespace App\Http\Controllers;

use App\Models\InboundShipment;
use App\Models\InboundShipmentEvent;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Inbound shipment and receiving tracking: one or more shipments per purchase with carrier, tracking and container numbers,
 * dates (shipped, ETA, arrived) and a status history. Late shipments (ETA passed, not arrived) are flagged.
 */
class InboundShipmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/inboundshipment');

        $rows = InboundShipment::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('late'), fn ($q) => $q->whereNotNull('eta')->whereDate('eta', '<', now()->toDateString())->whereNotIn('status', ['arrived', 'received', 'cancelled']))
            ->orderByRaw('case when eta is null then 1 else 0 end, eta')
            ->paginate(min($request->integer('per_page', 25), 100));

        $rows->getCollection()->transform(function (InboundShipment $shipment): InboundShipment {
            $shipment->setAttribute('is_late', $shipment->eta !== null && $shipment->eta->toDateString() < now()->toDateString() && ! in_array($shipment->status, ['arrived', 'received', 'cancelled'], true));

            return $shipment;
        });

        return response()->json(['data' => $rows]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/inboundshipment');

        return response()->json(InboundShipment::query()->visibleToCurrentUser()->with('events')->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/inboundshipment/add');

        $data = $request->validate([
            'transaction_id' => 'required|integer',
            'carrier' => 'nullable|string|max:150',
            'tracking_no' => 'nullable|string|max:100',
            'container_no' => 'nullable|string|max:100',
            'status' => ['nullable', Rule::in(InboundShipment::STATUSES)],
            'shipped_on' => 'nullable|date_format:Y-m-d',
            'eta' => 'nullable|date_format:Y-m-d',
            'note' => 'nullable|string|max:1000',
        ]);

        $purchase = Transaction::query()->visibleToCurrentUser()->where('type', Transaction::TYPE_PURCHASE)->findOrFail($data['transaction_id']);

        $shipment = DB::transaction(function () use ($data, $purchase): InboundShipment {
            $shipment = InboundShipment::query()->create($data + [
                'company_id' => $purchase->company_id, 'branch_id' => $purchase->branch_id, 'shipment_no' => 'TMP',
                'status' => $data['status'] ?? 'ordered', 'created_by' => Auth::id(),
            ]);
            $shipment->forceFill(['shipment_no' => 'IS-'.str_pad((string) $shipment->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
            InboundShipmentEvent::query()->create(['inbound_shipment_id' => $shipment->id, 'status' => $shipment->status, 'occurred_at' => now(), 'created_by' => Auth::id()]);

            return $shipment;
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $shipment->load('events')], 201);
    }

    /**
     * Changes the details and, when a status is sent, adds it to the history.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/inboundshipment/:id/edit');

        $data = $request->validate([
            'carrier' => 'nullable|string|max:150',
            'tracking_no' => 'nullable|string|max:100',
            'container_no' => 'nullable|string|max:100',
            'status' => ['nullable', Rule::in(InboundShipment::STATUSES)],
            'shipped_on' => 'nullable|date_format:Y-m-d',
            'eta' => 'nullable|date_format:Y-m-d',
            'arrived_on' => 'nullable|date_format:Y-m-d',
            'location' => 'nullable|string|max:150',
            'event_note' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
        ]);

        $shipment = InboundShipment::query()->visibleToCurrentUser()->findOrFail($id);
        $statusChanged = isset($data['status']) && $data['status'] !== $shipment->status;

        DB::transaction(function () use ($shipment, $data, $statusChanged): void {
            $shipment->fill(collect($data)->except(['location', 'event_note'])->all());

            if ($statusChanged && in_array($data['status'], ['arrived', 'received'], true) && $shipment->arrived_on === null) {
                $shipment->arrived_on = now()->toDateString();
            }

            $shipment->save();

            if ($statusChanged) {
                InboundShipmentEvent::query()->create([
                    'inbound_shipment_id' => $shipment->id, 'status' => $data['status'], 'location' => $data['location'] ?? null,
                    'note' => $data['event_note'] ?? null, 'occurred_at' => now(), 'created_by' => Auth::id(),
                ]);
            }
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $shipment->fresh('events')]);
    }
}
