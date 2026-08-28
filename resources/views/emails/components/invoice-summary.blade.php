@php
    $rows = [
        'Invoice No' => $invoiceSummary['number'] ?? $invoiceSummary['Invoice No'] ?? null,
        'Customer' => $invoiceSummary['customer'] ?? $invoiceSummary['Customer'] ?? null,
        'Issue Date' => $invoiceSummary['issue_date'] ?? $invoiceSummary['Issue Date'] ?? null,
        'Due Date' => $invoiceSummary['due_date'] ?? $invoiceSummary['Due Date'] ?? null,
        'Subtotal' => $invoiceSummary['subtotal'] ?? $invoiceSummary['Subtotal'] ?? null,
        'Tax' => $invoiceSummary['tax'] ?? $invoiceSummary['Tax'] ?? null,
        'Discount' => $invoiceSummary['discount'] ?? $invoiceSummary['Discount'] ?? null,
    ];
    $total = $invoiceSummary['total'] ?? $invoiceSummary['Total'] ?? $invoiceSummary['amount_due'] ?? null;
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
    <tr>
        <td colspan="2" style="padding:14px 20px;background-color:#f1f5f9;border-bottom:1px solid #e2e8f0;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.7px;text-transform:uppercase;color:#334155;">
            Invoice Summary
        </td>
    </tr>
    <tr>
        <td style="padding:12px 20px;background-color:#fafafa;border-bottom:1px solid #e2e8f0;font-size:11px;line-height:14px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:#64748b;">
            Description
        </td>
        <td align="right" style="padding:12px 20px;background-color:#fafafa;border-bottom:1px solid #e2e8f0;font-size:11px;line-height:14px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:#64748b;">
            Amount
        </td>
    </tr>
    @foreach ($rows as $label => $value)
        @continue($value === null || $value === '')
        <tr>
            <td style="padding:14px 20px;border-bottom:1px solid #f1f5f9;font-size:14px;line-height:20px;color:#334155;font-weight:500;">
                {{ $label }}
            </td>
            <td align="right" style="padding:14px 20px;border-bottom:1px solid #f1f5f9;font-size:14px;line-height:20px;color:#0f172a;font-weight:600;">
                {{ $value }}
            </td>
        </tr>
    @endforeach
    @if ($total)
        <tr>
            <td style="padding:14px 20px;background-color:#f8fafc;border-top:2px solid #e2e8f0;font-size:15px;line-height:22px;font-weight:700;color:#0f172a;">
                {{ ($status ?? '') === 'paid' ? 'Total Settled Amount' : 'Total Amount' }}
            </td>
            <td align="right" style="padding:14px 20px;background-color:#f8fafc;border-top:2px solid #e2e8f0;font-size:18px;line-height:24px;font-weight:800;color:{{ $accentColor }};">
                {{ $total }}
            </td>
        </tr>
    @endif
</table>
