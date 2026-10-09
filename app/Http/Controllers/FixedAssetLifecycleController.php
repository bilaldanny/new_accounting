<?php

namespace App\Http\Controllers;

use App\Models\FixedAsset;
use App\Services\FixedAssetLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asking for a revaluation, an impairment or a disposal. Each only records a pending request; the Approval Center
 * (AssetApprovalController) is where it is approved and posted.
 */
class FixedAssetLifecycleController extends Controller
{
    public function __construct(private readonly FixedAssetLifecycle $lifecycle) {}

    public function revalue(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset/revalue');

        $asset = FixedAsset::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $request->validate([
            'date' => 'required|date',
            'new_value' => 'required|numeric|gt:0|max:999999999999',
            'reason' => 'required|string|max:500',
        ]);

        $event = $this->lifecycle->requestRevaluation($asset, $data['date'], (float) $data['new_value'], $data['reason']);

        return response()->json(['message' => 'Revaluation sent for approval', 'data' => $event->present()]);
    }

    public function impair(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset/impair');

        $asset = FixedAsset::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $request->validate([
            'date' => 'required|date',
            'amount' => 'required|numeric|gt:0|max:999999999999',
            'reason' => 'required|string|max:500',
        ]);

        $event = $this->lifecycle->requestImpairment($asset, $data['date'], (float) $data['amount'], $data['reason']);

        return response()->json(['message' => 'Impairment sent for approval', 'data' => $event->present()]);
    }

    public function dispose(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset/dispose');

        $asset = FixedAsset::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $request->validate([
            'date' => 'required|date',
            'proceeds' => 'required|numeric|min:0|max:999999999999',
            'proceeds_coa_id' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')->where('company_id', $asset->company_id)->where('acc_type', 't')->whereNull('deleted_at')],
            'reason' => 'required|string|max:500',
        ]);

        $event = $this->lifecycle->requestDisposal($asset, $data['date'], (float) $data['proceeds'], isset($data['proceeds_coa_id']) ? (int) $data['proceeds_coa_id'] : null, $data['reason']);

        return response()->json(['message' => 'Disposal sent for approval', 'data' => $event->present()]);
    }
}
