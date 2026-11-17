<?php

namespace App\Actions\Orders;

use App\Domain\Inventory\TicketStock;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Models\Tenant\Order;

class ReleaseOrder
{
    /**
     * Pending -> failed or expired: give the held tickets back and move the order forward.
     * The caller holds the event and order locks and is inside a transaction.
     */
    public function execute(Order $order, OrderStatus $to): void
    {
        $items = $order->items->pluck('quantity', 'ticket_type_id')->map(fn ($q): int => (int) $q)->all();
        ksort($items); // ascending ticket type id

        foreach ($items as $ticketTypeId => $quantity) {
            TicketStock::release($ticketTypeId, $quantity);
        }

        OrderStateMachine::transition($order, $to);
    }
}
