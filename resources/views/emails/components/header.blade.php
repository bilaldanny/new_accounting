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
        <td class="email-section" align="center" style="background-color:#0f172a;padding:30px 38px 26px;border-bottom:3px solid {{ $accentColor }};text-align:center;">
            @if (! empty($companyLogo))
                <img src="{{ $companyLogo }}" alt="{{ $companyName }}" width="200" style="display:block;margin:0 auto;max-width:200px;height:auto;">
            @else
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
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
            @if (! empty($headerRef))
                <p style="margin:12px 0 0;color:#64748b;font-size:12px;line-height:18px;font-weight:500;text-align:center;">
                    Ref: #{{ $headerRef }}
                </p>
            @endif
        </td>
    </tr>
</table>
