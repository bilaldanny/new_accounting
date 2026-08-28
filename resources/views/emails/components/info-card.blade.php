<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
    <tr>
        <td style="padding:14px 20px;background-color:#f1f5f9;border-bottom:1px solid #e2e8f0;font-size:12px;line-height:16px;font-weight:700;letter-spacing:0.7px;text-transform:uppercase;color:#334155;">
            Account details
        </td>
    </tr>
    @foreach ($details as $label => $value)
        <tr>
            <td style="padding:14px 20px;border-bottom:{{ $loop->last ? '0' : '1px' }} solid #f1f5f9;background-color:#ffffff;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                    <tr>
                        <td style="font-size:13px;line-height:20px;color:#64748b;">{{ $label }}</td>
                        <td align="right" style="font-size:14px;line-height:20px;font-weight:600;color:#0f172a;">{{ $value }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    @endforeach
</table>
