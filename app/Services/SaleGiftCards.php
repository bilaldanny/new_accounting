<?php

namespace App\Services;

use App\Models\GiftCard;
use App\Models\GiftCardEntry;
use App\Models\GiftCardOrphanRefund;
use App\Models\Payment;
use App\Models\SellLine;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Keeps the gift card money of a sale in step with the sale, the way LoyaltyPoints::syncForSale keeps its
 * points: a sale deleted, turned back into a draft or (partly) returned gives the card its money back, a
 * sale restored or finished again takes it out again, and calling it twice changes nothing.
 *
 * For every card that paid the sale, `paid` is the first redeem entry, `held` is what is still taken
 * (redeems minus refunds) and `target` is `paid x keep`, where `keep` is 0 for a deleted or unfinished sale
 * and, for a finished one, one minus the share of the goods that came back on its sale returns (returned
 * value / line total, so returning every line returns all of it whatever the shipping or bill discount). The
 * difference is written as one ledger entry: a refund (a `topup` naming the sale) or a new redeem. Nothing is
 * ever deleted from the ledger, so the audit trail keeps every step.
 *
 * - A refund is always credited, even to an inactive, expired or trashed card.
 * - Taking the money out again needs a usable card; if it cannot pay, a ValidationException refuses the
 *   restore / reopening so a sale never comes back unpaid by its card.
 * - A card that was deleted for good took its ledger with it. The sale's payments ("Gift card CODE") still
 *   show what it paid, so the refund is logged as a signed row in `gift_card_orphan_refunds` for someone to
 *   settle by hand (a restore logs the negative).
 *
 * The sell payment row and its ledger voucher are not touched: they behave as they do for any other payment
 * on a deleted sale.
 */
class SaleGiftCards
{
    public function syncForSale(Transaction $sale, ?int $userId = null, bool $reverseAll = false): void
    {
        $entries = GiftCardEntry::query()->where('transaction_id', $sale->id)->orderBy('id')->get();

        if ($entries->isEmpty() && ! $this->paidByGiftCardPayment($sale)->isNotEmpty()) {
            return;
        }

        $keep = $this->keepShare($sale, $reverseAll);
        $reference = $sale->invoice_no ?: '#'.$sale->id;
        $why = $reverseAll ? 'deleted' : (LoyaltyPoints::isPostedSale($sale) ? 'returned' : 'no longer a finished sale');

        foreach ($entries->groupBy('gift_card_id') as $cardId => $cardEntries) {
            $this->syncCard((int) $cardId, $cardEntries, $sale, $keep, $reference, $why, $userId);
        }

        $this->syncOrphans($sale, $entries, $keep, $why, $userId);
    }

    /**
     * How much of what the gift cards paid the sale still keeps: 0 when it is deleted or not a finished sale,
     * else the share of the goods that has not been returned.
     */
    private function keepShare(Transaction $sale, bool $reverseAll): float
    {
        if ($reverseAll || ! LoyaltyPoints::isPostedSale($sale)) {
            return 0.0;
        }

        $goods = (float) SellLine::query()->where('transaction_id', $sale->id)->sum('subtotal');

        if ($goods <= 0) {
            return 1.0;
        }

        $returned = (float) Transaction::query()
            ->where('type', Transaction::TYPE_SELL_RETURN)
            ->where('parent_id', $sale->id)
            ->sum('final_amount');

        return round(1 - min($returned / $goods, 1), 6);
    }

    /**
     * @param  Collection<int, GiftCardEntry>  $entries  this sale's entries on one card
     */
    private function syncCard(int $cardId, Collection $entries, Transaction $sale, float $keep, string $reference, string $why, ?int $userId): void
    {
        $redeems = $entries->where('type', GiftCardEntry::TYPE_REDEEM);

        if ($redeems->isEmpty()) {
            return;
        }

        $paid = (float) $redeems->first()->amount;
        $held = round((float) $redeems->sum('amount') - (float) $entries->where('type', GiftCardEntry::TYPE_TOPUP)->sum('amount'), 2);
        $delta = round($paid * $keep - $held, 2);

        if ($delta < 0) {
            GiftCard::refundForSale($cardId, -$delta, (int) $sale->id, 'Refund: sale '.$reference.' '.$why, $userId);

            return;
        }

        if ($delta > 0) {
            try {
                GiftCard::chargeForSale($cardId, $delta, (int) $sale->id, 'Sale '.$reference.' is back', $userId);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['gift_card_code' => [self::CHARGE_REFUSALS[$e->getMessage()] ?? 'The gift card cannot be charged again.']]);
            }
        }
    }

    private const CHARGE_REFUSALS = [
        'missing' => 'The gift card that paid this sale no longer exists, so the sale cannot come back.',
        'deleted' => 'The gift card that paid this sale has been deleted, so the sale cannot come back.',
        'inactive' => 'The gift card that paid this sale is switched off, so it cannot be charged again.',
        'expired' => 'The gift card that paid this sale has expired, so it cannot be charged again.',
        'insufficient_balance' => 'The gift card that paid this sale no longer has the balance to be charged again.',
    ];

    /**
     * Payments made with a card that has no ledger any more (deleted for good), by card code.
     *
     * @return Collection<string, float>
     */
    private function paidByGiftCardPayment(Transaction $sale): Collection
    {
        return Payment::query()
            ->where('transaction_id', $sale->id)
            ->where('method', 'other')
            ->where('note', 'like', 'Gift card %')
            ->get()
            ->groupBy(fn (Payment $payment): string => trim(substr((string) $payment->note, strlen('Gift card '))))
            ->map(fn (Collection $payments): float => round((float) $payments->sum('amount'), 2));
    }

    /**
     * @param  Collection<int, GiftCardEntry>  $entries
     */
    private function syncOrphans(Transaction $sale, Collection $entries, float $keep, string $why, ?int $userId): void
    {
        $known = GiftCard::withTrashed()->whereIn('id', $entries->pluck('gift_card_id')->unique())->pluck('code')->all();

        foreach ($this->paidByGiftCardPayment($sale) as $code => $paid) {
            if (in_array($code, $known, true)) {
                continue;
            }

            $logged = round((float) GiftCardOrphanRefund::query()->where('transaction_id', $sale->id)->where('gift_card_code', $code)->sum('amount'), 2);
            $delta = round($paid * (1 - $keep) - $logged, 2);

            if ($delta === 0.0) {
                continue;
            }

            GiftCardOrphanRefund::query()->create([
                'company_id' => $sale->company_id,
                'transaction_id' => $sale->id,
                'gift_card_code' => $code,
                'amount' => $delta,
                'reason' => $delta > 0
                    ? 'Card no longer exists; sale '.$why.': refund owed to the customer'
                    : 'Sale is back: part of the refund owed no longer applies',
                'created_by' => $userId,
            ]);
        }
    }
}
