<?php

namespace App\Actions\Tickets;

use App\Enums\TicketStatus;
use App\Models\Tenant\Order;
use App\Models\Tenant\Ticket;
use Illuminate\Support\Str;

class IssueTickets
{
    /**
     * One ticket per purchased seat, numbered 1..quantity inside its order line. Safe to run twice: the UNIQUE
     * (order_item_id, seq) index makes the second run a no-op instead of a second set of tickets.
     */
    public function execute(Order $order): void
    {
        $now = now();
        $rows = [];

        foreach ($order->items as $item) {
            for ($seq = 1; $seq <= $item->quantity; $seq++) {
                $rows[] = [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'ticket_type_id' => $item->ticket_type_id,
                    'event_id' => $order->event_id,
                    'seq' => $seq,
                    'code' => Str::upper(Str::random(20)), // 36^20 possibilities: not guessable
                    'status' => TicketStatus::Valid->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Ticket::query()->insertOrIgnore($chunk);
        }
    }
}
