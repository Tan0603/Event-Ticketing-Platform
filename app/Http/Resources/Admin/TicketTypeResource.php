<?php

namespace App\Http\Resources\Admin;

use App\Models\Tenant\TicketType;
use App\Support\TenantTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketType */
class TicketTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'description' => $this->description,
            'price_minor' => $this->price_minor,
            'currency' => tenant('currency'),
            'quantity' => $this->quantity,
            'sold' => $this->sold,
            'held' => $this->held,
            'available' => $this->available(),
            'max_per_order' => $this->max_per_order,
            'sales_start_at' => TenantTime::iso($this->sales_start_at),
            'sales_end_at' => TenantTime::iso($this->sales_end_at),
            'sort_order' => $this->sort_order,
            'sales_status' => $this->salesStatus()->value,
        ];
    }
}
