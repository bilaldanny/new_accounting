<?php

namespace App\Models;

use Database\Factories\ContactLedgerWatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactLedgerWatch extends Model
{
    /** @use HasFactory<ContactLedgerWatchFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'contact_id',
        'last_row_id',
        'last_voucher_date',
        'last_voucher_no',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_voucher_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return array{row_id: string, voucher_date: string, voucher_no: string|null}|null
     */
    public static function payloadFor(?User $user, Contact $contact): ?array
    {
        if ($user === null) {
            return null;
        }

        $watch = self::query()
            ->where('user_id', $user->id)
            ->where('contact_id', $contact->id)
            ->first();

        return $watch?->toPayload();
    }

    /**
     * @return array{row_id: string, voucher_date: string, voucher_no: string|null}
     */
    public function toPayload(): array
    {
        return [
            'row_id' => $this->last_row_id,
            'voucher_date' => $this->last_voucher_date?->toDateString() ?? '',
            'voucher_no' => $this->last_voucher_no,
        ];
    }

    /**
     * @return array{row_id: string, voucher_date: string, voucher_no: string|null}|null
     */
    public static function markUntil(
        User $user,
        Contact $contact,
        ?string $rowId,
        ?string $voucherDate,
        ?string $voucherNo,
    ): ?array {
        if ($rowId === null || $rowId === '' || $voucherDate === null || $voucherDate === '') {
            self::query()
                ->where('user_id', $user->id)
                ->where('contact_id', $contact->id)
                ->delete();

            return null;
        }

        $watch = self::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'contact_id' => $contact->id,
            ],
            [
                'last_row_id' => $rowId,
                'last_voucher_date' => $voucherDate,
                'last_voucher_no' => $voucherNo,
            ],
        );

        return $watch->toPayload();
    }
}
