<?php

namespace App\Http\Controllers;

use App\Models\PosOfflineSale;
use App\Services\PosOfflineSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Offline POS (see Services\PosOfflineSync): the catalog a terminal caches, the replay of its offline sales, and the review of the
 * ones the server parked. The company and branch are always the signed-in user's.
 */
class PosOfflineController extends Controller
{
    public function __construct(private readonly PosOfflineSync $sync) {}

    public function catalog(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/sell/pos');

        $user = Auth::user();

        return response()->json($this->sync->catalog((int) $user->company_id, $user->branch_id === null ? null : (int) $user->branch_id));
    }

    public function sync(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/sell/pos');

        $data = $request->validate([
            'client_uuid' => 'required|string|min:16|max:64',
            'device_id' => 'nullable|string|max:64',
            'offline_receipt_no' => 'nullable|string|max:60',
            'sold_at' => 'required|date',
            'catalog_version' => 'nullable|string|max:64',
            'branch_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'total' => 'required|numeric|min:0',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.product_id' => 'required|integer',
            'lines.*.variation_id' => 'required|integer',
            'lines.*.unit_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount_percent' => 'nullable|numeric|min:0',
            'payments' => 'required|array|min:1|max:5',
            'payments.*.method' => 'required|string|max:20',
            'payments.*.payment_account' => 'required|integer',
            'payments.*.amount' => 'required|numeric|gt:0',
        ]);

        $result = $this->sync->sync(Auth::user(), $data);

        return response()->json($result['body'], $result['http']);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/posoffline');

        $rows = PosOfflineSale::query()->visibleToCurrentUser()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')->limit(300)
            ->get(['id', 'client_uuid', 'device_id', 'offline_receipt_no', 'sold_at', 'total', 'status', 'conflict_code', 'conflict_detail', 'oversold', 'transaction_id', 'invoice_no', 'error', 'synced_at', 'created_at']);

        return response()->json(['data' => $rows]);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/posoffline/resolve');

        $data = $request->validate(['action' => ['required', Rule::in(['accept', 'discard'])]]);
        $sale = PosOfflineSale::query()->visibleToCurrentUser()->findOrFail($id);
        $result = $this->sync->resolve($sale, $data['action']);

        return response()->json($result['body'], $result['http']);
    }
}
