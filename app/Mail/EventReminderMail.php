<?php

namespace App\Mail;

use App\Models\Tenant\Order;
use App\Support\TenantTime;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

/** "Your event is coming up": when and where, and the link to the tickets. */
class EventReminderMail extends Mailable
{
    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Reminder: {$this->order->event->title} is coming up");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.event-reminder',
            with: [
                'order' => $this->order,
                'event' => $this->order->event,
                'tenantName' => (string) tenant('name'),
                'startsAt' => TenantTime::iso($this->order->event->starts_at),
                'ticketsUrl' => URL::temporarySignedRoute(
                    'public.tickets.show',
                    now()->addDays((int) config('ticketing.ticket_link_days')),
                    ['tenant' => (string) tenant('id'), 'reference' => $this->order->reference],
                ),
            ],
        );
    }
}
