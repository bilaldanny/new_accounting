<?php

namespace App\Services;

use App\Models\AssetCategory;
use App\Models\AssetEvent;
use App\Models\FixedAsset;
use App\Models\TAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revaluation, impairment and disposal of a fixed asset (Accounts & Finance Phase 3). None of them is ever
 * auto-approved: asking for one only records a pending AssetEvent. It touches neither the ledger nor the asset until
 * someone with the approve permission approves it in the Approval Center; then it posts an approved journal voucher
 * and changes the asset in one transaction. Rejecting it changes nothing.
 *
 *  - Revaluation (upwards only): cost goes up by the difference between the new value and today's book value; the
 *    difference is credited to the category's revaluation surplus account. A fall in value is an impairment.
 *  - Impairment: debit the impairment loss account, credit accumulated depreciation, for the amount written off.
 *  - Disposal: debit the account the proceeds went to, debit accumulated depreciation, credit the asset's cost, and
 *    the gain or loss against book value goes to the category's gain / loss on disposal account. Depreciation must be
 *    booked up to the month before the disposal first, so book value is right.
 */
class FixedAssetLifecycle
{
    public function __construct(
        private readonly FixedAssetJournal $journal,
        private readonly FixedAssetDepreciationEngine $depreciation,
    ) {}

    public function requestRevaluation(FixedAsset $asset, string $date, float $newValue, string $reason): AssetEvent
    {
        $this->assertRequestable($asset, $date);
        $this->assertAccount($asset->category, 'revaluation_coa_id', 'revaluation surplus');

        if ($newValue <= $asset->bookValue()) {
            throw ValidationException::withMessages(['new_value' => ['A revaluation can only raise the value. Use an impairment to write an asset down.']]);
        }

        return $this->record($asset, AssetEvent::TYPE_REVALUATION, $date, round($newValue - $asset->bookValue(), 2), $reason, ['new_value' => $newValue, 'book_value_at_request' => $asset->bookValue()]);
    }

    public function requestImpairment(FixedAsset $asset, string $date, float $amount, string $reason): AssetEvent
    {
        $this->assertRequestable($asset, $date);
        $this->assertAccount($asset->category, 'impairment_coa_id', 'impairment loss');

        if ($amount > $asset->bookValue()) {
            throw ValidationException::withMessages(['amount' => ['The impairment cannot be more than the book value ('.number_format($asset->bookValue(), 2, '.', '').').']]);
        }

        return $this->record($asset, AssetEvent::TYPE_IMPAIRMENT, $date, round($amount, 2), $reason, ['book_value_at_request' => $asset->bookValue()]);
    }

    public function requestDisposal(FixedAsset $asset, string $date, float $proceeds, ?int $proceedsCoaId, string $reason): AssetEvent
    {
        $this->assertRequestable($asset, $date);
        $this->assertAccount($asset->category, 'disposal_coa_id', 'gain / loss on disposal');
        $this->assertDepreciationCurrent($asset, $date);

        if ($proceeds > 0 && $proceedsCoaId === null) {
            throw ValidationException::withMessages(['proceeds_coa_id' => ['Choose the account the sale proceeds go to.']]);
        }

        return $this->record($asset, AssetEvent::TYPE_DISPOSAL, $date, round($proceeds, 2), $reason, ['proceeds_coa_id' => $proceedsCoaId, 'book_value_at_request' => $asset->bookValue()]);
    }

    public function approve(AssetEvent $event): AssetEvent
    {
        return DB::transaction(function () use ($event): AssetEvent {
            $event = AssetEvent::query()->lockForUpdate()->findOrFail($event->id);
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($event->fixed_asset_id);

            if ($event->status !== AssetEvent::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => ['Only a pending request can be approved.']]);
            }

            if ($asset->status !== FixedAsset::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['asset' => ['The asset is no longer in service.']]);
            }

            $date = $event->event_date->toDateString();
            $category = $asset->category;
            $label = $asset->code.' '.$asset->name;
            $payload = (array) $event->payload;

            [$voucher, $amount] = match ($event->type) {
                AssetEvent::TYPE_REVALUATION => $this->approveRevaluation($asset, $category, $date, (float) $payload['new_value'], $label),
                AssetEvent::TYPE_IMPAIRMENT => $this->approveImpairment($asset, $category, $date, (float) $event->amount, $label),
                default => $this->approveDisposal($asset, $category, $date, (float) $event->amount, $payload['proceeds_coa_id'] ?? null, $label),
            };

            $event->update([
                'status' => AssetEvent::STATUS_APPROVED,
                'amount' => $amount,
                't_account_id' => $voucher->id,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            return $event->refresh();
        });
    }

    public function reject(AssetEvent $event, ?string $reason): AssetEvent
    {
        if ($event->status !== AssetEvent::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => ['Only a pending request can be rejected.']]);
        }

        $event->update(['status' => AssetEvent::STATUS_REJECTED, 'rejected_reason' => $reason, 'approved_by' => Auth::id(), 'approved_at' => now()]);

        return $event->refresh();
    }

    /**
     * @return array{0: TAccount, 1: float}
     */
    private function approveRevaluation(FixedAsset $asset, AssetCategory $category, string $date, float $newValue, string $label): array
    {
        $difference = round($newValue - $asset->bookValue(), 2);

        if ($difference <= 0) {
            throw ValidationException::withMessages(['new_value' => ['The asset is already worth at least that much on the books. Reject this request and raise a new one.']]);
        }

        $voucher = $this->journal->post((int) $asset->company_id, (int) $asset->branch_id, $date, [
            ['account_id' => (int) $category->asset_coa_id, 'debit' => $difference, 'credit' => 0.0],
            ['account_id' => (int) $category->revaluation_coa_id, 'debit' => 0.0, 'credit' => $difference],
        ], 'Revaluation: '.$label, $asset->code, respectApprovalSetting: false);

        $asset->update(['cost' => round((float) $asset->cost + $difference, 2), 'updated_by' => Auth::id()]);

        return [$voucher, $difference];
    }

    /**
     * @return array{0: TAccount, 1: float}
     */
    private function approveImpairment(FixedAsset $asset, AssetCategory $category, string $date, float $amount, string $label): array
    {
        if ($amount > $asset->bookValue()) {
            throw ValidationException::withMessages(['amount' => ['The book value has fallen below the impairment since it was requested. Reject this request and raise a new one.']]);
        }

        $voucher = $this->journal->post((int) $asset->company_id, (int) $asset->branch_id, $date, [
            ['account_id' => (int) $category->impairment_coa_id, 'debit' => $amount, 'credit' => 0.0, 'cost_center_id' => $asset->cost_center_id],
            ['account_id' => (int) $category->accumulated_coa_id, 'debit' => 0.0, 'credit' => $amount],
        ], 'Impairment: '.$label, $asset->code, respectApprovalSetting: false);

        $accumulated = round((float) $asset->accumulated_depreciation + $amount, 2);
        $asset->update([
            'accumulated_depreciation' => $accumulated,
            'salvage_value' => min((float) $asset->salvage_value, round((float) $asset->cost - $accumulated, 2)),
            'updated_by' => Auth::id(),
        ]);

        return [$voucher, $amount];
    }

    /**
     * @return array{0: TAccount, 1: float}
     */
    private function approveDisposal(FixedAsset $asset, AssetCategory $category, string $date, float $proceeds, mixed $proceedsCoaId, string $label): array
    {
        $this->assertDepreciationCurrent($asset, $date);

        $cost = round((float) $asset->cost, 2);
        $accumulated = round((float) $asset->accumulated_depreciation, 2);
        $gain = round($proceeds - ($cost - $accumulated), 2);

        $lines = [['account_id' => (int) $category->asset_coa_id, 'debit' => 0.0, 'credit' => $cost]];

        if ($proceeds > 0) {
            $lines[] = ['account_id' => (int) $proceedsCoaId, 'debit' => $proceeds, 'credit' => 0.0];
        }

        if ($accumulated > 0) {
            $lines[] = ['account_id' => (int) $category->accumulated_coa_id, 'debit' => $accumulated, 'credit' => 0.0];
        }

        if ($gain > 0) {
            $lines[] = ['account_id' => (int) $category->disposal_coa_id, 'debit' => 0.0, 'credit' => $gain, 'cost_center_id' => $asset->cost_center_id];
        } elseif ($gain < 0) {
            $lines[] = ['account_id' => (int) $category->disposal_coa_id, 'debit' => abs($gain), 'credit' => 0.0, 'cost_center_id' => $asset->cost_center_id];
        }

        $voucher = $this->journal->post((int) $asset->company_id, (int) $asset->branch_id, $date, $lines, 'Disposal: '.$label, $asset->code, respectApprovalSetting: false);

        $asset->update(['status' => FixedAsset::STATUS_DISPOSED, 'disposed_on' => $date, 'updated_by' => Auth::id()]);

        return [$voucher, $proceeds];
    }

    private function assertRequestable(FixedAsset $asset, string $date): void
    {
        if ($asset->status !== FixedAsset::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['asset' => ['Only an asset in service can be revalued, impaired or disposed of.']]);
        }

        if ($date < $asset->acquired_on->toDateString()) {
            throw ValidationException::withMessages(['date' => ['The date cannot be before the asset was acquired.']]);
        }

        $pending = AssetEvent::query()->where('fixed_asset_id', $asset->id)->where('status', AssetEvent::STATUS_PENDING)->whereIn('type', AssetEvent::APPROVAL_TYPES)->exists();

        if ($pending) {
            throw ValidationException::withMessages(['asset' => ['This asset already has a request waiting for approval.']]);
        }
    }

    private function assertAccount(AssetCategory $category, string $column, string $label): void
    {
        if ($category->{$column} === null) {
            throw ValidationException::withMessages(['asset' => ["This category has no {$label} account. Add one to the category first."]]);
        }
    }

    /**
     * Book value is only right once depreciation is booked up to the month before the disposal, and not beyond
     * the disposal month.
     */
    private function assertDepreciationCurrent(FixedAsset $asset, string $date): void
    {
        $day = CarbonImmutable::parse($date);
        $previous = $day->startOfMonth()->subDay()->format('Y-m');

        if ($this->depreciation->schedule($asset, $previous) !== []) {
            throw ValidationException::withMessages(['date' => ['Book depreciation up to '.$previous.' first, so the book value used for the gain or loss is right.']]);
        }

        if ($asset->depreciated_through !== null && $asset->depreciated_through->greaterThan($day->endOfMonth())) {
            throw ValidationException::withMessages(['date' => ['Depreciation has already been booked after that date.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(FixedAsset $asset, string $type, string $date, float $amount, string $reason, array $payload): AssetEvent
    {
        return AssetEvent::query()->create([
            'company_id' => $asset->company_id,
            'branch_id' => $asset->branch_id,
            'fixed_asset_id' => $asset->id,
            'type' => $type,
            'status' => AssetEvent::STATUS_PENDING,
            'event_date' => $date,
            'amount' => $amount,
            'description' => $reason,
            'payload' => $payload,
            'created_by' => Auth::id(),
        ]);
    }
}
