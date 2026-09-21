<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\DocumentSetting;
use App\Models\GiftCard;
use App\Models\GiftCardEntry;
use App\Models\Payment;
use App\Models\SaleDiscount;
use App\Models\SellLine;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * What a customer can do at checkout with the Discount, Gift Card and Loyalty modules. `afterSave()` runs
 * right after a sale (and its lines and journal) has been saved, inside the same database transaction, so
 * a refusal rolls the whole sale back.
 *
 * - **Discount code** (`discount_code`, `coupon_discount_amount` in the request): the code is looked up in
 *   the company, checked (switched on, dates, minimum purchase) against the sale's line total and the amount
 *   is worked out here. The client also sends the amount it took off `final_amount`; if it differs by more than
 *   a cent the sale is refused (the client is showing a stale or wrong total). The applied code is stored in
 *   `sale_discounts` with a snapshot of the rule. One code per sale; sending an empty code removes it, not
 *   sending the field at all leaves it alone; an edit that keeps the same code keeps it even if it has
 *   expired since (it is not "applied again"), but the minimum purchase is still checked.
 * - **Gift card** (`gift_card_code`, `gift_card_amount`, `gift_card_payment_account`): on a finished sale the
 *   amount is spent from the card and recorded as an ordinary sell payment (method `other`, note "Gift card
 *   CODE") posted to the given cash / bank account, so the invoice shows partly or fully paid and the rest
 *   is paid the usual way. A sale that already spent a card is never charged again by an edit.
 * - **Loyalty**: points follow the sale through LoyaltyPoints::syncForSale (earned when it is a finished
 *   sale, corrected on edit, reversed on a return or delete).
 *
 * Discount codes and gift cards only work for a company that switched them on in Checkout Extras.
 */
class SaleIncentives
{
    public function __construct(private readonly LoyaltyPoints $loyalty) {}

    public function afterSave(Transaction $sale, object $request): void
    {
        $this->applyDiscountCode($sale, $request);
        $this->spendGiftCard($sale, $request);
        $this->loyalty->syncForSale($sale, Auth::id());
    }

    /**
     * Called when a sale is deleted: takes its loyalty points back.
     */
    public function afterDelete(Transaction $sale): void
    {
        $this->loyalty->syncForSale($sale, Auth::id(), reverseAll: true);
    }

    /**
     * Called when a sale changes without a full save (status change, restore) or when one of its returns
     * changes: brings its loyalty points in line again.
     */
    public function refreshLoyalty(Transaction $sale): void
    {
        $this->loyalty->syncForSale($sale->fresh() ?? $sale, Auth::id());
    }

    /**
     * Which checkout extras a company has switched on.
     *
     * @return array{discount_codes: bool, gift_cards: bool}
     */
    public function enabledFor(int $companyId): array
    {
        $values = DocumentSetting::valuesFor($companyId, 'checkout');

        return ['discount_codes' => (bool) $values['discount_codes_enabled'], 'gift_cards' => (bool) $values['gift_cards_enabled']];
    }

    private function applyDiscountCode(Transaction $sale, object $request): void
    {
        if (method_exists($request, 'has') && ! $request->has('discount_code')) {
            return;
        }

        $code = strtoupper(trim((string) ($request->discount_code ?? '')));
        $existing = SaleDiscount::query()->where('transaction_id', $sale->id)->first();

        if ($code === '') {
            $existing?->delete();

            return;
        }

        $grandfathered = $existing !== null && $existing->code === $code;

        if (! $grandfathered && ! $this->enabledFor((int) $sale->company_id)['discount_codes']) {
            throw ValidationException::withMessages(['discount_code' => ['Discount codes are not switched on for this company.']]);
        }

        $discount = Discount::findByCode($code, (int) $sale->company_id);

        if ($discount === null) {
            throw ValidationException::withMessages(['discount_code' => ['This coupon code does not exist.']]);
        }

        $subtotal = round((float) SellLine::query()->where('transaction_id', $sale->id)->sum('subtotal'), 2);
        $reason = $grandfathered
            ? ($subtotal < (float) $discount->min_purchase_amount ? 'min_purchase' : null)
            : $discount->rejectionReason($subtotal);

        if ($reason !== null) {
            throw ValidationException::withMessages(['discount_code' => [self::DISCOUNT_REJECTIONS[$reason]]]);
        }

        $amount = $discount->discountFor($subtotal);
        $sent = round((float) ($request->coupon_discount_amount ?? 0), 2);

        if ((int) round(abs($sent - $amount) * 100) > 1) {
            throw ValidationException::withMessages(['coupon_discount_amount' => ['The discount changed since it was applied ('.number_format($amount, 2, '.', '').'). Apply the code again.']]);
        }

        SaleDiscount::query()->updateOrCreate(['transaction_id' => $sale->id], [
            'discount_id' => $discount->id,
            'code' => $discount->code,
            'name' => $discount->name,
            'discount_type' => $discount->discount_type,
            'value' => (float) $discount->value,
            'amount' => $amount,
        ]);
    }

    /**
     * What the discount refusals say (the same reasons as POST discounts/apply).
     *
     * @var array<string, string>
     */
    private const DISCOUNT_REJECTIONS = [
        'inactive' => 'This discount is switched off.',
        'not_started' => 'This discount is not valid yet.',
        'expired' => 'This discount has expired.',
        'min_purchase' => 'The sale is below the minimum amount for this discount.',
    ];

    private const GIFT_CARD_REJECTIONS = [
        'inactive' => 'This gift card is switched off.',
        'expired' => 'This gift card has expired.',
        'insufficient_balance' => 'The gift card balance is less than the amount.',
    ];

    private function spendGiftCard(Transaction $sale, object $request): void
    {
        $code = strtoupper(trim((string) ($request->gift_card_code ?? '')));
        $amount = round((float) ($request->gift_card_amount ?? 0), 2);

        if ($code === '' && $amount <= 0) {
            return;
        }

        $alreadySpent = GiftCardEntry::query()
            ->where('transaction_id', $sale->id)
            ->where('type', GiftCardEntry::TYPE_REDEEM)
            ->exists();

        if ($alreadySpent) {
            return;
        }

        if (! LoyaltyPoints::isPostedSale($sale)) {
            throw ValidationException::withMessages(['gift_card_code' => ['A gift card can only pay a finished sale, not a draft or a quotation.']]);
        }

        if (! $this->enabledFor((int) $sale->company_id)['gift_cards']) {
            throw ValidationException::withMessages(['gift_card_code' => ['Gift cards are not switched on for this company.']]);
        }

        $card = $code === '' ? null : GiftCard::findByCode($code, (int) $sale->company_id);

        if ($card === null) {
            throw ValidationException::withMessages(['gift_card_code' => ['This gift card code does not exist.']]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['gift_card_amount' => ['Enter the amount to take from the gift card.']]);
        }

        if ($amount > Payment::remainingAmountForTransaction($sale)) {
            throw ValidationException::withMessages(['gift_card_amount' => ['The gift card amount is more than the sale still owes ('.Payment::remainingAmountForTransaction($sale).').']]);
        }

        if (empty($request->gift_card_payment_account)) {
            throw ValidationException::withMessages(['gift_card_payment_account' => ['Choose the account the gift card payment is posted to.']]);
        }

        $reason = $card->rejectionReason($amount);

        if ($reason !== null) {
            throw ValidationException::withMessages(['gift_card_amount' => [self::GIFT_CARD_REJECTIONS[$reason] ?? 'This gift card cannot be used.']]);
        }

        $reference = $sale->invoice_no ?: '#'.$sale->id;

        try {
            $card->redeem($amount, 'Sale '.$reference, (int) $sale->id, Auth::id());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['gift_card_amount' => [self::GIFT_CARD_REJECTIONS[$e->getMessage()] ?? 'This gift card cannot be used.']]);
        }

        Payment::createSellPayment(new Request([
            'transaction_id' => $sale->id,
            'amount' => $amount,
            'paid_on' => Carbon::parse($sale->transaction_date ?? now())->toDateString(),
            'method' => 'other',
            'payment_account' => (int) $request->gift_card_payment_account,
            'note' => 'Gift card '.$card->code,
        ]));
    }
}
