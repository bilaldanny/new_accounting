<?php

namespace App\Services\Documents\TemplateCode;

/**
 * Maps a document's data (DocumentData) onto the names a template-code author writes, and lists them for the editor.
 * Only these keys exist in a template: nothing else of the company or the system can be reached.
 */
final class TemplateCodeData
{
    /** @var array<string, array{0: string, 1: string}> */
    public const PLACEHOLDERS = [
        'invoice.number' => ['Document', 'Invoice / bill number'],
        'invoice.date' => ['Document', 'Date'],
        'invoice.due_date' => ['Document', 'Due date'],
        'invoice.status' => ['Document', 'Status'],
        'invoice.type' => ['Document', 'Document type'],
        'invoice.note' => ['Document', 'Note'],
        'invoice.reference' => ['Document', 'Reference'],
        'customer.name' => ['Customer / supplier', 'Name'],
        'customer.address' => ['Customer / supplier', 'Address'],
        'customer.phone' => ['Customer / supplier', 'Phone'],
        'customer.email' => ['Customer / supplier', 'Email'],
        'customer.ntn' => ['Customer / supplier', 'NTN'],
        'customer.strn' => ['Customer / supplier', 'STRN'],
        'ship_to' => ['Customer / supplier', 'Ship-to address'],
        'company.name' => ['Company', 'Name'],
        'company.address' => ['Company', 'Address'],
        'company.phone' => ['Company', 'Phone'],
        'company.email' => ['Company', 'Email'],
        'company.ntn' => ['Company', 'NTN'],
        'company.strn' => ['Company', 'STRN'],
        'company.logo' => ['Company', 'Logo address (use inside <img src="">)'],
        '#lines' => ['Lines', 'Repeat for every line: {{#lines}} ... {{/lines}}'],
        'sr' => ['Lines', 'Serial number (inside lines)'],
        'sku' => ['Lines', 'SKU (inside lines)'],
        'name' => ['Lines', 'Item name (inside lines)'],
        'qty' => ['Lines', 'Quantity (inside lines)'],
        'unit' => ['Lines', 'Unit (inside lines)'],
        'price' => ['Lines', 'Unit price (inside lines)'],
        'discount' => ['Lines', 'Discount % (inside lines)'],
        'tax' => ['Lines', 'Tax (inside lines)'],
        'total' => ['Lines', 'Line total (inside lines)'],
        'totals.subtotal' => ['Totals', 'Subtotal'],
        'totals.discount' => ['Totals', 'Discount'],
        'totals.tax' => ['Totals', 'Tax'],
        'totals.shipping' => ['Totals', 'Shipping'],
        'totals.grand' => ['Totals', 'Grand total'],
        'totals.paid' => ['Totals', 'Paid'],
        'totals.balance' => ['Totals', 'Balance'],
        'totals.previous_balance' => ['Totals', 'Previous balance'],
        'terms' => ['Other', 'Terms and conditions'],
        'footer_note' => ['Other', 'Footer note'],
        'fbr_number' => ['Other', 'FBR invoice number'],
        'qr' => ['Other', 'QR code image (use inside <img src="">)'],
    ];

    /**
     * @param  array<string, mixed>  $data  DocumentData::forTransaction() or the sample
     * @return array<string, mixed>
     */
    public static function from(array $data): array
    {
        $money = fn (mixed $value): string => number_format((float) $value, 2, '.', ',');
        $qty = fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
        $party = $data['party'] ?? [];
        $company = $data['company'] ?? [];
        $doc = $data['doc'] ?? [];
        $totals = $data['totals'] ?? [];

        return [
            'invoice' => [
                'number' => (string) ($doc['number'] ?? ''), 'date' => (string) ($doc['date'] ?? ''), 'due_date' => (string) ($doc['due_date'] ?? ''),
                'status' => (string) ($doc['status'] ?? ''), 'type' => (string) ($doc['type'] ?? ''), 'note' => (string) ($doc['note'] ?? ''), 'reference' => (string) ($doc['reference'] ?? ''),
            ],
            'customer' => [
                'name' => (string) ($party['name'] ?? ''), 'address' => (string) ($party['address'] ?? ''), 'phone' => (string) ($party['phone'] ?? ''),
                'email' => (string) ($party['email'] ?? ''), 'ntn' => (string) ($party['ntn'] ?? ''), 'strn' => (string) ($party['strn'] ?? ''),
            ],
            'ship_to' => (string) ($data['ship_to'] ?? ''),
            'company' => [
                'name' => (string) ($company['name'] ?? ''), 'address' => (string) ($company['address'] ?? ''), 'phone' => (string) ($company['phone'] ?? ''),
                'email' => (string) ($company['email'] ?? ''), 'ntn' => (string) ($company['ntn'] ?? ''), 'strn' => (string) ($company['strn'] ?? ''),
                'logo' => (string) ($company['logo_url'] ?? ''),
            ],
            'lines' => collect($data['lines'] ?? [])->map(fn (array $line): array => [
                'sr' => (string) ($line['sr'] ?? ''), 'sku' => (string) ($line['sku'] ?? ''), 'name' => (string) ($line['name'] ?? ''), 'qty' => $qty($line['qty'] ?? 0),
                'unit' => (string) ($line['unit'] ?? ''), 'price' => $money($line['price'] ?? 0), 'discount' => $qty($line['discount'] ?? 0), 'tax' => $money($line['tax'] ?? 0),
                'total' => $money($line['amount'] ?? 0),
            ])->values()->all(),
            'totals' => [
                'subtotal' => $money($totals['subtotal'] ?? 0), 'discount' => $money($totals['discount'] ?? 0), 'tax' => $money($totals['tax'] ?? 0),
                'shipping' => $money($totals['shipping'] ?? 0), 'grand' => $money($totals['total'] ?? 0), 'paid' => $money($totals['paid'] ?? 0),
                'balance' => $money($totals['balance'] ?? 0), 'previous_balance' => $money($totals['previous_balance'] ?? 0),
            ],
            'terms' => (string) ($data['terms'] ?? ''),
            'footer_note' => (string) ($data['footer_note'] ?? ''),
            'fbr_number' => (string) ($data['fbr_number'] ?? ''),
        ];
    }
}
