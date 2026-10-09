<?php

namespace App\Http\Controllers;

use App\Models\AssetEvent;
use App\Services\FixedAssetLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approval Center: approve or reject fixed-asset revaluations, impairments and disposals. Same shape as the Credit
 * Limit approval (the page's own path is the approve permission, `/assetapproval/reject` the reject one). Approving
 * posts the voucher and changes the asset (FixedAssetLifecycle); it is never done automatically.
 */
class AssetApprovalController extends Controller
{
    private const TYPE_LABELS = [
        AssetEvent::TYPE_REVALUATION => 'Revaluation',
        AssetEvent::TYPE_IMPAIRMENT => 'Impairment',
        AssetEvent::TYPE_DISPOSAL => 'Disposal',
    ];

    public function __construct(private readonly FixedAssetLifecycle $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/assetapproval');

        $user = $request->user();

        $events = AssetEvent::query()
            ->with(['asset:id,code,name,cost,accumulated_depreciation'])
            ->where('status', AssetEvent::STATUS_PENDING)
            ->whereIn('type', AssetEvent::APPROVAL_TYPES)
            ->when(! $user?->hasRole('superadmin'), fn ($q) => $q->where('company_id', (int) $user?->company_id))
            ->when($user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin'), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('asset', fn ($a) => $a->where('code', 'like', '%'.$request->input('search').'%')->orWhere('name', 'like', '%'.$request->input('search').'%')))
            ->orderByDesc('id')
            ->paginate(min((int) ($request->input('show_record') ?: 10), 100));

        $events->getCollection()->transform(fn (AssetEvent $event) => [
            'id' => $event->id,
            'type' => self::TYPE_LABELS[$event->type] ?? $event->type,
            'asset' => $event->asset?->code.' '.$event->asset?->name,
            'event_date' => $event->event_date?->toDateString(),
            'amount' => $event->amount,
            'detail' => $this->detail($event),
            'reason' => $event->description,
        ]);

        return response()->json(['data' => $events]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/assetapproval');

        $this->lifecycle->approve($this->visibleEvent($request, $id));

        return response()->json(['message' => 'Successfully Approved']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/assetapproval/reject');

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);
        $this->lifecycle->reject($this->visibleEvent($request, $id), $validated['reason'] ?? null);

        return response()->json(['message' => 'Successfully Rejected']);
    }

    private function visibleEvent(Request $request, int $id): AssetEvent
    {
        $user = $request->user();

        return AssetEvent::query()
            ->whereIn('type', AssetEvent::APPROVAL_TYPES)
            ->when(! $user?->hasRole('superadmin'), fn ($q) => $q->where('company_id', (int) $user?->company_id))
            ->findOrFail($id);
    }

    private function detail(AssetEvent $event): string
    {
        $payload = (array) $event->payload;
        $book = number_format((float) ($payload['book_value_at_request'] ?? 0), 2, '.', '');

        return match ($event->type) {
            AssetEvent::TYPE_REVALUATION => 'Book value '.$book.' to '.number_format((float) ($payload['new_value'] ?? 0), 2, '.', ''),
            AssetEvent::TYPE_IMPAIRMENT => 'Write off '.number_format((float) $event->amount, 2, '.', '').' of '.$book,
            default => 'Sold for '.number_format((float) $event->amount, 2, '.', '').' against book value '.$book,
        };
    }
}
