<?php

namespace App\Http\Resources\Storefront;

use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Support\TenantTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'payment_reference' => $this->payment_reference,
            'status' => $this->status->value,
            'event' => [
                'title' => $this->event->title,
                'slug' => $this->event->slug,
                'starts_at' => TenantTime::iso($this->event->starts_at),
                'venue_name' => $this->event->venue_name,
                'venue_address' => $this->event->venue_address,
            ],
            'buyer_name' => $this->buyer_name,
            'buyer_email' => $this->buyer_email,
            'currency' => $this->currency,
            'items' => $this->items->map(fn (OrderItem $item): array => [
                'ticket_type_id' => $item->ticket_type_id,
                'name' => $item->ticket_type_name,
                'unit_price_minor' => $item->unit_price_minor,
                'quantity' => $item->quantity,
                'unit_fee_minor' => $item->unit_fee_minor,
                'line_fee_minor' => $item->line_fee_minor,
                'line_total_minor' => $item->line_total_minor,
            ])->all(),
            'subtotal_minor' => $this->subtotal_minor,
            'fee_minor' => $this->fee_minor,
            'total_minor' => $this->total_minor,
            'expires_at' => TenantTime::iso($this->expires_at),
            'paid_at' => TenantTime::iso($this->paid_at),
            'created_at' => TenantTime::iso($this->created_at),
        ];
    }
}
