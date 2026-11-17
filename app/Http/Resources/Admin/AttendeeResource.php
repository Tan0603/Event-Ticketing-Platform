<?php

namespace App\Http\Resources\Admin;

use App\Models\Tenant\Ticket;
use App\Support\TenantTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
class AttendeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ticket_code' => $this->code,
            'status' => $this->status->value,
            'ticket_type' => ['id' => $this->ticketType->id, 'name' => $this->ticketType->name],
            'buyer_name' => $this->order->buyer_name,
            'buyer_email' => $this->order->buyer_email,
            'order_reference' => $this->order->reference,
            'checked_in_at' => TenantTime::iso($this->checked_in_at),
            'checked_in_by' => $this->checkedInBy?->name,
            'gate_name' => $this->gate_name,
        ];
    }
}
