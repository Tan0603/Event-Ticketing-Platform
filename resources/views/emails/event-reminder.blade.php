<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reminder: {{ $event->title }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;">
    <tr>
        <td style="padding:28px;">
            <p style="margin:0 0 4px 0;font-size:13px;color:#71717a;">{{ $tenantName }}</p>
            <h1 style="margin:0;font-size:22px;">{{ $event->title }} is coming up</h1>
            <p style="margin:12px 0 0 0;font-size:15px;line-height:1.5;">Hi {{ $order->buyer_name }}, a reminder that your event starts soon.</p>
            <p style="margin:16px 0 0 0;font-size:14px;"><strong>When:</strong> {{ $startsAt }}</p>
            <p style="margin:4px 0 0 0;font-size:14px;"><strong>Where:</strong> {{ $event->venue_name }}@if ($event->venue_address), {{ $event->venue_address }}@endif</p>
            <p style="margin:4px 0 0 0;font-size:14px;"><strong>Order:</strong> {{ $order->reference }}</p>
            <p style="margin:20px 0 0 0;font-size:14px;"><a href="{{ $ticketsUrl }}">Open your tickets</a> and show the QR code at the entrance.</p>
        </td>
    </tr>
</table>
</body>
</html>
