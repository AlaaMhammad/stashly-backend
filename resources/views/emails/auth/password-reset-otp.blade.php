<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>{{ $appName }} password reset code</title>
    <style>
        @media only screen and (max-width: 620px) {
            .container { width: 100% !important; }
            .px { padding-left: 20px !important; padding-right: 20px !important; }
            .otp { font-size: 30px !important; letter-spacing: 6px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #eef2ff; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">
    {{-- Preheader: shown in the inbox list next to the subject. --}}
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; color: transparent; font-size: 1px; line-height: 1px;">
        Your {{ $appName }} password reset code is {{ $code }}. It expires in {{ $minutes }} minutes.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef2ff" style="background-color: #eef2ff;">
        <tr>
            <td align="center" style="padding: 32px 12px;">
                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width: 600px; max-width: 600px;">
                    {{-- Header --}}
                    <tr>
                        <td align="center" bgcolor="#4f46e5" style="background-color: #4f46e5; border-radius: 16px 16px 0 0; padding: 28px 24px;">
                            <span style="font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 26px; font-weight: 800; letter-spacing: 0.5px; color: #ffffff;">{{ $appName }}</span>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td class="px" bgcolor="#ffffff" style="background-color: #ffffff; padding: 36px 40px 8px 40px; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0f172a;">
                            <h1 style="margin: 0 0 12px 0; font-size: 22px; line-height: 30px; font-weight: 700; color: #0f172a;">Reset your password</h1>
                            <p style="margin: 0 0 8px 0; font-size: 16px; line-height: 24px; color: #334155;">
                                @if (filled($name))
                                    Hi {{ $name }},
                                @else
                                    Hi,
                                @endif
                            </p>
                            <p style="margin: 0; font-size: 16px; line-height: 24px; color: #334155;">
                                Enter this code in the {{ $appName }} app to choose a new password.
                            </p>
                        </td>
                    </tr>

                    {{-- Code --}}
                    <tr>
                        <td class="px" bgcolor="#ffffff" align="center" style="background-color: #ffffff; padding: 24px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin: 0 auto;">
                                <tr>
                                    <td align="center" bgcolor="#f1f5f9" style="background-color: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 32px;">
                                        <span class="otp" style="font-size: 34px; letter-spacing: 8px; font-weight: 800; color: #4f46e5; font-family: monospace; background: #f1f5f9;">{{ $code }}</span>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 16px 0 0 0; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 14px; line-height: 22px; color: #475569;">
                                This code expires in <strong style="color: #0f172a;">{{ $minutes }} minutes</strong> and can be used once.
                            </p>
                        </td>
                    </tr>

                    {{-- Security notice --}}
                    <tr>
                        <td class="px" bgcolor="#ffffff" style="background-color: #ffffff; padding: 0 40px 36px 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td bgcolor="#fef2f2" style="background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 14px 16px; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 14px; line-height: 21px; color: #b91c1c;">
                                        <strong>Didn't request this?</strong> If you didn't request this, ignore this email. Your password will not change. Never share this code with anyone &mdash; {{ $appName }} will never ask for it.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" bgcolor="#f8fafc" style="background-color: #f8fafc; border-radius: 0 0 16px 16px; border-top: 1px solid #e2e8f0; padding: 20px 24px; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 12px; line-height: 18px; color: #94a3b8;">
                            This is an automated message from {{ $appName }}. Please do not reply.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
