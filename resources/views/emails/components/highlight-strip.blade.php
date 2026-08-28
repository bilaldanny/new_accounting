@php
    $summary = is_array($invoiceSummary ?? null) ? $invoiceSummary : [];
    $rows = is_array($details ?? null) ? $details : [];
    $highlightAmount = $summary['total']
        ?? $summary['Total']
        ?? $summary['amount_due']
        ?? $rows['Total Amount']
        ?? $rows['Amount Due']
        ?? $rows['Amount']
        ?? null;
    $showHighlight = $highlightAmount !== null || ! empty($status);
@endphp

@if ($showHighlight)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 28px;background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
        <tr>
            @if ($highlightAmount !== null)
                <td valign="middle" style="padding:18px 24px;">
                    <p style="margin:0 0 4px;font-size:11px;line-height:14px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;color:#64748b;">
                        {{ ($status ?? '') === 'paid' ? 'Total Settled Amount' : 'Total Amount Due' }}
                    </p>
                    <p style="margin:0;font-size:20px;line-height:26px;font-weight:800;color:#0f172a;">
                        {{ $highlightAmount }}
                    </p>
                </td>
            @endif
            @isset($status)
                <td valign="middle" align="right" style="padding:18px 24px;">
                    @include('emails.components.status-badge')
                </td>
            @endisset
        </tr>
    </table>
@endif
