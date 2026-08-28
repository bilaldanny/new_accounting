@php
    $brandLetter = strtoupper(mb_substr((string) $companyName, 0, 1));
    $summary = is_array($invoiceSummary ?? null) ? $invoiceSummary : [];
    $rows = is_array($details ?? null) ? $details : [];
    $headerRef = $summary['number']
        ?? $summary['Invoice No']
        ?? $rows['Invoice Number']
        ?? $rows['Payment Reference']
        ?? null;
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
        <td class="email-section" style="background-color:#0f172a;padding:40px 48px 36px;border-bottom:3px solid {{ $accentColor }};">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                    <td valign="middle" style="padding:0;">
                        @if (! empty($companyLogo))
                            <img src="{{ $companyLogo }}" alt="{{ $companyName }}" width="140" style="display:block;max-width:140px;max-height:40px;height:auto;">
                        @else
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td valign="middle" width="32" height="32" style="width:32px;height:32px;border-radius:8px;background-color:{{ $accentColor }};text-align:center;">
                                        <span style="display:inline-block;font-size:16px;line-height:32px;font-weight:800;color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
                                            {{ $brandLetter }}
                                        </span>
                                    </td>
                                    <td valign="middle" style="padding-left:10px;font-size:22px;line-height:28px;font-weight:800;letter-spacing:-0.5px;color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
                                        {{ $companyName }}
                                    </td>
                                </tr>
                            </table>
                        @endif
                    </td>
                    <td valign="middle" align="right" class="header-meta" style="color:#64748b;font-size:12px;font-weight:500;line-height:18px;text-align:right;">
                        ACCOUNTING &amp; FINANCIAL SUITE
                        @if (! empty($headerRef))
                            <br>Ref: #{{ $headerRef }}
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
