<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Refund for {{ $order->reference }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;">
    <tr>
        <td style="padding:28px;">
            <p style="margin:0 0 4px 0;font-size:13px;color:#71717a;">{{ $tenantName }}</p>
            <h1 style="margin:0;font-size:22px;">Your order was refunded</h1>
            <p style="margin:12px 0 0 0;font-size:15px;line-height:1.5;">
                Hi {{ $order->buyer_name }}, we refunded <strong>{{ $amount }}</strong> for your order {{ $order->reference }}
                ({{ $event->title }}). Your tickets for this order no longer work.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
