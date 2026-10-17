<?php

namespace App\Http\Controllers;

use App\Models\OfflineSyncEntry;
use App\Models\PosOfflineSale;
use App\Services\OfflineSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Offline mode, the endpoints a device uses (design: docs/offline-mode-design.md). The heartbeat is public (a device checks the
 * network with it); everything else needs a signed-in user and the Offline menu, and every row is the user's own company's: a user
 * without the resolve permission sees only their own entries.
 */
class OfflineSyncController extends Controller
{
    public function __construct(private readonly OfflineSync $sync) {}

    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true, 'time' => now()->toIso8601String()])->header('Cache-Control', 'no-store');
    }

    public function reference(): JsonResponse
    {
        $this->authorizeMenuPermission('/offline');

        return response()->json(['data' => $this->sync->reference(Auth::user())])->header('Cache-Control', 'no-store');
    }

    public function sync(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/offline');
        abort_if(strlen((string) $request->getContent()) > OfflineSync::MAX_PAYLOAD_BYTES, 413, 'This entry is too large to send.');

        $data = $request->validate([
            'client_uuid' => 'required|string|min:8|max:64', 'kind' => ['required', Rule::in(OfflineSync::KINDS)], 'payload' => 'required|array',
            'device_id' => 'nullable|string|max:64', 'provisional_ref' => 'nullable|string|max:60', 'created_at' => 'nullable|date',
        ]);

        $result = $this->sync->sync(Auth::user(), $data);

        return response()->json($result['body'], $result['http']);
    }

    public function entries(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/offline');
        $request->validate(['status' => ['nullable', Rule::in(['conflict', 'synced', 'discarded'])]]);

        $rows = OfflineSyncEntry::query()->visibleToCurrentUser()
            ->when(! hasMenuPermission('/offline/resolve'), fn ($q) => $q->where('user_id', Auth::id()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id')->limit(200)->get();

        $pos = PosOfflineSale::query()->visibleToCurrentUser()->where('status', 'conflict')->count();

        return response()->json(['data' => $rows->map(fn (OfflineSyncEntry $e): array => [
            'id' => $e->id, 'client_uuid' => $e->client_uuid, 'kind' => $e->kind, 'status' => $e->status, 'provisional_ref' => $e->provisional_ref, 'real_ref' => $e->real_ref,
            'conflict_code' => $e->conflict_code, 'error' => $e->error, 'user_id' => $e->user_id, 'created_at_client' => $e->created_at_client?->toIso8601String(),
            'processed_at' => $e->processed_at?->toIso8601String(), 'payload' => $e->payload,
        ])->values(), 'pos_conflicts' => $pos]);
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/offline');
        $data = $request->validate(['payload' => 'nullable|array']);
        $entry = OfflineSyncEntry::query()->visibleToCurrentUser()->findOrFail($id);

        $result = $this->sync->retry($entry, Auth::user(), $data['payload'] ?? null);

        return response()->json($result['body'], $result['http']);
    }

    public function discard(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/offline');
        $data = $request->validate(['reason' => 'required|string|min:3|max:300', 'confirm' => 'required|accepted']);
        $entry = OfflineSyncEntry::query()->visibleToCurrentUser()->findOrFail($id);

        $this->sync->discard($entry, Auth::user(), $data['reason']);

        return response()->json(['message' => 'The entry was discarded and the audit log has it.']);
    }
}
