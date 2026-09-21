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
 * Nothing here is called from Sell or POS: points are awarded and redeemed through the Loyalty pages.
 */
class LoyaltyPoints
{
    public function balance(int $contactId): int
    {
        return (int) LoyaltyPointEntry::query()->where('contact_id', $contactId)->sum('points');
    }

    /**
     * Awards the points a finished sale earns its customer. Once per sale.
     */
    public function earnForSale(Transaction $sale, ?int $userId = null): LoyaltyPointEntry
    {
        if ($sale->type !== Transaction::TYPE_SELL || $sale->status !== 'final') {
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
