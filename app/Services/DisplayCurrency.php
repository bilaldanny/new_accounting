<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Models\Transaction;

/**
 * Display-level currency conversion. Sales and purchases carry no currency and every amount is stored and
 * posted in the company's base currency (Company Settings). When a customer has a different currency
 * (contacts.currency_id) and the company has set a rate for it (CurrencyRate), an invoice can also show its
 * total in that currency: the original amount stays the truth, the converted one is a convenience.
 *
 * Nothing is shown (null) when the customer has no currency, it is the base currency, the company has no base
 * currency set, or no rate was entered: a wrong number is worse than none.
 */
class DisplayCurrency
{
    public function baseCurrencyId(int $companyId): ?int
    {
        $id = CompanySetting::query()->where('company_id', $companyId)->value('currency_id');

        return $id === null || (int) $id === 0 ? null : (int) $id;
    }

    /**
     * @return array{code: string, symbol: string|null, base_code: string|null, base_symbol: string|null, rate: float, total: float, as_of: string|null}|null
     */
    public function forSale(Transaction $sale): ?array
    {
        // read straight from the contact: a sale is often loaded with a slim contact that has no currency
        $customerCurrencyId = $sale->contact_id === null ? 0 : (int) Contact::withTrashed()->whereKey($sale->contact_id)->value('currency_id');
        $companyId = (int) $sale->getRawOriginal('company_id');
        $baseId = $companyId === 0 ? null : $this->baseCurrencyId($companyId);

        if ($customerCurrencyId === 0 || $baseId === null || $customerCurrencyId === $baseId) {
            return null;
        }

        $rate = CurrencyRate::query()->where('company_id', $companyId)->where('currency_id', $customerCurrencyId)->first();

        if ($rate === null || $rate->rate <= 0) {
            return null;
        }

        $currencies = Currency::withTrashed()->whereIn('id', [$customerCurrencyId, $baseId])->get()->keyBy('id');

        return [
            'code' => (string) ($currencies[$customerCurrencyId]->code ?? ''),
            'symbol' => $currencies[$customerCurrencyId]->symbol ?? null,
            'base_code' => $currencies[$baseId]->code ?? null,
            'base_symbol' => $currencies[$baseId]->symbol ?? null,
            'rate' => $rate->rate,
            'total' => round((float) $sale->final_amount * $rate->rate, 2),
            'as_of' => $rate->updated_at?->toDateString(),
        ];
    }
}
