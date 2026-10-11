<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\InventoryCloseEntry;
use App\Services\InventoryClose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Period-end inventory closing lock (see App\Services\InventoryClose): close the stock through a date so no stock document
 * dated on or before it can change, and reopen it (moving the date back or clearing it) with a reason. Every step is kept.
 */
class InventoryCloseController extends Controller
{
    use ScopesToCompany;

    public function show(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/inventoryclose');

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        return response()->json([
            'locked_through' => app(InventoryClose::class)->lockedThrough($companyId, $branchId),
            'history' => InventoryCloseEntry::query()
                ->where('company_id', $companyId)
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'branch_id', 'locked_through', 'action', 'reason', 'created_by', 'created_at']),
        ]);
    }

    /**
     * Closes inventory through a date; it can only move the close forward (use reopen to move it back).
     */
    public function close(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/inventoryclose/add');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'locked_through' => 'required|date_format:Y-m-d|before_or_equal:today',
            'reason' => 'nullable|string|max:500',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);
        $current = app(InventoryClose::class)->lockedThrough($companyId, $branchId);

        if ($current !== null && $data['locked_through'] <= $current) {
            throw ValidationException::withMessages(['locked_through' => ["Inventory is already closed through {$current}. Reopen it to move the date back."]]);
        }

        $entry = InventoryCloseEntry::query()->create([
            'company_id' => $companyId, 'branch_id' => $branchId, 'locked_through' => $data['locked_through'], 'action' => 'close',
            'reason' => $data['reason'] ?? null, 'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Inventory closed', 'data' => $entry], 201);
    }

    /**
     * Moves the close back to an earlier date, or clears it, with a reason.
     */
    public function reopen(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/inventoryclose/reopen');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'locked_through' => 'nullable|date_format:Y-m-d',
            'reason' => 'required|string|min:3|max:500',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);
        $current = app(InventoryClose::class)->lockedThrough($companyId, $branchId);

        if ($current === null) {
            throw ValidationException::withMessages(['locked_through' => ['Inventory is not closed.']]);
        }

        if (($data['locked_through'] ?? null) !== null && $data['locked_through'] >= $current) {
            throw ValidationException::withMessages(['locked_through' => ["Choose a date before {$current}, or leave it empty to open everything."]]);
        }

        $entry = InventoryCloseEntry::query()->create([
            'company_id' => $companyId, 'branch_id' => $branchId, 'locked_through' => $data['locked_through'] ?? null, 'action' => 'reopen',
            'reason' => $data['reason'], 'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Inventory reopened', 'data' => $entry], 201);
    }
}
