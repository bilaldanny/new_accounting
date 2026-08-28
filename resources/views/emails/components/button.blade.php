<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" align="center">
    <tr>
        <td align="center" style="padding:16px 0 28px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center">
                <tr>
                    <td align="center" bgcolor="{{ $accentColor }}" style="border-radius:10px;background-color:{{ $accentColor }};">
                        <!--[if mso]>
                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $buttonUrl }}" style="height:48px;v-text-anchor:middle;width:240px;" arcsize="20%" stroke="f" fillcolor="{{ $accentColor }}">
                            <w:anchorlock/>
                            <center style="color:#ffffff;font-family:Segoe UI,Arial,sans-serif;font-size:15px;font-weight:700;">{{ $buttonText }}</center>
                        </v:roundrect>
                        <![endif]-->
                        <!--[if !mso]><!-->
                        <a href="{{ $buttonUrl }}" target="_blank" style="display:inline-block;padding:14px 36px;font-size:15px;line-height:20px;font-weight:700;color:#FFFFFF;text-decoration:none;border-radius:10px;background-color:{{ $accentColor }};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
                            {{ $buttonText }} →
                        </a>
                        <!--<![endif]-->
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
