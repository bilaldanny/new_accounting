<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    <tr>
        <td class="email-section" align="center" style="background-color:#0f172a;padding:36px 48px;border-top:1px solid #1e293b;">
            <p style="margin:0 0 8px;font-size:16px;line-height:22px;font-weight:700;color:#ffffff;">
                {{ $companyName }}
            </p>
            <p style="margin:0 0 20px;font-size:12px;line-height:1.6;color:#64748b;">
                Need help?
                @if (! empty($supportEmail))
                    Contact
                    <a href="mailto:{{ $supportEmail }}" style="color:{{ $accentColor }};text-decoration:none;font-weight:600;">{{ $supportEmail }}</a>
                @else
                    Contact our support team.
                @endif
            </p>
            @if (! empty($privacyUrl) || ! empty($termsUrl))
                <p style="margin:0 0 8px;font-size:12px;line-height:18px;">
                    @if (! empty($privacyUrl))
                        <a href="{{ $privacyUrl }}" style="color:{{ $accentColor }};text-decoration:none;font-weight:600;margin:0 10px;">Privacy Policy</a>
                    @endif
                    @if (! empty($privacyUrl) && ! empty($termsUrl))
                        <span style="color:#334155;">•</span>
                    @endif
                    @if (! empty($termsUrl))
                        <a href="{{ $termsUrl }}" style="color:{{ $accentColor }};text-decoration:none;font-weight:600;margin:0 10px;">Terms &amp; Conditions</a>
                    @endif
                </p>
            @endif
            <p style="margin:24px 0 0;font-size:11px;line-height:16px;color:#475569;">
                &copy; {{ date('Y') }} {{ $companyName }}. All rights reserved.
            </p>
        </td>
    </tr>
</table>
