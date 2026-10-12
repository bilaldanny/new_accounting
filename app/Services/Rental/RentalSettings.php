<?php

namespace App\Services\Rental;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Rental settings of a company: whether returns post to the ledger (off by default) and the four accounts they use. With posting
 * off, bookings, deposits and charges are still worked out and kept on the booking, and no voucher is made.
 */
class RentalSettings
{
    public const ACCOUNT_SLOTS = ['rental_income', 'late_fee_income', 'damage_income', 'deposit_liability'];

    public const DEFAULTS = [
        'posting_enabled' => false,
        'accounts' => ['rental_income' => null, 'late_fee_income' => null, 'damage_income' => null, 'deposit_liability' => null],
    ];

    /**
     * @return array<string, mixed>
     */
    public function all(int $companyId): array
    {
        $saved = Schema::hasTable('rental_settings') ? (array) json_decode((string) DB::table('rental_settings')->where('company_id', $companyId)->value('settings'), true) : [];
        $values = array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        $values['accounts'] = array_merge(self::DEFAULTS['accounts'], array_intersect_key((array) ($saved['accounts'] ?? []), self::DEFAULTS['accounts']));

        return $values;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function save(int $companyId, array $input): array
    {
        $current = $this->all($companyId);
        $errors = [];

        if (array_key_exists('posting_enabled', $input)) {
            if (! in_array($input['posting_enabled'], [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                $errors['posting_enabled'] = ['Choose on or off.'];
            } else {
                $current['posting_enabled'] = filter_var($input['posting_enabled'], FILTER_VALIDATE_BOOLEAN);
            }
        }

        foreach (array_intersect_key((array) ($input['accounts'] ?? []), self::DEFAULTS['accounts']) as $slot => $accountId) {
            if ($accountId === null || $accountId === '') {
                $current['accounts'][$slot] = null;
            } elseif (! DB::table('chart_of_accounts')->where('company_id', $companyId)->where('id', (int) $accountId)->exists()) {
                $errors["accounts.{$slot}"] = ['Choose an account of this company.'];
            } else {
                $current['accounts'][$slot] = (int) $accountId;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::table('rental_settings')->updateOrInsert(['company_id' => $companyId], ['settings' => json_encode($current), 'updated_at' => now(), 'created_at' => now()]);

        return $current;
    }
}
