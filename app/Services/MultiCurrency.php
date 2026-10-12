<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\CurrencyRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Optional multi-currency vouchers. A company switches it on in the Foreign Currency page; until then every function here does
 * nothing and a voucher line's currency fields are not even read, so the ledger is exactly what it was.
 *
 * With it on, a voucher line may carry a currency, the rate it was entered at (foreign units per one unit of the base currency,
 * the same sense as Currency Rates) and the foreign amount. `debit` / `credit` remain the base amount and the posted truth; it is
 * checked against foreign amount / rate (to the cent) and filled in when left empty. Nothing is gained or lost at entry: a
 * difference of rate only becomes a gain or loss when a line is settled (FxSettlement).
 */
class MultiCurrency
{
    private static ?bool $installed = null;

    public static function installed(): bool
    {
        return self::$installed ??= Schema::hasColumn('company_settings', 'multi_currency_vouchers') && Schema::hasColumn('t_account_details', 'currency_id');
    }

    public static function enabled(?int $companyId): bool
    {
        if ($companyId === null || ! self::installed()) {
            return false;
        }

        return (bool) DB::table('company_settings')->where('company_id', $companyId)->value('multi_currency_vouchers');
    }

    /**
     * The rate for a currency on a day: the latest dated rate on or before it, else the company's current Currency Rate.
     */
    public static function rateOn(int $companyId, int $currencyId, string $date): ?float
    {
        if (Schema::hasTable('currency_rate_history')) {
            $dated = DB::table('currency_rate_history')
                ->where('company_id', $companyId)->where('currency_id', $currencyId)->where('effective_on', '<=', $date)
                ->orderByDesc('effective_on')->value('rate');

            if ($dated !== null) {
                return (float) $dated;
            }
        }

        $current = CurrencyRate::query()->where('company_id', $companyId)->where('currency_id', $currencyId)->value('rate');

        return $current === null ? null : (float) $current;
    }

    public static function baseCurrencyId(int $companyId): ?int
    {
        return app(DisplayCurrency::class)->baseCurrencyId($companyId);
    }

    /**
     * Checks the foreign lines of a voucher and fills in the base amount of a line that has none. Lines without a currency are
     * returned untouched; with the feature off every line is.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function normalise(?int $companyId, ?string $date, array $lines): array
    {
        if (! self::enabled($companyId)) {
            return $lines;
        }

        $base = self::baseCurrencyId((int) $companyId);

        foreach ($lines as $index => $line) {
            $currencyId = (int) ($line['currency_id'] ?? 0);

            if ($currencyId === 0 || ! isset($line['foreign_amount']) || $line['foreign_amount'] === '') {
                continue;
            }

            if ($base === null) {
                throw ValidationException::withMessages(["taccountdetails.{$index}.currency_id" => ['Set the company base currency in Company Settings before using foreign currency.']]);
            }

            if ($currencyId === $base || ! Currency::query()->whereKey($currencyId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(["taccountdetails.{$index}.currency_id" => ['Choose an active foreign currency (not the base currency).']]);
            }

            $rate = isset($line['exchange_rate']) && $line['exchange_rate'] !== '' ? (float) $line['exchange_rate'] : self::rateOn((int) $companyId, $currencyId, (string) ($date ?: now()->toDateString()));

            if ($rate === null || $rate <= 0) {
                throw ValidationException::withMessages(["taccountdetails.{$index}.exchange_rate" => ['There is no exchange rate for this currency and day. Enter the rate.']]);
            }

            $foreign = round((float) $line['foreign_amount'], 2);

            if ($foreign <= 0) {
                throw ValidationException::withMessages(["taccountdetails.{$index}.foreign_amount" => ['The foreign amount must be more than zero.']]);
            }

            $baseAmount = round($foreign / $rate, 2);
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit === 0.0 && $credit === 0.0) {
                $side = $line['side'] ?? null;

                if (! in_array($side, ['debit', 'credit'], true)) {
                    throw ValidationException::withMessages(["taccountdetails.{$index}.side" => ['Say whether the foreign amount is a debit or a credit.']]);
                }

                $line[$side] = $baseAmount;
            } elseif (abs(max($debit, $credit) - $baseAmount) > 0.01) {
                throw ValidationException::withMessages(["taccountdetails.{$index}.foreign_amount" => ["The base amount does not match the foreign amount at this rate ({$baseAmount})."]]);
            }

            $line['exchange_rate'] = $rate;
            $line['foreign_amount'] = $foreign;
            $lines[$index] = $line;
        }

        return $lines;
    }

    /**
     * The columns a voucher line stores for its foreign part (empty when it has none, or the feature is off).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function attributes(?int $companyId, array $line): array
    {
        if (! self::enabled($companyId) || empty($line['currency_id']) || ! isset($line['foreign_amount'], $line['exchange_rate'])) {
            return [];
        }

        return [
            'currency_id' => (int) $line['currency_id'],
            'exchange_rate' => round((float) $line['exchange_rate'], 6),
            'foreign_amount' => round((float) $line['foreign_amount'], 2),
        ];
    }
}
