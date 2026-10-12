<?php

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\DocumentSetting;
use App\Models\FbrSubmission;
use App\Models\Transaction;
use App\Services\Reports\PartyAgingReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The facts a printed document needs, read for one sale or purchase by explicit ids (no signed-in user is assumed, because the public
 * share page prints with no session): the company and its logo, the document, the party, the lines, the totals, the terms and footer
 * of the company's Invoice Settings, the custom fields marked for print, and the FBR number only when FBR really issued it.
 */
class DocumentData
{
    /**
     * The document type a transaction prints as when none is asked for.
     */
    public static function typeOf(Transaction $transaction): string
    {
        return match ($transaction->type) {
            Transaction::TYPE_PURCHASE => 'purchase_order',
            Transaction::TYPE_SELL_RETURN => 'sell_return',
            default => $transaction->status === 'quotation' ? 'quotation' : 'invoice',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function forTransaction(Transaction $transaction, ?string $shareUrl = null): array
    {
        $companyId = (int) $transaction->getRawOriginal('company_id');
        $company = Company::query()->with('companySetting')->find($companyId);
        $contact = $transaction->contact_id === null ? null : DB::table('contacts')->where('id', $transaction->contact_id)->first();
        $isPurchase = $transaction->type === Transaction::TYPE_PURCHASE;
        $invoice = DocumentSetting::valuesFor($companyId, 'invoice');

        $due = PartyAgingReport::dueDate(
            (string) $transaction->getRawOriginal('transaction_date'), $transaction->pay_term, $transaction->pay_type, $contact->pay_term ?? null, $contact->pay_type ?? null,
        );

        $paid = round((float) DB::table('payments')->where('transaction_id', $transaction->id)->where('is_return', false)->sum('amount'), 2);
        $final = round((float) $transaction->final_amount, 2);

        $custom = [];

        if (Schema::hasTable('custom_field_definitions')) {
            $custom = DB::table('custom_field_definitions as d')
                ->leftJoin('custom_field_values as v', fn ($join) => $join->on('v.field_id', '=', 'd.id')->where('v.entity', '=', $isPurchase ? 'purchase' : 'sale')->where('v.entity_id', '=', $transaction->id))
                ->where('d.company_id', $companyId)->where('d.entity', $isPurchase ? 'purchase' : 'sale')->where('d.is_active', true)->where('d.show_on_print', true)
                ->orderBy('d.sort_order')->orderBy('d.id')->get(['d.label', 'd.type', 'v.value'])
                ->filter(fn (object $row): bool => $row->value !== null && $row->value !== '')
                ->map(fn (object $row): array => ['label' => (string) $row->label, 'value' => $row->type === 'checkbox' ? ($row->value ? 'Yes' : 'No') : (string) $row->value])->values()->all();
        }

        return [
            'company' => [
                'name' => (string) ($company?->companySetting?->business_name ?: $company?->name),
                'address' => (string) ($company?->companySetting?->address ?: $company?->address),
                'phone' => (string) ($company?->phone ?: $company?->cell),
                'email' => (string) $company?->email,
                'logo_url' => Company::logoUrl($company?->companySetting?->logo ?? $company?->logo),
                'ntn' => (string) $company?->ntn_no,
                'strn' => (string) $company?->strn_no,
            ],
            'doc' => [
                'id' => (int) $transaction->id,
                'type' => self::typeOf($transaction),
                'number' => (string) $transaction->invoice_no,
                'date' => substr((string) $transaction->getRawOriginal('transaction_date'), 0, 10),
                'due_date' => $due->toDateString(),
                'status' => (string) $transaction->status,
                'note' => trim((string) $transaction->additional_note),
                'reference' => (string) $transaction->sup_ref_no,
            ],
            'party' => [
                'name' => $contact === null ? '' : trim((string) ($contact->business_name ?: trim($contact->first_name.' '.$contact->last_name))),
                'address' => (string) ($contact->address ?? ''),
                'phone' => (string) ($contact->mobile ?? ''),
                'email' => (string) ($contact->email ?? ''),
                'ntn' => (string) ($contact->ntn_number ?? ''),
                'strn' => (string) ($contact->strn_number ?? ''),
            ],
            'ship_to' => (string) ($transaction->shipping_address ?? ''),
            'lines' => $isPurchase ? $this->purchaseLines($transaction) : $this->sellLines($transaction),
            'totals' => [
                'subtotal' => round((float) $transaction->total_before_tax, 2),
                'discount' => round((float) $transaction->discount_amount, 2),
                'tax' => round((float) $transaction->tax_amount, 2),
                'shipping' => round((float) $transaction->shipping_charges, 2),
                'total' => $final,
                'paid' => $paid,
                'balance' => round($final - $paid, 2),
                'previous_balance' => $transaction->contact_id === null || $isPurchase ? 0.0 : round((float) Transaction::previousDueBalanceForContact((int) $transaction->contact_id, (int) $transaction->id), 2),
            ],
            'terms' => $invoice['show_terms_and_conditions'] && trim((string) $invoice['terms_and_conditions']) !== '' ? trim((string) $invoice['terms_and_conditions']) : null,
            'footer_note' => $invoice['show_footer_note'] && trim((string) $invoice['footer_note']) !== '' ? trim((string) $invoice['footer_note']) : null,
            'show_tax_numbers' => (bool) $invoice['show_tax_numbers'],
            'fbr_number' => FbrSubmission::query()->where('transaction_id', $transaction->id)->where('status', FbrSubmission::STATUS_SUBMITTED)->value('fbr_invoice_number'),
            'custom_fields' => $custom,
            'share_url' => $shareUrl,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sellLines(Transaction $transaction): array
    {
        return DB::table('sell_lines as l')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'l.unit_id')
            ->where('l.transaction_id', $transaction->id)->orderBy('l.id')
            ->get(['p.name', 'p.sku', 'u.short_name as unit', 'l.quantity', 'l.unit_price', 'l.discount_percent', 'l.tax_amount', 'l.subtotal'])
            ->values()
            ->map(fn (object $row, int $index): array => [
                'sr' => $index + 1, 'sku' => (string) $row->sku, 'name' => (string) $row->name, 'qty' => (float) $row->quantity, 'unit' => (string) $row->unit,
                'price' => round((float) $row->unit_price, 2), 'discount' => round((float) $row->discount_percent, 2), 'tax' => round((float) $row->tax_amount, 2), 'amount' => round((float) $row->subtotal, 2),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function purchaseLines(Transaction $transaction): array
    {
        return DB::table('purchase_lines as l')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'l.unit_id')
            ->where('l.transaction_id', $transaction->id)->orderBy('l.id')
            ->get(['p.name', 'p.sku', 'u.short_name as unit', 'l.quantity', 'l.purchase_rate', 'l.discount_percent', 'l.tax_amount', 'l.packing_qty'])
            ->values()
            ->map(function (object $row, int $index): array {
                $packing = $row->packing_qty !== null && (float) $row->packing_qty >= 1 ? (float) $row->packing_qty : 1.0;
                $amount = (float) $row->quantity * (float) $row->purchase_rate * $packing * (1 - (float) $row->discount_percent / 100);

                return [
                    'sr' => $index + 1, 'sku' => (string) $row->sku, 'name' => (string) $row->name, 'qty' => (float) $row->quantity, 'unit' => (string) $row->unit,
                    'price' => round((float) $row->purchase_rate, 2), 'discount' => round((float) $row->discount_percent, 2), 'tax' => round((float) $row->tax_amount, 2), 'amount' => round($amount, 2),
                ];
            })->all();
    }
}
