<?php

namespace App\Http\Controllers;

use App\Models\FixedAsset;
use App\Services\FixedAssetTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asset Transfer: move an asset to another branch of the same company.
 */
class FixedAssetTransferController extends Controller
{
    public function __construct(private readonly FixedAssetTransfer $transfers) {}

    public function store(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset/transfer');

        $asset = FixedAsset::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $request->validate([
            'to_branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $asset->company_id)],
            'to_cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('company_id', $asset->company_id)->whereNull('deleted_at')],
            'date' => 'required|date',
            'note' => 'nullable|string|max:500',
        ]);

        $asset = $this->transfers->transfer($asset, (int) $data['to_branch_id'], $data['date'], $data['note'] ?? null, isset($data['to_cost_center_id']) ? (int) $data['to_cost_center_id'] : null);

        return response()->json(['message' => 'Asset transferred', 'data' => $asset->load(['category', 'branch:id,name'])->present()]);
    }
}
