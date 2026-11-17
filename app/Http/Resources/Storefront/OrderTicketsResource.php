<?php

namespace App\Http\Resources\Storefront;

use App\Models\Tenant\Order;
use App\Models\Tenant\Ticket;
use App\Support\TenantTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin Order */
class OrderTicketsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tenantId = (string) tenant('id');
        $expires = now()->addDays((int) config('ticketing.ticket_link_days'));

        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'buyer_name' => $this->buyer_name,
            'event' => [
                'title' => $this->event->title,
                'venue_name' => $this->event->venue_name,
                'venue_address' => $this->event->venue_address,
                'starts_at' => TenantTime::iso($this->event->starts_at),
                'ends_at' => TenantTime::iso($this->event->ends_at),
            ],
            'tickets' => $this->tickets->map(fn (Ticket $ticket): array => [
                'code' => $ticket->code,
                'ticket_type' => $ticket->ticketType->name,
                'seq' => $ticket->seq,
                'status' => $ticket->status->value,
                'checked_in_at' => TenantTime::iso($ticket->checked_in_at),
                'qr_url' => URL::temporarySignedRoute('public.tickets.qr', $expires, ['tenant' => $tenantId, 'code' => $ticket->code]),
            ])->all(),
        ];
    }
}
