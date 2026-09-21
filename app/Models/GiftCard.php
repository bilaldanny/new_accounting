<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A prepaid gift card / voucher with a balance. Issuing it writes an `issue` ledger entry for the face
 * value; `redeem()` and `topUp()` move the balance and write one entry each, all inside a transaction on
 * a row-locked card so two redemptions at the same moment can never spend the same money twice.
 *
 * Master data plus a ledger: no sale reads a gift card yet, so nothing is deducted from an invoice by
 * this model (an entry may carry a `transaction_id` for the sale it paid).
 */
class GiftCard extends Model
{
    use SoftDeletes;

    /**
     * Characters a generated code is made of: no 0/O/1/I so a code read out over the phone is not misheard.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Columns the list may be sorted by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['code', 'initial_value', 'balance', 'expires_at', 'is_active', 'created_at'];

    protected $fillable = [
        'company_id',
        'code',
        'contact_id',
        'initial_value',
        'balance',
        'expires_at',
        'note',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'initial_value' => 'decimal:2',
            'balance' => 'decimal:2',
            'expires_at' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    protected function companyId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<GiftCardEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(GiftCardEntry::class);
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if (! $user?->company_id) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('company_id', $user->company_id);
    }

    /**
     * The name shown for the holder: the business name, else first and last name; null without a holder.
     */
    public function holderName(): ?string
    {
        $contact = $this->contact;

        if ($contact === null) {
            return null;
        }

        $name = trim((string) $contact->business_name);

        if ($name === '') {
            $name = trim($contact->first_name.' '.$contact->last_name);
        }

        return $name === '' ? null : $name;
    }

    /**
     * Why $amount cannot be moved on this card on $date, or null when it can. Both redeeming and topping
     * up need a switched-on, unexpired card (the last day is still valid); only redeeming needs the funds.
     *
     * @return 'inactive'|'expired'|'insufficient_balance'|null
     */
    public function rejectionReason(float $amount = 0, bool $redeeming = true, CarbonInterface|string|null $date = null): ?string
    {
        $day = Carbon::parse($date ?? now())->startOfDay();

        return match (true) {
            ! $this->is_active => 'inactive',
            $this->expires_at !== null && $day->gt($this->expires_at->copy()->startOfDay()) => 'expired',
            $redeeming && round($amount, 2) > round((float) $this->balance, 2) => 'insufficient_balance',
            default => null,
        };
    }

    /**
     * Takes $amount off the balance. Throws a RuntimeException whose message is the rejection reason when
     * the card cannot pay it (see `rejectionReason`); the caller has already checked the amount is above 0.
     */
    public function redeem(float $amount, ?string $note = null, ?int $transactionId = null, ?int $userId = null): GiftCardEntry
    {
        return $this->move(GiftCardEntry::TYPE_REDEEM, $amount, $note, $transactionId, $userId);
    }

    /**
     * Adds $amount to the balance of a switched-on, unexpired card.
     */
    public function topUp(float $amount, ?string $note = null, ?int $userId = null): GiftCardEntry
    {
        return $this->move(GiftCardEntry::TYPE_TOPUP, $amount, $note, null, $userId);
    }

    private function move(string $type, float $amount, ?string $note, ?int $transactionId, ?int $userId): GiftCardEntry
    {
        $amount = round($amount, 2);
        $redeeming = $type === GiftCardEntry::TYPE_REDEEM;

        return DB::transaction(function () use ($type, $amount, $redeeming, $note, $transactionId, $userId): GiftCardEntry {
            $card = self::query()->lockForUpdate()->findOrFail($this->getKey());

            $reason = $card->rejectionReason($amount, $redeeming);

            if ($reason !== null) {
                throw new RuntimeException($reason);
            }

            $card->balance = round((float) $card->balance + ($redeeming ? -$amount : $amount), 2);
            $card->save();

            $entry = $card->entries()->create([
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $card->balance,
                'transaction_id' => $transactionId,
                'user_id' => $userId,
                'note' => $note,
            ]);

            $this->setRawAttributes($card->getAttributes(), true);

            return $entry;
        });
    }

    /**
     * Credits money back to a card because the sale it paid was deleted, returned or reopened as a draft.
     * It is written as a `topup` entry that names the sale (a manual top-up has no sale), and it is always
     * accepted: an inactive, expired or even trashed card still gets the money back, so a customer's money
     * is never lost to a card's state. Returns null when the card no longer exists at all.
     */
    public static function refundForSale(int $cardId, float $amount, int $transactionId, string $note, ?int $userId = null): ?GiftCardEntry
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($cardId, $amount, $transactionId, $note, $userId): ?GiftCardEntry {
            $card = self::withTrashed()->lockForUpdate()->find($cardId);

            if ($card === null) {
                return null;
            }

            $card->balance = round((float) $card->balance + $amount, 2);
            $card->save();

            return $card->entries()->create([
                'type' => GiftCardEntry::TYPE_TOPUP,
                'amount' => $amount,
                'balance_after' => $card->balance,
                'transaction_id' => $transactionId,
                'user_id' => $userId,
                'note' => $note,
            ]);
        });
    }

    /**
     * Takes money out again for a sale that is back (restored, or finished again) after a refund. Unlike a
     * refund this needs a usable card: a RuntimeException says why not (`missing`, `deleted`, `inactive`,
     * `expired`, `insufficient_balance`).
     */
    public static function chargeForSale(int $cardId, float $amount, int $transactionId, string $note, ?int $userId = null): GiftCardEntry
    {
        $amount = round($amount, 2);

        return DB::transaction(function () use ($cardId, $amount, $transactionId, $note, $userId): GiftCardEntry {
            $card = self::withTrashed()->lockForUpdate()->find($cardId);

            if ($card === null) {
                throw new RuntimeException('missing');
            }

            if ($card->trashed()) {
                throw new RuntimeException('deleted');
            }

            $reason = $card->rejectionReason($amount);

            if ($reason !== null) {
                throw new RuntimeException($reason);
            }

            $card->balance = round((float) $card->balance - $amount, 2);
            $card->save();

            return $card->entries()->create([
                'type' => GiftCardEntry::TYPE_REDEEM,
                'amount' => $amount,
                'balance_after' => $card->balance,
                'transaction_id' => $transactionId,
                'user_id' => $userId,
                'note' => $note,
            ]);
        });
    }

    /**
     * A code no card of the company (trashed ones included) has: `GC-` and 10 characters.
     */
    public static function generateCode(?int $companyId): string
    {
        $length = strlen(self::CODE_ALPHABET);

        do {
            $code = 'GC-';

            for ($i = 0; $i < 10; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, $length - 1)];
            }
        } while (self::codeExists($code, $companyId));

        return $code;
    }

    public static function codeExists(string $code, ?int $companyId, ?int $exceptId = null): bool
    {
        return self::withTrashed()
            ->where('company_id', $companyId)
            ->where('code', strtoupper(trim($code)))
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->exists();
    }

    /**
     * The live card with this code in the company (codes are case-insensitive).
     */
    public static function findByCode(string $code, int $companyId): ?self
    {
        return self::query()
            ->where('company_id', $companyId)
            ->where('code', strtoupper(trim($code)))
            ->first();
    }

    /**
     * The company a record is saved under: the superadmin picks one, everyone else is always their own
     * company whatever the request says.
     */
    public static function scopedCompanyId(object $request, ?int $current = null): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return self::resolveScopedId($request->company_id) ?? $current;
        }

        return $user?->company_id ? (int) $user->company_id : $current;
    }

    /**
     * Creates the card with its `issue` entry. A blank code is generated; the balance starts at the face value.
     */
    public static function issue(object $request): self
    {
        $companyId = self::scopedCompanyId($request);
        $value = round((float) $request->initial_value, 2);
        $code = strtoupper(trim((string) $request->code));

        $card = new self;
        $card->company_id = $companyId;
        $card->code = $code === '' ? self::generateCode($companyId) : $code;
        $card->contact_id = self::resolveScopedId($request->contact_id);
        $card->initial_value = $value;
        $card->balance = $value;
        $card->expires_at = self::blankToNull($request->expires_at);
        $card->note = self::blankToNull($request->note);
        $card->is_active = $request->has('is_active') ? $request->boolean('is_active') : true;
        $card->save();

        $card->entries()->create([
            'type' => GiftCardEntry::TYPE_ISSUE,
            'amount' => $value,
            'balance_after' => $value,
            'user_id' => Auth::id(),
        ]);

        return $card;
    }

    /**
     * Only the holder, the expiry, the note and the status can be edited: the code and the money are
     * fixed once issued (money moves through `redeem` and `topUp`).
     */
    public static function updateGiftCard(object $request, int|string $id): self
    {
        $card = self::query()->visibleToCurrentUser()->findOrFail($id);
        $card->contact_id = self::resolveScopedId($request->contact_id);
        $card->expires_at = self::blankToNull($request->expires_at);
        $card->note = self::blankToNull($request->note);
        $card->is_active = $request->has('is_active') ? $request->boolean('is_active') : $card->is_active;
        $card->save();

        return $card;
    }

    public static function deleteGiftCard(int|string $id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
