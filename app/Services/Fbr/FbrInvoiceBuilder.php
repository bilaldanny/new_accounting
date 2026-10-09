<?php

namespace App\Services\Fbr;

use App\Models\Contact;
use App\Models\FbrSetting;
use App\Models\Tax;
use App\Models\Transaction;

/**
 * The invoice as the FBR gateway receives it: who sells, who buys, what, and the tax per line.
 *
 * THIS IS NOT THE FBR SPECIFICATION. It collects the facts an e-invoice needs (seller and buyer NTN / STRN, the invoice number
 * and date, each item with its rate and tax, the totals) under this application's own field names. The exact field names, codes
 * (HS code, scenario, sale type ...) and signing FBR requires have to be taken from the verified FBR specification before a
 * live gateway is written; nothing here has been checked against it.
 */
class FbrInvoiceBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Transaction $sell, FbrSetting $settings): array
    {
        $sell->loadMissing(['selllines.product:id,name,sku', 'selllines.unit:id,name,short_name', 'contact', 'company']);
        $taxNames = Tax::query()->whereIn('id', $sell->selllines->pluck('tax_id')->filter()->unique()->all())->pluck('name', 'id');
        $taxRates = Tax::query()->whereIn('id', $sell->selllines->pluck('tax_id')->filter()->unique()->all())->pluck('percentage', 'id');
        $buyer = $sell->contact;

        return [
            'structure' => 'ACCUNIVO internal structure v0 - NOT the FBR specification',
            'environment' => $settings->environment ?: 'sandbox',
            'seller' => [
                'name' => $sell->company?->name,
                'ntn' => $sell->company?->ntn_no,
                'strn' => $sell->company?->strn_no,
                'address' => $sell->company?->address,
                'pos_id' => $settings->pos_id,
            ],
            'buyer' => $this->buyer($buyer),
            'invoice' => [
                'number' => $sell->invoice_no,
                'date' => $sell->transaction_date?->format('Y-m-d'),
                'prices_include_tax' => (bool) $sell->tax_inclusive,
            ],
            'items' => $sell->selllines->map(fn ($line): array => [
                'name' => $line->product?->name,
                'sku' => $line->product?->sku,
                'unit' => $line->unit?->short_name ?: $line->unit?->name,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price_after_discount,
                'value' => (float) $line->subtotal,
                'tax' => $line->tax_id === null ? null : $taxNames->get($line->tax_id),
                'tax_rate' => $line->tax_id === null ? null : (float) $taxRates->get($line->tax_id),
                'tax_amount' => $line->tax_amount === null ? 0.0 : (float) $line->tax_amount,
                'exempt' => $line->tax_exemption_id !== null,
            ])->values()->all(),
            'totals' => [
                'value_before_tax' => (float) $sell->total_before_tax,
                'tax' => (float) $sell->tax_amount,
                'withholding' => (float) ($sell->withholding_amount ?? 0),
                'total' => (float) $sell->final_amount,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buyer(?Contact $buyer): array
    {
        return [
            'name' => $buyer?->business_name ?: trim((string) $buyer?->first_name.' '.(string) $buyer?->last_name),
            'ntn' => $buyer?->ntn_number,
            'strn' => $buyer?->strn_number,
            'address' => $buyer?->address,
        ];
    }
}
