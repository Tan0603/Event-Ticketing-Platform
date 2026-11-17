<?php

namespace App\Mail;

use App\Domain\Money\MoneyFormatter;
use App\Models\Tenant\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Your order was refunded": the amount and which event it was for. */
class OrderRefundedMail extends Mailable
{
    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your order {$this->order->reference} was refunded");
    }

    public function content(): Content
    {
        $order = $this->order;

        return new Content(
            view: 'emails.order-refunded',
            with: [
                'order' => $order,
                'event' => $order->event,
                'tenantName' => (string) tenant('name'),
                'amount' => MoneyFormatter::format($order->refund->amount_minor, $order->refund->currency),
            ],
        );
    }
}
