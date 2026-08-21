<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $website->url }} is down!</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f5f7; font-family:-apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; color:#1f2933;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7; padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="max-width:560px; background-color:#ffffff; border:1px solid #e4e7eb; border-radius:6px;">
                <tr>
                    <td style="padding:24px; border-bottom:3px solid #d64545;">
                        <p style="margin:0; font-size:13px; letter-spacing:0.08em; text-transform:uppercase; color:#d64545;">
                            Uptime alert
                        </p>
                        <h1 style="margin:8px 0 0; font-size:20px; line-height:1.4; color:#1f2933;">
                            {{ $website->url }} is down!
                        </h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px; font-size:15px; line-height:1.6;">
                        <p style="margin:0 0 16px;">Hello,</p>

                        <p style="margin:0 0 16px;">
                            Our monitoring check could not reach one of your websites, so it has been marked as down.
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                               style="margin:0 0 16px; font-size:14px; border-collapse:collapse;">
                            <tr>
                                <td style="padding:8px 0; color:#616e7c; width:130px;">Website</td>
                                <td style="padding:8px 0;">
                                    <a href="{{ $website->url }}" style="color:#2478cc; word-break:break-all;">{{ $website->url }}</a>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0; color:#616e7c;">Status</td>
                                <td style="padding:8px 0; color:#d64545; font-weight:bold;">Down</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 0; color:#616e7c;">Checked at</td>
                                <td style="padding:8px 0;">
                                    {{ optional($website->last_checked_at)->toDayDateTimeString() ?? now()->toDayDateTimeString() }} UTC
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0;">
                            We will keep checking every 15 minutes and this alert will not repeat until the website recovers
                            and goes down again.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 24px; background-color:#f9fafb; border-top:1px solid #e4e7eb; font-size:12px; color:#7b8794; border-radius:0 0 6px 6px;">
                        This is an automated message from {{ config('app.name') }}. Please do not reply to this email.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
