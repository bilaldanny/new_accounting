<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\LoyaltyPointEntry;
use App\Models\LoyaltySetting;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves a customer's loyalty points. Every method runs in a transaction that first locks the customer's
 * contact row, so two requests for the same customer are applied one after the other and a balance can
 * never be spent twice. A refused movement throws a RuntimeException whose message is the reason:
 *
 * - `disabled`: the company has not switched the programme on (earn and redeem only)
 * - `not_a_sale`, `no_customer`, `already_awarded`, `no_points`: earning for a sale
 * - `below_minimum`, `insufficient_points`: redeeming
 * - `negative_balance`: an adjustment that would take the balance below zero
 *
 * Points for a sale are kept in step by `syncForSale()`, which the sell save, the sale return and the sale
 * delete call (AppServicesSaleIncentives); redeeming and manual adjustments stay on the Loyalty pages.
 */
class LoyaltyPoints
{
    /**
     * Sale statuses that count as a finished sale: final, then approved / issued as the workflow goes on.
     * Draft, quotation and anything else earn nothing.
     *
     * @var list<string>
     */
    public const POSTED_STATUSES = ['final', 'approved', 'issue'];

    public static function isPostedSale(Transaction $sale): bool
    {
        return in_array($sale->status, self::POSTED_STATUSES, true);
    }

    public function balance(int $contactId): int
    {
        return (int) LoyaltyPointEntry::query()->where('contact_id', $contactId)->sum('points');
    }

    /**
     * Brings the points a sale has earned in line with the sale as it is now, so a checkout, an edit, a
     * sale return and a delete all end in the right balance without double counting:
     *
     * - the sale earns `pointsFor(final_amount - what its sale returns took back)` points while it is a
     *   posted sale of a customer and the programme is on (a proportional reverse for a return);
     * - a draft / quotation, a deleted sale or a customer change earns 0, so anything earned is taken back;
     * - a reversal never takes the customer below zero (points already spent stay spent);
     * - with the programme switched off nothing is awarded and nothing is reversed for an ordinary edit.
     *
     * The first award is an `earn` entry, later corrections are `adjust` entries on the same sale. Returns
     * the entry written for the sale's customer, or null when nothing changed.
     */
    public function syncForSale(Transaction $sale, ?int $userId = null, bool $reverseAll = false): ?LoyaltyPointEntry
    {
        if ($sale->type !== Transaction::TYPE_SELL || $sale->company_id === null) {
            return null;
        }

        $settings = LoyaltySetting::forCompany((int) $sale->company_id);
        $eligible = ! $reverseAll && $sale->contact_id !== null && self::isPostedSale($sale);

        if ($eligible && ! $settings->is_enabled) {
            return null;
        }

        $held = LoyaltyPointEntry::query()
            ->where('transaction_id', $sale->id)
            ->whereIn('type', [LoyaltyPointEntry::TYPE_EARN, LoyaltyPointEntry::TYPE_ADJUST])
            ->get()
            ->groupBy('contact_id');

        // whatever another customer still holds for this sale (the customer was changed) goes back first
        foreach ($held as $contactId => $entries) {
            if ($eligible && (int) $contactId === (int) $sale->contact_id) {
                continue;
            }

            $this->correct((int) $contactId, $sale, (int) $entries->sum('points'), 0, 0.0, $userId, $reverseAll ? 'Sale deleted or reversed' : 'Sale customer changed');
        }

        if (! $eligible) {
            return null;
        }

        $returned = (float) Transaction::query()
            ->where('type', Transaction::TYPE_SELL_RETURN)
            ->where('parent_id', $sale->id)
            ->sum('final_amount');
        $net = round(max((float) $sale->final_amount - $returned, 0), 2);

        return $this->correct(
            (int) $sale->contact_id,
            $sale,
            (int) ($held[$sale->contact_id] ?? collect())->sum('points'),
            $settings->pointsFor($net),
            $net,
            $userId,
            $returned > 0 ? 'Sale changed or returned' : 'Sale changed',
        );
    }

    /**
     * Moves a customer's points for one sale from what they hold to what they should hold.
     */
    private function correct(int $contactId, Transaction $sale, int $current, int $expected, float $amount, ?int $userId, string $note): ?LoyaltyPointEntry
    {
        if ($expected === $current) {
            return null;
        }

        return $this->locked($contactId, function (Contact $contact) use ($sale, $current, $expected, $amount, $userId, $note): ?LoyaltyPointEntry {
            $delta = $expected - $current;

            if ($delta < 0) {
                $delta = -min(-$delta, max($this->balance((int) $contact->id), 0));
            }

            if ($delta === 0) {
                return null;
            }

            $firstAward = ! LoyaltyPointEntry::query()
                ->where('transaction_id', $sale->id)
                ->where('contact_id', $contact->id)
                ->whereIn('type', [LoyaltyPointEntry::TYPE_EARN, LoyaltyPointEntry::TYPE_ADJUST])
                ->exists();

            return $this->record(
                $contact,
                $firstAward ? LoyaltyPointEntry::TYPE_EARN : LoyaltyPointEntry::TYPE_ADJUST,
                $delta,
                $delta > 0 ? $amount : null,
                $sale->id,
                $firstAward ? null : $note.' ('.($sale->invoice_no ?: '#'.$sale->id).')',
                $userId,
            );
        });
    }

    /**
     * Awards the points a finished sale earns its customer. Once per sale.
     */
    public function earnForSale(Transaction $sale, ?int $userId = null): LoyaltyPointEntry
    {
        if ($sale->type !== Transaction::TYPE_SELL || ! self::isPostedSale($sale)) {
            throw new RuntimeException('not_a_sale');
        }

        if ($sale->contact_id === null) {
            throw new RuntimeException('no_customer');
        }

        return $this->locked((int) $sale->contact_id, function (Contact $contact) use ($sale, $userId): LoyaltyPointEntry {
            $settings = $this->enabledSettings((int) $sale->company_id);

            $alreadyAwarded = LoyaltyPointEntry::query()
                ->where('type', LoyaltyPointEntry::TYPE_EARN)
                ->where('transaction_id', $sale->id)
                ->exists();

            if ($alreadyAwarded) {
                throw new RuntimeException('already_awarded');
            }

            $points = $settings->pointsFor((float) $sale->final_amount);

            if ($points < 1) {
                throw new RuntimeException('no_points');
            }

            return $this->record($contact, LoyaltyPointEntry::TYPE_EARN, $points, (float) $sale->final_amount, $sale->id, null, $userId);
        });
    }

    /**
     * Spends $points (a whole number above zero) and records their currency value.
     */
    public function redeem(Contact $contact, int $points, ?string $note = null, ?int $transactionId = null, ?int $userId = null): LoyaltyPointEntry
    {
        return $this->locked((int) $contact->id, function (Contact $locked) use ($points, $note, $transactionId, $userId): LoyaltyPointEntry {
            $settings = $this->enabledSettings((int) $locked->company_id);

            if ($points < max(1, $settings->min_redeem_points)) {
                throw new RuntimeException('below_minimum');
            }

            if ($points > $this->balance((int) $locked->id)) {
                throw new RuntimeException('insufficient_points');
            }

            return $this->record($locked, LoyaltyPointEntry::TYPE_REDEEM, -$points, $settings->valueOf($points), $transactionId, $note, $userId);
        });
    }

    /**
     * A manual correction of $points (a whole number, negative to take points away). Works even with the
     * programme switched off, but never takes the balance below zero.
     */
    public function adjust(Contact $contact, int $points, string $note, ?int $userId = null): LoyaltyPointEntry
    {
        return $this->locked((int) $contact->id, function (Contact $locked) use ($points, $note, $userId): LoyaltyPointEntry {
            if ($this->balance((int) $locked->id) + $points < 0) {
                throw new RuntimeException('negative_balance');
            }

            return $this->record($locked, LoyaltyPointEntry::TYPE_ADJUST, $points, null, null, $note, $userId);
        });
    }

    /**
     * @template T
     *
     * @param  callable(Contact): T  $callback
     * @return T
     */
    private function locked(int $contactId, callable $callback): mixed
    {
        return DB::transaction(function () use ($contactId, $callback) {
            $contact = Contact::query()->lockForUpdate()->findOrFail($contactId);

            return $callback($contact);
        });
    }

    private function enabledSettings(int $companyId): LoyaltySetting
    {
        $settings = LoyaltySetting::forCompany($companyId);

        if (! $settings->is_enabled) {
            throw new RuntimeException('disabled');
        }

        return $settings;
    }

    private function record(Contact $contact, string $type, int $points, ?float $amount, ?int $transactionId, ?string $note, ?int $userId): LoyaltyPointEntry
    {
        return LoyaltyPointEntry::query()->create([
            'company_id' => $contact->company_id,
            'contact_id' => $contact->id,
            'type' => $type,
            'points' => $points,
            'balance_after' => $this->balance((int) $contact->id) + $points,
            'amount' => $amount,
            'transaction_id' => $transactionId,
            'user_id' => $userId,
            'note' => $note,
        ]);
    }
}
