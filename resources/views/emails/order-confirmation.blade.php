<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your tickets for {{ $event->title }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;">
    <tr>
        <td style="padding:28px 28px 8px 28px;">
            <p style="margin:0 0 4px 0;font-size:13px;color:#71717a;">{{ $tenantName }}</p>
            <h1 style="margin:0;font-size:22px;">You're going to {{ $event->title }}</h1>
            <p style="margin:12px 0 0 0;font-size:15px;line-height:1.5;">
                Hi {{ $order->buyer_name }}, your payment was received. Show the QR code below at the entrance.
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:16px 28px;">
            <p style="margin:0;font-size:14px;"><strong>When:</strong> {{ $startsAt }}</p>
            <p style="margin:4px 0 0 0;font-size:14px;"><strong>Where:</strong> {{ $event->venue_name }}@if ($event->venue_address), {{ $event->venue_address }}@endif</p>
            <p style="margin:4px 0 0 0;font-size:14px;"><strong>Order:</strong> {{ $order->reference }}</p>
        </td>
    </tr>
    <tr>
        <td style="padding:0 28px 8px 28px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="6" style="font-size:14px;border-collapse:collapse;">
                @foreach ($order->items as $item)
                    <tr style="border-bottom:1px solid #e4e4e7;">
                        <td>{{ $item->quantity }} &times; {{ $item->ticket_type_name }}</td>
                        <td align="right">{{ $money($item->line_total_minor) }}</td>
                    </tr>
                @endforeach
                <tr><td>Fees</td><td align="right">{{ $money($order->fee_minor) }}</td></tr>
                <tr><td><strong>Total paid</strong></td><td align="right"><strong>{{ $money($order->total_minor) }}</strong></td></tr>
            </table>
        </td>
    </tr>
    @foreach ($tickets as $ticket)
        <tr>
            <td style="padding:16px 28px;border-top:1px solid #e4e4e7;" align="center">
                <p style="margin:0 0 8px 0;font-size:14px;"><strong>{{ $ticket->ticketType->name }}</strong> &middot; ticket {{ $ticket->seq }}</p>
                <img src="{{ $message->embedData(\App\Support\TicketQr::png($ticket->code), 'ticket-'.$ticket->code.'.png', 'image/png') }}" width="200" height="200" alt="QR code {{ $ticket->code }}">
                <p style="margin:8px 0 0 0;font-size:13px;color:#52525b;letter-spacing:1px;">{{ $ticket->code }}</p>
            </td>
        </tr>
    @endforeach
    <tr>
        <td style="padding:16px 28px 28px 28px;font-size:13px;color:#52525b;">
            Can't see the images? <a href="{{ $ticketsUrl }}">Open your tickets online</a>.
        </td>
    </tr>
</table>
</body>
</html>
