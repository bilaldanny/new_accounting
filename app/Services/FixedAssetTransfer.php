<?php

namespace App\Services;

use App\Models\AssetCategory;
use App\Models\AssetEvent;
use App\Models\FixedAsset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moving an asset from one branch to another (Accounts & Finance Phase 3). Ledger reports are filtered by the
 * branch of the voucher, so each branch needs its own entry: the branch it leaves credits the asset's cost, debits its
 * accumulated depreciation and debits the category's inter-branch transfer account with the net book value, and the
 * branch it joins posts the mirror image. Across the company the transfer account nets to nothing and no balance
 * changes. Both vouchers follow the company's journal approval setting. The asset keeps its code, cost, accumulated
 * depreciation and depreciation settings; only its branch changes.
 */
class FixedAssetTransfer
{
    public function __construct(private readonly FixedAssetJournal $journal) {}

    public function transfer(FixedAsset $asset, int $toBranchId, string $date, ?string $note, ?int $toCostCenterId = null): FixedAsset
    {
        return DB::transaction(function () use ($asset, $toBranchId, $date, $note, $toCostCenterId): FixedAsset {
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $category = $asset->category;

            if ($asset->status !== FixedAsset::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['asset' => ['Only an asset in service can be transferred.']]);
            }

            if ((int) $asset->branch_id === $toBranchId) {
                throw ValidationException::withMessages(['to_branch_id' => ['The asset is already in that branch.']]);
            }

            if ($date < $asset->acquired_on->toDateString()) {
                throw ValidationException::withMessages(['date' => ['The transfer cannot be dated before the asset was acquired.']]);
            }

            if ($category->transfer_coa_id === null) {
                throw ValidationException::withMessages(['asset' => ['This category has no inter-branch transfer account. Add one to the category first.']]);
            }

            $cost = round((float) $asset->cost, 2);
            $accumulated = round((float) $asset->accumulated_depreciation, 2);
            $netBook = round($cost - $accumulated, 2);
            $fromBranchId = (int) $asset->branch_id;
            $label = $asset->code.' '.$asset->name;

            $out = $this->journal->post((int) $asset->company_id, $fromBranchId, $date, $this->lines($category, $cost, $accumulated, $netBook, outgoing: true), 'Asset transferred out: '.$label, $asset->code);
            $in = $this->journal->post((int) $asset->company_id, $toBranchId, $date, $this->lines($category, $cost, $accumulated, $netBook, outgoing: false), 'Asset transferred in: '.$label, $asset->code);

            $fromCostCenterId = $asset->cost_center_id;
            $asset->update(['branch_id' => $toBranchId, 'cost_center_id' => $toCostCenterId ?? $asset->cost_center_id, 'updated_by' => Auth::id()]);

            AssetEvent::query()->create([
                'company_id' => $asset->company_id,
                'branch_id' => $toBranchId,
                'fixed_asset_id' => $asset->id,
                'type' => AssetEvent::TYPE_TRANSFER,
                'status' => AssetEvent::STATUS_POSTED,
                'event_date' => $date,
                'amount' => $netBook,
                'description' => $note !== null && $note !== '' ? $note : 'Transferred between branches',
                't_account_id' => $in->id,
                'payload' => ['from_branch_id' => $fromBranchId, 'to_branch_id' => $toBranchId, 'out_voucher_id' => $out->id, 'in_voucher_id' => $in->id, 'cost' => $cost, 'accumulated_depreciation' => $accumulated, 'from_cost_center_id' => $fromCostCenterId, 'to_cost_center_id' => $asset->cost_center_id],
                'created_by' => Auth::id(),
            ]);

            return $asset->refresh();
        });
    }

    /**
     * @return list<array{account_id: int, debit: float, credit: float}>
     */
    private function lines(AssetCategory $category, float $cost, float $accumulated, float $netBook, bool $outgoing): array
    {
        $lines = $outgoing
            ? [
                ['account_id' => (int) $category->asset_coa_id, 'debit' => 0.0, 'credit' => $cost],
                ['account_id' => (int) $category->accumulated_coa_id, 'debit' => $accumulated, 'credit' => 0.0],
                ['account_id' => (int) $category->transfer_coa_id, 'debit' => $netBook, 'credit' => 0.0],
            ]
            : [
                ['account_id' => (int) $category->asset_coa_id, 'debit' => $cost, 'credit' => 0.0],
                ['account_id' => (int) $category->accumulated_coa_id, 'debit' => 0.0, 'credit' => $accumulated],
                ['account_id' => (int) $category->transfer_coa_id, 'debit' => 0.0, 'credit' => $netBook],
            ];

        // A zero line (nothing depreciated yet) cannot be a journal line.
        return array_values(array_filter($lines, fn (array $line): bool => $line['debit'] > 0.0 || $line['credit'] > 0.0));
    }
}
