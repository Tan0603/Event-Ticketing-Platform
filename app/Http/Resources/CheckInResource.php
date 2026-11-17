<?php

namespace App\Http\Resources;

use App\Models\Tenant\Ticket;
use App\Support\TenantTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
class CheckInResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status->value,
            'ticket_type' => $this->ticketType->name,
            'seq' => $this->seq,
            'buyer_name' => $this->order->buyer_name,
            'order_reference' => $this->order->reference,
            'event' => ['id' => $this->event->id, 'title' => $this->event->title],
            'checked_in_at' => TenantTime::iso($this->checked_in_at),
        ];
    }
}
