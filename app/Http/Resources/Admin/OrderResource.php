<?php

namespace App\Http\Resources\Admin;

use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\Ticket;
use App\Support\TenantTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List rows carry the summary; the detail view (items, tickets, refund) is included only when loaded.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'payment_reference' => $this->payment_reference,
            'status' => $this->status->value,
            'event' => ['id' => $this->event->id, 'title' => $this->event->title],
            'buyer_name' => $this->buyer_name,
            'buyer_email' => $this->buyer_email,
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'discount_minor' => $this->discount_minor,
            'promo_code' => $this->promo_code,
            'fee_minor' => $this->fee_minor,
            'total_minor' => $this->total_minor,
            'created_at' => TenantTime::iso($this->created_at),
            'paid_at' => TenantTime::iso($this->paid_at),
            'refunded_at' => TenantTime::iso($this->refunded_at),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (OrderItem $item): array => [
                'name' => $item->ticket_type_name,
                'unit_price_minor' => $item->unit_price_minor,
                'quantity' => $item->quantity,
                'unit_fee_minor' => $item->unit_fee_minor,
                'line_fee_minor' => $item->line_fee_minor,
                'line_total_minor' => $item->line_total_minor,
            ])->all()),
            'tickets' => $this->whenLoaded('tickets', fn () => $this->tickets->map(fn (Ticket $ticket): array => [
                'code' => $ticket->code,
                'seq' => $ticket->seq,
                'status' => $ticket->status->value,
                'checked_in_at' => TenantTime::iso($ticket->checked_in_at),
            ])->all()),
            'refund' => $this->whenLoaded('refund', fn () => $this->refund === null ? null : [
                'amount_minor' => $this->refund->amount_minor,
                'currency' => $this->refund->currency,
                'reason' => $this->refund->reason,
                'refunded_by' => $this->refund->refunded_by,
                'created_at' => TenantTime::iso($this->refund->created_at),
            ]),
        ];
    }
}
