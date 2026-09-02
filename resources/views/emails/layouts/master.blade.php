<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
    <title>{{ $subject ?? $title }}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style type="text/css">
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        table { border-collapse: collapse !important; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #0b0f19; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; }
            .email-section { padding-left: 24px !important; padding-right: 24px !important; }
            .email-hero-title { font-size: 20px !important; line-height: 26px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#0b0f19;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#334155;">
    <div style="display:none;font-size:1px;color:#0b0f19;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
        {{ $preheader }}
    </div>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#0b0f19;">
        <tr>
            <td align="center" style="padding:40px 12px;">
                <table role="presentation" class="email-container" cellpadding="0" cellspacing="0" border="0" width="620" style="width:620px;max-width:620px;background-color:#ffffff;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td>
                            @include('emails.components.header')
                        </td>
                    </tr>
                    <tr>
                        <td class="email-section" style="padding:44px 48px;background-color:#ffffff;">
                            @include('emails.components.hero')

                            @if (! empty($userName))
                                <p style="margin:0 0 16px;font-size:15px;line-height:24px;color:#475569;">
                                    Hello {{ $userName }},
                                </p>
                            @endif

                            @foreach ($paragraphs as $paragraph)
                                <p style="margin:0 0 16px;font-size:15px;line-height:1.65;color:#475569;">
                                    {{ $paragraph }}
                                </p>
                            @endforeach

                            @yield('content')

                            @include('emails.components.highlight-strip')

                            @isset($alert)
                                <div style="padding:0 0 8px;">
                                    @include('emails.components.alert')
                                </div>
                            @endisset

                            @if (! empty($details))
                                <div style="padding:0 0 8px;">
                                    @include('emails.components.info-card')
                                </div>
                            @endif

                            @if (! empty($invoiceSummary))
                                <div style="padding:0 0 8px;">
                                    @include('emails.components.invoice-summary')
                                </div>
                            @endif

                            @if (! empty($buttonText) && ! empty($buttonUrl))
                                <div style="padding:8px 0 12px;">
                                    @include('emails.components.button')
                                </div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td>
                            @include('emails.components.footer')
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
