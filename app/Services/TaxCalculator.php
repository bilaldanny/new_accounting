<?php

namespace App\Services;

use App\Models\Tax;
use App\Models\TaxExemption;
use Illuminate\Validation\ValidationException;

/**
 * Works out the tax of a sale or purchase line by line, so the document's tax no longer depends on what a client typed.
 *
 * - A line's tax is a single tax or a group. A group adds its taxes, or - when it is compound - charges each tax on the amount
 *   plus the taxes before it (in the order of the group).
 * - Prices are either exclusive (the tax is added on top) or inclusive (the line amount already holds it, so it is carved out).
 * - A document-level discount is shared between the lines in proportion to their amounts before the tax is worked out.
 * - A line is exempt (tax 0, the rule kept on the line) when an active exemption rule matches its customer or its item.
 *
 * Money is rounded to 2 places per line; the document's tax is the sum of its lines' rounded taxes.
 */
class TaxCalculator
{
    /**
     * @param  list<array{tax_id: int|null, product_id: int|null, amount: float}>  $lines
     * @return array{lines: list<array{tax_id: int|null, tax_amount: float, net_amount: float, tax_exemption_id: int|null, components: list<array{id: int, name: string, rate: float, amount: float}>}>, tax_total: float, exclusive_tax: float}
     *
     * @throws ValidationException
     */
    public function calculate(array $lines, bool $inclusive, ?int $companyId, ?int $contactId, ?string $date, float $documentDiscount = 0.0, string $field = 'selllines'): array
    {
        $taxes = $this->loadTaxes($lines, $companyId, $field);
        $exemptions = $companyId === null ? collect() : TaxExemption::activeRules($companyId, $date);
        $totalAmount = array_sum(array_map(fn (array $line): float => max((float) $line['amount'], 0.0), $lines));
        $discount = min(max($documentDiscount, 0.0), $totalAmount);

        $results = [];
        $taxTotal = 0.0;

        foreach ($lines as $index => $line) {
            $amount = (float) $line['amount'];
            $base = $totalAmount > 0 ? $amount - ($discount * max($amount, 0.0) / $totalAmount) : $amount;
            $taxId = $line['tax_id'];
            $tax = $taxId === null ? null : $taxes[$taxId];

            if ($tax === null) {
                $results[] = ['tax_id' => null, 'tax_amount' => 0.0, 'net_amount' => round($base, 2), 'tax_exemption_id' => null, 'components' => []];

                continue;
            }

            $exemption = TaxExemption::matching($exemptions, $contactId, $line['product_id'] ?? null, (int) $taxId);

            if ($exemption !== null) {
                $results[] = ['tax_id' => (int) $taxId, 'tax_amount' => 0.0, 'net_amount' => round($base, 2), 'tax_exemption_id' => (int) $exemption->id, 'components' => []];

                continue;
            }

            [$taxAmount, $net, $components] = $this->taxOf($tax['rates'], $tax['compound'], $base, $inclusive);
            $taxTotal += $taxAmount;
            $results[] = ['tax_id' => (int) $taxId, 'tax_amount' => $taxAmount, 'net_amount' => $net, 'tax_exemption_id' => null, 'components' => $components];
        }

        $taxTotal = round($taxTotal, 2);

        return ['lines' => $results, 'tax_total' => $taxTotal, 'exclusive_tax' => $inclusive ? 0.0 : $taxTotal];
    }

    /**
     * The tax a withholding tax takes from a document.
     */
    public function withholding(Tax $tax, float $netValue, float $grossValue): float
    {
        $base = $tax->applies_on === 'gross' ? $grossValue : $netValue;

        return round(max($base, 0.0) * ((float) $tax->percentage / 100), 2);
    }

    /**
     * @param  list<array{id: int, name: string, rate: float}>  $rates
     * @return array{0: float, 1: float, 2: list<array{id: int, name: string, rate: float, amount: float}>} [tax, net, components]
     */
    private function taxOf(array $rates, bool $compound, float $base, bool $inclusive): array
    {
        $net = $base;

        if ($inclusive) {
            $divisor = 1.0;

            foreach ($rates as $rate) {
                $divisor = $compound ? $divisor * (1 + $rate['rate'] / 100) : $divisor + $rate['rate'] / 100;
            }

            $net = $base / $divisor;
        }

        $components = [];
        $running = 0.0;

        foreach ($rates as $rate) {
            $on = $compound ? $net + $running : $net;
            $amount = $on * $rate['rate'] / 100;
            $running += $amount;
            $components[] = ['id' => $rate['id'], 'name' => $rate['name'], 'rate' => $rate['rate'], 'amount' => round($amount, 2)];
        }

        $taxAmount = $inclusive ? round($base - $net, 2) : round($running, 2);

        return [$taxAmount, round($inclusive ? $base - $taxAmount : $net, 2), $components];
    }

    /**
     * @param  list<array{tax_id: int|null, product_id: int|null, amount: float}>  $lines
     * @return array<int, array{rates: list<array{id: int, name: string, rate: float}>, compound: bool}>
     *
     * @throws ValidationException
     */
    private function loadTaxes(array $lines, ?int $companyId, string $field): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (array $line): ?int => $line['tax_id'], $lines))));

        if ($ids === []) {
            return [];
        }

        $found = Tax::query()->whereIn('id', $ids)->where('kind', Tax::KIND_SALES)->get()->keyBy('id');
        $parts = Tax::query()->whereIn('id', $found->flatMap(fn (Tax $tax) => $tax->subTaxIds())->unique()->all())->get()->keyBy('id');
        $resolved = [];

        foreach ($lines as $index => $line) {
            $taxId = $line['tax_id'];

            if ($taxId === null || isset($resolved[$taxId])) {
                continue;
            }

            $tax = $found->get($taxId);

            if ($tax === null || ($companyId !== null && (int) $tax->company_id !== $companyId)) {
                throw ValidationException::withMessages(["{$field}.{$index}.tax_id" => ['Choose a tax of this company, or leave the line without tax.']]);
            }

            $rates = (int) $tax->type === 1
                ? collect($tax->subTaxIds())->map(fn (int $id) => $parts->get($id))->filter()->map(fn (Tax $part): array => ['id' => (int) $part->id, 'name' => $part->name, 'rate' => (float) $part->percentage])->values()->all()
                : [['id' => (int) $tax->id, 'name' => $tax->name, 'rate' => (float) $tax->percentage]];

            $resolved[$taxId] = ['rates' => $rates, 'compound' => (bool) $tax->compound];
        }

        return $resolved;
    }
}
