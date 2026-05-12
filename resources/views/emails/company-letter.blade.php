<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ config('app.name') }}</title>
</head>
<body style="margin:0; padding:0; background:#eef6ff; font-family: Arial, sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef6ff; padding:20px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff; border-radius:8px; padding:0;">
                {{-- Header --}}
                <tr>
                    <td style="background:#1062b9; padding:15px 20px; border-top-left-radius:8px; border-top-right-radius:8px;">
                        <table width="100%">
                            <tr>
                                <td style="color:#ffffff; font-size:18px; font-weight:bold;">
                                    {{ config('app.name') }}
                                </td>
                                <td align="right">
                                    <img src="https://res.cloudinary.com/dgisx7rv4/image/upload/f_auto,q_auto/favicon-32x32_a1njvt" style="width: 25px;" alt="Logo">
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Title (subject крупным заголовком над телом письма) --}}
                @if($title)
                <tr>
                    <td style="color:#273a55; font-size:18px; font-weight:bold; padding:25px 30px 0 30px;">
                        {{ $title }}
                    </td>
                </tr>
                @endif

                {{-- Letter body — текст пишется оператором целиком,
                     включая приветствие и подпись; шаблон в него не лезет. --}}
                <tr>
                    <td style="color:#273a55; font-size:14px; line-height:1.7; padding:15px 30px 20px 30px; white-space:pre-wrap;">{{ $letter }}</td>
                </tr>

                {{-- Signature --}}
                <tr>
                    <td style="color:#6b7280; font-size:13px; line-height:1.6; padding:10px 30px 30px 30px; border-bottom-left-radius:8px; border-bottom-right-radius:8px;">
                        Best regards, Bagalov Shamil <br><br>
                        HACCPilot Research<br>
                        <a href="https://demo.haccpilot.eu/en" style="color:#1062b9; text-decoration:none;">demo.haccpilot.eu</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
