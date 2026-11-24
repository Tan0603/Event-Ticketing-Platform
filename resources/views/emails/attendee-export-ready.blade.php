<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendee export for {{ $eventTitle }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;">
    <tr>
        <td style="padding:28px;">
            <p style="margin:0 0 4px 0;font-size:13px;color:#71717a;">{{ $tenantName }}</p>
            <h1 style="margin:0;font-size:22px;">Your attendee export is ready</h1>
            <p style="margin:12px 0 0 0;font-size:15px;line-height:1.5;">
                Hi {{ $adminName }}, the CSV of attendees for <strong>{{ $eventTitle }}</strong> ({{ $rowCount }} tickets) is ready.
            </p>
            <p style="margin:20px 0;">
                <a href="{{ $downloadUrl }}" style="background:#18181b;color:#ffffff;padding:12px 20px;border-radius:6px;text-decoration:none;font-size:15px;">Download the CSV</a>
            </p>
            <p style="margin:0;font-size:13px;color:#71717a;line-height:1.5;">
                The link works for {{ $validMinutes }} minutes and contains personal data: do not forward it.
                You can request a new export at any time.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
