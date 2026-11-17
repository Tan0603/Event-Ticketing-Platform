<?php

namespace App\Http\Resources\Admin;

use App\Models\Tenant\Event;
use App\Support\TenantTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Event */
class EventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'venue_name' => $this->venue_name,
            'venue_address' => $this->venue_address,
            'starts_at' => TenantTime::iso($this->starts_at),
            'ends_at' => TenantTime::iso($this->ends_at),
            'status' => $this->status->value,
            'timing' => $this->timing()->value,
            'published_at' => TenantTime::iso($this->published_at),
            'cancelled_at' => TenantTime::iso($this->cancelled_at),
            // Present on the list (SQL aggregates).
            'ticket_types_count' => $this->whenCounted('ticketTypes'),
            'capacity' => $this->when(isset($this->resource->capacity), fn () => (int) $this->resource->capacity),
            'tickets_sold' => $this->when(isset($this->resource->capacity), fn () => (int) $this->resource->tickets_sold),
            'ticket_types' => TicketTypeResource::collection($this->whenLoaded('ticketTypes')),
        ];
    }
}
