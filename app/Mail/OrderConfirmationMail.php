<?php

namespace App\Mail;

use App\Domain\Money\MoneyFormatter;
use App\Models\Tenant\Order;
use App\Models\Tenant\Ticket;
use App\Support\TenantTime;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

/** "Your tickets": order summary, one QR code per ticket and a link to the online ticket page. */
class OrderConfirmationMail extends Mailable
{
    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your tickets for {$this->order->event->title} ({$this->order->reference})");
    }

    public function content(): Content
    {
        $order = $this->order;
        $tenantId = (string) tenant('id');

        return new Content(
            view: 'emails.order-confirmation',
            with: [
                'order' => $order,
                'event' => $order->event,
                'tenantName' => (string) tenant('name'),
                'startsAt' => TenantTime::iso($order->event->starts_at),
                'money' => fn (int $minor): string => MoneyFormatter::format($minor, $order->currency),
                'tickets' => Ticket::query()->with('ticketType:id,name')->where('order_id', $order->id)->orderBy('id')->get(),
                'ticketsUrl' => URL::temporarySignedRoute(
                    'public.tickets.show',
                    now()->addDays((int) config('ticketing.ticket_link_days')),
                    ['tenant' => $tenantId, 'reference' => $order->reference],
                ),
            ],
        );
    }
}
