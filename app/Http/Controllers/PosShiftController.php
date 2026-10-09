<?php

namespace App\Http\Controllers;

use App\Models\PosShift;
use App\Services\PosShiftReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POS Shift & Cash Drawer Management: open a shift with a float, record pay-ins / pay-outs, read the X
 * report while it is open, close it with the cash counted (the Z report), and list past shifts.
 */
class PosShiftController extends Controller
{
    public function __construct(private readonly PosShiftReport $report) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/posshift');

        $shifts = PosShift::query()
            ->visibleToCurrentUser()
            ->with(['user:id,first_name,last_name', 'branch:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->orderByDesc('id')
            ->paginate(min((int) ($request->input('show_record') ?: 15), 100));

        $shifts->getCollection()->transform(fn (PosShift $shift) => $shift->presentForIndex());

        return response()->json(['data' => $shifts]);
    }

    /**
     * The signed-in user's open shift with its live X report, or null.
     */
    public function current(): JsonResponse
    {
        $this->authorizeMenuPermission('/posshift');

        $shift = PosShift::currentForUser();

        return response()->json(['data' => $shift === null ? null : $this->detail($shift)]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/posshift');

        $shift = PosShift::query()->visibleToCurrentUser()->findOrFail($id);

        return response()->json(['data' => $this->detail($shift)]);
    }

    public function open(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/posshift/open');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'opening_float' => 'required|numeric|min:0|max:99999999',
            'note' => 'nullable|string|max:500',
        ]);

        $shift = PosShift::openShift($data['company_id'] ?? null, $data['branch_id'] ?? null, (float) $data['opening_float'], $data['note'] ?? null);

        return response()->json(['message' => 'Shift opened', 'data' => $this->detail($shift)]);
    }

    public function movement(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/posshift/movement');

        $data = $request->validate([
            'type' => 'required|in:in,out',
            'amount' => 'required|numeric|gt:0|max:99999999',
            'reason' => 'required|string|max:255',
        ]);

        $shift = PosShift::query()->visibleToCurrentUser()->findOrFail($id);
        $shift->addMovement($data['type'], (float) $data['amount'], $data['reason']);

        return response()->json(['message' => 'Saved', 'data' => $this->detail($shift->refresh())]);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/posshift/close');

        $data = $request->validate([
            'counted_cash' => 'required|numeric|min:0|max:99999999',
            'note' => 'nullable|string|max:500',
        ]);

        $shift = PosShift::query()->visibleToCurrentUser()->findOrFail($id);
        $shift->closeShift((float) $data['counted_cash'], $data['note'] ?? null);

        return response()->json(['message' => 'Shift closed', 'data' => $this->detail($shift->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(PosShift $shift): array
    {
        $shift->loadMissing(['user:id,first_name,last_name', 'branch:id,name']);

        return $shift->presentForIndex() + [
            'note' => $shift->note,
            'report_type' => $shift->isOpen() ? 'X' : 'Z',
            'report' => $shift->isOpen() ? $this->report->build($shift, now()) : $shift->summary,
            'movements' => $shift->movements()->orderBy('id')->get(['id', 'type', 'amount', 'reason', 'created_at'])->all(),
        ];
    }
}
