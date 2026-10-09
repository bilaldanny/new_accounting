<?php

namespace App\Services;

use App\Models\AssetCategory;
use App\Models\AssetEvent;
use App\Models\FixedAsset;
use App\Models\TAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buying an asset and building one (Accounts & Finance Phase 3). An acquisition debits the category's asset account
 * and credits the account it was paid from (bank, cash or a payable). Construction in progress debits the category's
 * CWIP account instead, collects further costs the same way, and is capitalised once: the total moves from CWIP to the
 * asset account and depreciation starts from the in-service date. Every posting is a journal voucher made by
 * FixedAssetJournal, so it follows the company's journal approval setting.
 */
class FixedAssetAcquisition
{
    public function __construct(private readonly FixedAssetJournal $journal) {}

    /**
     * @param  array<string, mixed>  $data  Validated by FixedAssetAcquisitionController.
     */
    public function acquire(int $companyId, array $data): FixedAsset
    {
        $category = AssetCategory::query()->where('company_id', $companyId)->findOrFail($data['asset_category_id']);
        $isCwip = ($data['mode'] ?? 'asset') === 'cwip';

        if ($isCwip && $category->cwip_coa_id === null) {
            throw ValidationException::withMessages(['asset_category_id' => ['This category has no capital work in progress account. Add one to the category first.']]);
        }

        $data = FixedAsset::applyCategoryDefaults($data + ['accumulated_depreciation' => 0], $category);

        if ($isCwip) {
            $data['salvage_value'] = 0;
            $data['in_service_on'] = null;
        }

        FixedAsset::assertDepreciationInputs($data, checkSalvage: ! $isCwip);

        return DB::transaction(function () use ($companyId, $category, $data, $isCwip): FixedAsset {
            $asset = FixedAsset::query()->create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'],
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'asset_category_id' => $category->id,
                'code' => FixedAsset::nextCode($companyId),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'serial_no' => $data['serial_no'] ?? null,
                'status' => $isCwip ? FixedAsset::STATUS_CWIP : FixedAsset::STATUS_ACTIVE,
                'source' => $isCwip ? 'cwip' : 'purchase',
                'acquired_on' => $data['acquired_on'],
                'in_service_on' => $data['in_service_on'],
                'cost' => $data['cost'],
                'salvage_value' => $data['salvage_value'],
                'accumulated_depreciation' => 0,
                'method' => $data['method'],
                'useful_life_months' => $data['useful_life_months'],
                'rate' => $data['rate'],
                'created_by' => Auth::id(),
            ]);

            $voucher = $this->journal->post(
                $companyId,
                (int) $asset->branch_id,
                (string) $data['acquired_on'],
                [
                    ['account_id' => (int) ($isCwip ? $category->cwip_coa_id : $category->asset_coa_id), 'debit' => (float) $data['cost'], 'credit' => 0.0],
                    ['account_id' => (int) $data['offset_coa_id'], 'debit' => 0.0, 'credit' => (float) $data['cost']],
                ],
                ($isCwip ? 'Construction started: ' : 'Asset acquired: ').$asset->code.' '.$asset->name,
                $data['reference'] ?? $asset->code,
            );

            $this->record($asset, AssetEvent::TYPE_ACQUISITION, (string) $data['acquired_on'], (float) $data['cost'], $voucher, ($isCwip ? 'Construction in progress started' : 'Acquired').' for '.number_format((float) $data['cost'], 2, '.', ''));

            return $asset;
        });
    }

    /**
     * A further cost of a construction in progress.
     */
    public function addCwipCost(FixedAsset $asset, string $date, float $amount, int $offsetCoaId, string $description): FixedAsset
    {
        return DB::transaction(function () use ($asset, $date, $amount, $offsetCoaId, $description): FixedAsset {
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);

            if ($asset->status !== FixedAsset::STATUS_CWIP) {
                throw ValidationException::withMessages(['asset' => ['Only an asset still in progress can take more construction costs.']]);
            }

            $category = $asset->category;

            $voucher = $this->journal->post(
                (int) $asset->company_id,
                (int) $asset->branch_id,
                $date,
                [
                    ['account_id' => (int) $category->cwip_coa_id, 'debit' => $amount, 'credit' => 0.0],
                    ['account_id' => $offsetCoaId, 'debit' => 0.0, 'credit' => $amount],
                ],
                'Construction cost: '.$asset->code.' '.$description,
                $asset->code,
            );

            $asset->update(['cost' => round((float) $asset->cost + $amount, 2), 'updated_by' => Auth::id()]);
            $this->record($asset, AssetEvent::TYPE_CWIP_COST, $date, $amount, $voucher, $description);

            return $asset->refresh();
        });
    }

    /**
     * Moves the whole construction cost to the asset account and starts depreciation.
     */
    public function capitalise(FixedAsset $asset, string $inServiceOn, ?float $salvageValue = null): FixedAsset
    {
        return DB::transaction(function () use ($asset, $inServiceOn, $salvageValue): FixedAsset {
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);

            if ($asset->status !== FixedAsset::STATUS_CWIP) {
                throw ValidationException::withMessages(['asset' => ['Only an asset still in progress can be capitalised.']]);
            }

            if ($inServiceOn < $asset->acquired_on->toDateString()) {
                throw ValidationException::withMessages(['in_service_on' => ['The in-service date cannot be before construction started.']]);
            }

            $category = $asset->category;
            $salvage = $salvageValue ?? round((float) $asset->cost * (float) $category->salvage_percent / 100, 2);

            if ($salvage >= (float) $asset->cost) {
                throw ValidationException::withMessages(['salvage_value' => ['The salvage value must be below the cost.']]);
            }

            $voucher = $this->journal->post(
                (int) $asset->company_id,
                (int) $asset->branch_id,
                $inServiceOn,
                [
                    ['account_id' => (int) $category->asset_coa_id, 'debit' => (float) $asset->cost, 'credit' => 0.0],
                    ['account_id' => (int) $category->cwip_coa_id, 'debit' => 0.0, 'credit' => (float) $asset->cost],
                ],
                'Capitalised: '.$asset->code.' '.$asset->name,
                $asset->code,
            );

            $asset->update([
                'status' => FixedAsset::STATUS_ACTIVE,
                'in_service_on' => $inServiceOn,
                'salvage_value' => $salvage,
                'updated_by' => Auth::id(),
            ]);
            $this->record($asset, AssetEvent::TYPE_CAPITALISATION, $inServiceOn, (float) $asset->cost, $voucher, 'Capitalised and put in service');

            return $asset->refresh();
        });
    }

    private function record(FixedAsset $asset, string $type, string $date, float $amount, TAccount $voucher, string $description): AssetEvent
    {
        return AssetEvent::query()->create([
            'company_id' => $asset->company_id,
            'branch_id' => $asset->branch_id,
            'fixed_asset_id' => $asset->id,
            'type' => $type,
            'status' => AssetEvent::STATUS_POSTED,
            'event_date' => $date,
            'amount' => $amount,
            'description' => $description,
            't_account_id' => $voucher->id,
            'created_by' => Auth::id(),
        ]);
    }
}
