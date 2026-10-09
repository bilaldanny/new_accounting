<?php

namespace App\Http\Controllers;

use App\Models\AssetCategory;
use App\Models\AssetEvent;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Services\FixedAssetAcquisition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Asset Acquisition & CWIP: buy an asset (or start building one), add construction costs, capitalise it, and read
 * an asset's history. The ledger side is posted by FixedAssetAcquisition through FixedAssetJournal.
 */
class FixedAssetAcquisitionController extends Controller
{
    public function __construct(private readonly FixedAssetAcquisition $acquisition) {}

    public function acquire(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset/acquire');

        $companyId = $this->companyId($request);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['asset', 'cwip'])],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'asset_category_id' => ['required', 'integer', Rule::exists('asset_categories', 'id')->where('company_id', $companyId)->where('active', true)],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:500',
            'serial_no' => 'nullable|string|max:100',
            'acquired_on' => 'required|date',
            'in_service_on' => 'nullable|date|after_or_equal:acquired_on',
            'cost' => 'required|numeric|gt:0|max:999999999999',
            'salvage_value' => 'nullable|numeric|min:0',
            'method' => ['nullable', Rule::in(AssetCategory::METHODS)],
            'useful_life_months' => 'nullable|integer|min:1|max:1200',
            'rate' => 'nullable|numeric|gt:0|max:100',
            'offset_coa_id' => ['required', 'integer', $this->postingAccount($companyId)],
            'reference' => 'nullable|string|max:100',
        ]);

        $this->assertBranchAllowed($request, (int) $data['branch_id']);

        $asset = $this->acquisition->acquire($companyId, $data);

        return response()->json(['message' => 'Asset acquired', 'data' => $asset->load(['category', 'branch:id,name'])->present()]);
    }

    public function cwipCost(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset/cwip');

        $asset = FixedAsset::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $request->validate([
            'date' => 'required|date|after_or_equal:'.$asset->acquired_on->toDateString(),
            'amount' => 'required|numeric|gt:0|max:999999999999',
            'offset_coa_id' => ['required', 'integer', $this->postingAccount((int) $asset->company_id)],
            'description' => 'required|string|max:255',
        ]);

        $asset = $this->acquisition->addCwipCost($asset, $data['date'], (float) $data['amount'], (int) $data['offset_coa_id'], $data['description']);

        return response()->json(['message' => 'Construction cost added', 'data' => $asset->load(['category', 'branch:id,name'])->present()]);
    }

    public function capitalise(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset/capitalise');

        $asset = FixedAsset::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $request->validate([
            'in_service_on' => 'required|date',
            'salvage_value' => 'nullable|numeric|min:0',
        ]);

        $asset = $this->acquisition->capitalise($asset, $data['in_service_on'], isset($data['salvage_value']) ? (float) $data['salvage_value'] : null);

        return response()->json(['message' => 'Asset capitalised', 'data' => $asset->load(['category', 'branch:id,name'])->present()]);
    }

    public function history(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset');

        $asset = FixedAsset::query()->visibleToCurrentUser()->findOrFail($id);

        $events = AssetEvent::query()->with('voucher:id,voucher_no,status')->where('fixed_asset_id', $asset->id)->orderBy('event_date')->orderBy('id')->get()
            ->map(fn (AssetEvent $event) => $event->present())->values();

        $depreciation = FixedAssetDepreciation::query()->with('voucher:id,voucher_no,status')->where('fixed_asset_id', $asset->id)->get()
            ->map(fn (FixedAssetDepreciation $row) => [
                'id' => $row->id,
                'fixed_asset_id' => $row->fixed_asset_id,
                'type' => 'depreciation',
                'status' => 'posted',
                'event_date' => $row->period_end?->toDateString(),
                'amount' => $row->amount,
                'description' => 'Depreciation '.$row->period.' (book value '.number_format($row->closing_book_value, 2, '.', '').')',
                'voucher_no' => $row->voucher?->voucher_no,
                'voucher_status' => $row->voucher?->status,
                'payload' => null,
            ]);

        $events = $events->concat($depreciation)->sortBy([['event_date', 'asc'], ['id', 'asc']])->values();

        return response()->json(['data' => ['asset' => $asset->load(['category', 'branch:id,name'])->present(), 'events' => $events]]);
    }

    private function companyId(Request $request): int
    {
        $companyId = (int) ($request->user()?->company_id ?: $request->integer('company_id'));

        if ($companyId === 0) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        return $companyId;
    }

    private function postingAccount(int $companyId): Exists
    {
        return Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)->where('acc_type', 't')->whereNull('deleted_at');
    }

    private function assertBranchAllowed(Request $request, int $branchId): void
    {
        $user = $request->user();

        if ($user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin') && (int) $user->branch_id !== $branchId) {
            throw ValidationException::withMessages(['branch_id' => ['You can only work on your own branch.']]);
        }
    }
}
