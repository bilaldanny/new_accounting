<?php

namespace App\Services\Rental;

use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\Transaction;
use App\Services\AccountsVoucher;
use App\Services\LedgerJournal;
use App\Services\StockMovements;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rental: items with units, a rate, a deposit and a late fee; bookings that cannot overbook an item on any day; pickup (deposit
 * taken); return (inspection, late days, late charge, damage charge, deposit applied and refunded).
 *
 *   rent         = days (start to end, both counted) x daily rate x quantity
 *   late charge  = days returned after the end date x late fee per day x quantity
 *   charges      = rent + late charge + damage charge
 *   applied      = the deposit received, up to the charges      refund = what is left of the deposit      due = charges - applied
 *
 * With rental posting on, the return posts one voucher through AccountsVoucher (period lock, journal approval and numbering apply):
 *   Dr customer account (charges) + Dr deposit liability (deposit received)
 *   Cr rental income, late fee income, damage income (the charges) + Cr customer account (deposit applied) + Cr cash / bank (refund)
 * and the pickup posts Dr cash / bank, Cr deposit liability for the deposit taken. The customer's balance after the return is `due`.
 * With posting off nothing reaches the ledger. A rental does not move stock: the item is away, not sold.
 */
class RentalService
{
    public function __construct(private readonly RentalSettings $settings, private readonly AccountsVoucher $vouchers, private readonly LedgerJournal $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function saveItem(int $companyId, ?int $branchId, ?int $id, array $data): int
    {
        abort_if($id !== null && ! DB::table('rental_items')->where('company_id', $companyId)->where('id', $id)->exists(), 404);

        $product = Product::query()->where('company_id', $companyId)->where('active', 1)->find((int) ($data['product_id'] ?? 0));

        if ($product === null || ! StockMovements::productKeepsStock((int) $product->id)) {
            throw ValidationException::withMessages(['product_id' => ['Choose a stocked product of this company to rent out.']]);
        }

        $variation = ! empty($data['variation_id'])
            ? ProductDetail::query()->where('product_id', $product->id)->find((int) $data['variation_id'])
            : ProductDetail::query()->where('product_id', $product->id)->orderBy('id')->first();

        if ($variation === null) {
            throw ValidationException::withMessages(['variation_id' => ['That variation does not belong to the product.']]);
        }

        $row = [
            'product_id' => $product->id, 'variation_id' => $variation->id, 'units' => max(1, (int) ($data['units'] ?? 1)), 'daily_rate' => max(0, round((float) ($data['daily_rate'] ?? 0), 2)),
            'deposit_amount' => max(0, round((float) ($data['deposit_amount'] ?? 0), 2)), 'late_fee_per_day' => max(0, round((float) ($data['late_fee_per_day'] ?? 0), 2)),
            'is_active' => (bool) ($data['is_active'] ?? true), 'notes' => isset($data['notes']) ? mb_substr(strip_tags((string) $data['notes']), 0, 200) : null, 'updated_at' => now(),
        ];

        $duplicate = DB::table('rental_items')->where('company_id', $companyId)->where('product_id', $product->id)->where('variation_id', $variation->id)->when($id !== null, fn ($q) => $q->where('id', '!=', $id))->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['product_id' => ['This product variation is already a rental item.']]);
        }

        if ($id === null) {
            return (int) DB::table('rental_items')->insertGetId($row + ['company_id' => $companyId, 'branch_id' => $branchId, 'created_at' => now()]);
        }

        abort_unless(DB::table('rental_items')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
        DB::table('rental_items')->where('id', $id)->update($row);

        return $id;
    }

    /**
     * Units of an item free on every day of a range (the least over the range), counting reserved and out bookings.
     */
    public function available(int $companyId, int $itemId, string $from, string $to, ?int $exceptBooking = null): int
    {
        $item = DB::table('rental_items')->where('company_id', $companyId)->where('id', $itemId)->first();
        abort_if($item === null, 404);

        $bookings = DB::table('rental_bookings')->where('company_id', $companyId)->where('rental_item_id', $itemId)->whereIn('status', ['reserved', 'out'])
            ->when($exceptBooking !== null, fn ($q) => $q->where('id', '!=', $exceptBooking))
            ->whereDate('start_date', '<=', $to)->get(['start_date', 'end_date', 'quantity', 'status']);
        $worst = 0;
        $today = now()->toDateString();

        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)) && $worst < 100000; $day->addDay()) {
            $date = $day->toDateString();
            $taken = $bookings->filter(function (object $b) use ($date, $today): bool {
                $end = substr((string) $b->end_date, 0, 10);
                // an item that is still out after its end date stays taken until it is brought back
                $until = $b->status === 'out' && $end < $today ? $today : $end;

                return substr((string) $b->start_date, 0, 10) <= $date && $until >= $date;
            })->sum('quantity');
            $worst = max($worst, (int) $taken);
        }

        return max(0, (int) $item->units - $worst);
    }

    /**
     * @throws ValidationException
     */
    public function book(int $companyId, ?int $branchId, int $itemId, int $contactId, int $quantity, string $start, string $end, ?int $userId): int
    {
        $item = DB::table('rental_items')->where('company_id', $companyId)->where('id', $itemId)->where('is_active', true)->first();

        if ($item === null) {
            throw ValidationException::withMessages(['rental_item_id' => ['Choose an active rental item.']]);
        }

        if (! Contact::query()->where('company_id', $companyId)->whereKey($contactId)->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a customer of this company.']]);
        }

        if ($end < $start || $quantity < 1) {
            throw ValidationException::withMessages(['end_date' => ['The end date cannot be before the start date, and at least one unit is rented.']]);
        }

        if ($this->available($companyId, $itemId, $start, $end) < $quantity) {
            throw ValidationException::withMessages(['quantity' => ['Not enough units are free on every day of these dates.']]);
        }

        $days = Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;

        return (int) DB::transaction(function () use ($companyId, $branchId, $item, $contactId, $quantity, $start, $end, $days, $userId): int {
            $next = (int) DB::table('rental_bookings')->where('company_id', $companyId)->count() + 1;

            do {
                $number = 'RB-'.str_pad((string) $next++, 5, '0', STR_PAD_LEFT);
            } while (DB::table('rental_bookings')->where('company_id', $companyId)->where('booking_no', $number)->exists());

            return (int) DB::table('rental_bookings')->insertGetId([
                'company_id' => $companyId, 'branch_id' => $branchId, 'booking_no' => $number, 'rental_item_id' => $item->id, 'contact_id' => $contactId, 'quantity' => $quantity,
                'start_date' => $start, 'end_date' => $end, 'status' => 'reserved', 'daily_rate' => $item->daily_rate, 'deposit_amount' => round((float) $item->deposit_amount * $quantity, 2),
                'late_fee_per_day' => round((float) $item->late_fee_per_day * $quantity, 2), 'rent_amount' => round((float) $item->daily_rate * $quantity * $days, 2),
                'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function cancel(int $companyId, int $id): void
    {
        $booking = $this->find($companyId, $id);

        if ($booking->status !== 'reserved') {
            throw ValidationException::withMessages(['status' => ['Only a reservation that was not picked up can be cancelled.']]);
        }

        DB::table('rental_bookings')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /**
     * The customer takes the item away and leaves the deposit.
     *
     * @throws ValidationException
     */
    public function pickup(int $companyId, int $id, ?float $deposit, ?int $paymentAccountId, string $date): void
    {
        $booking = $this->find($companyId, $id);

        if ($booking->status !== 'reserved') {
            throw ValidationException::withMessages(['status' => ['This booking is not waiting for pickup.']]);
        }

        $received = round($deposit ?? (float) $booking->deposit_amount, 2);

        if ($received < 0) {
            throw ValidationException::withMessages(['deposit' => ['The deposit cannot be negative.']]);
        }

        $settings = $this->settings->all($companyId);
        $posting = (bool) $settings['posting_enabled'] && $received > 0;

        if ($settings['posting_enabled']) {
            $this->assertMapped($settings);
        }

        if ($posting && ($paymentAccountId === null || ! DB::table('chart_of_accounts')->where('company_id', $companyId)->where('id', $paymentAccountId)->exists())) {
            throw ValidationException::withMessages(['payment_account_id' => ['Choose the cash or bank account the deposit went into.']]);
        }

        DB::transaction(function () use ($companyId, $booking, $received, $paymentAccountId, $date, $settings, $posting): void {
            $voucher = $posting
                ? $this->vouchers->post($companyId, $booking->branch_id === null ? null : (int) $booking->branch_id, $date, [
                    ['account_id' => (int) $paymentAccountId, 'debit' => $received], ['account_id' => (int) $settings['accounts']['deposit_liability'], 'credit' => $received],
                ], 'Rental deposit '.$booking->booking_no, $booking->booking_no)->id
                : null;

            DB::table('rental_bookings')->where('id', $booking->id)->update([
                'status' => 'out', 'deposit_received' => $received, 'picked_up_at' => now(), 'pickup_voucher_id' => $voucher, 'updated_at' => now(),
            ]);
        });
    }

    /**
     * Receives the item back with its inspection and works out what the customer owes or is refunded.
     *
     * @return array{late_days: int, late_charge: float, damage_charge: float, rent: float, charges: float, deposit_applied: float, deposit_refund: float, amount_due: float}
     *
     * @throws ValidationException
     */
    public function receiveBack(int $companyId, int $id, string $returnedOn, string $condition, float $damageCharge, ?string $notes, ?int $refundAccountId): array
    {
        $booking = $this->find($companyId, $id);

        if ($booking->status !== 'out') {
            throw ValidationException::withMessages(['status' => ['This booking is not out.']]);
        }

        if (! in_array($condition, ['good', 'minor_damage', 'major_damage'], true) || $damageCharge < 0) {
            throw ValidationException::withMessages(['condition' => ['Choose the condition and a damage charge of zero or more.']]);
        }

        $lateDays = max(0, (int) Carbon::parse(substr((string) $booking->end_date, 0, 10))->diffInDays(Carbon::parse($returnedOn), false));
        $late = round($lateDays * (float) $booking->late_fee_per_day, 2);
        $damage = round($damageCharge, 2);
        $charges = round((float) $booking->rent_amount + $late + $damage, 2);
        $received = (float) $booking->deposit_received;
        $applied = round(min($received, $charges), 2);
        $refund = round($received - $applied, 2);
        $due = round($charges - $applied, 2);
        $settings = $this->settings->all($companyId);

        if ($settings['posting_enabled']) {
            $this->assertMapped($settings);

            if ($refund > 0 && ($refundAccountId === null || ! DB::table('chart_of_accounts')->where('company_id', $companyId)->where('id', $refundAccountId)->exists())) {
                throw ValidationException::withMessages(['refund_account_id' => ['Choose the cash or bank account the deposit refund is paid from.']]);
            }
        }

        DB::transaction(function () use ($companyId, $booking, $returnedOn, $condition, $notes, $lateDays, $late, $damage, $charges, $received, $applied, $refund, $due, $settings, $refundAccountId): void {
            $voucher = null;

            if ($settings['posting_enabled'] && ($charges > 0 || $received > 0)) {
                $voucher = $this->postReturn($companyId, $booking, $returnedOn, $settings['accounts'], $charges, $late, $damage, $received, $applied, $refund, $refundAccountId);
            }

            DB::table('rental_bookings')->where('id', $booking->id)->update([
                'status' => 'returned', 'returned_on' => $returnedOn, 'late_days' => $lateDays, 'late_charge' => $late, 'damage_charge' => $damage, 'condition' => $condition,
                'inspection_notes' => $notes !== null ? mb_substr(strip_tags($notes), 0, 500) : null, 'deposit_applied' => $applied, 'deposit_refund' => $refund, 'amount_due' => $due,
                'return_voucher_id' => $voucher, 'updated_at' => now(),
            ]);
        });

        return ['late_days' => $lateDays, 'late_charge' => $late, 'damage_charge' => $damage, 'rent' => round((float) $booking->rent_amount, 2), 'charges' => $charges, 'deposit_applied' => $applied, 'deposit_refund' => $refund, 'amount_due' => $due];
    }

    public function find(int $companyId, int $id): object
    {
        $booking = DB::table('rental_bookings')->where('company_id', $companyId)->where('id', $id)->first();
        abort_if($booking === null, 404);

        return $booking;
    }

    // ------------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, int|null>  $accounts
     */
    private function postReturn(int $companyId, object $booking, string $date, array $accounts, float $charges, float $late, float $damage, float $received, float $applied, float $refund, ?int $refundAccountId): int
    {
        $contact = Contact::query()->where('company_id', $companyId)->find((int) $booking->contact_id);
        $anchor = new Transaction;
        $anchor->company_id = $companyId;
        $anchor->branch_id = $booking->branch_id ?? $contact?->branch_id;
        $customer = $contact === null ? null : $this->ledger->accountByCode($anchor, (string) ($contact->customer_gl_id ?: $contact->gl_id));

        if ($customer === null) {
            throw ValidationException::withMessages(['contact_id' => ['Link the customer to a chart of account before posting a rental return.']]);
        }

        $party = (int) $booking->contact_id;
        $rent = round($charges - $late - $damage, 2);
        $lines = [];

        foreach ([
            [(int) $customer->id, $charges, 0.0, true], [$accounts['deposit_liability'], $received, 0.0, false],
            [$accounts['rental_income'], 0.0, $rent, false], [$accounts['late_fee_income'], 0.0, $late, false], [$accounts['damage_income'], 0.0, $damage, false],
            [(int) $customer->id, 0.0, $applied, true], [$refundAccountId, 0.0, $refund, false],
        ] as [$account, $debit, $credit, $isParty]) {
            if ($account !== null && ($debit > 0 || $credit > 0)) {
                $lines[] = ['account_id' => (int) $account, 'debit' => round($debit, 2), 'credit' => round($credit, 2)] + ($isParty ? ['contact_id' => $party] : []);
            }
        }

        return (int) $this->vouchers->post($companyId, $anchor->branch_id === null ? null : (int) $anchor->branch_id, $date, $lines, 'Rental return '.$booking->booking_no, $booking->booking_no)->id;
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws ValidationException
     */
    private function assertMapped(array $settings): void
    {
        foreach (RentalSettings::ACCOUNT_SLOTS as $slot) {
            if ($settings['accounts'][$slot] === null) {
                throw ValidationException::withMessages(['accounts' => ['Map the '.str_replace('_', ' ', $slot).' account in the rental settings before using rental posting.']]);
            }
        }
    }
}
