<?php

namespace App\Actions\Payments;

use App\Actions\Orders\QueueOrderEmail;
use App\Actions\Tickets\IssueTickets;
use App\Domain\Inventory\TicketStock;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Models\Tenant\Order;

class MarkOrderPaid
{
    public function __construct(
        private readonly IssueTickets $issueTickets,
        private readonly QueueOrderEmail $queueEmail,
    ) {}

    /**
     * Pending -> paid: turn every hold into a sale, move the order forward, issue the tickets and queue the e-mail.
     *
     * The caller already holds the locks in the global order (event, then this order) and runs inside a
     * transaction. Ticket types are touched in ascending id, the last step of that order.
     */
    public function execute(Order $order): void
    {
        foreach ($this->itemsByTicketType($order) as $ticketTypeId => $quantity) {
            TicketStock::commitHold($ticketTypeId, $quantity);
        }

        OrderStateMachine::transition($order, OrderStatus::Paid);
        $this->fulfil($order);
    }

    /**
     * Expired -> paid for a payment that arrived after the hold was released: sell the tickets directly, all or
     * nothing. Returns false (nothing changed) when any of them has been sold to someone else in the meantime.
     */
    public function executeLate(Order $order): bool
    {
        $sold = [];

        foreach ($this->itemsByTicketType($order) as $ticketTypeId => $quantity) {
            if (! TicketStock::sellWithoutHold($ticketTypeId, $quantity)) {
                // Undo the lines already sold; they were ours a moment ago, so this cannot fail.
                foreach ($sold as $id => $qty) {
                    TicketStock::returnSold($id, $qty);
                }

                return false;
            }
            $sold[$ticketTypeId] = $quantity;
        }

        OrderStateMachine::transition($order, OrderStatus::Paid);
        $this->fulfil($order);

        return true;
    }

    /** Everything a paid order owes the buyer: the tickets, and the e-mail that delivers them. */
    private function fulfil(Order $order): void
    {
        $this->issueTickets->execute($order);
        $this->queueEmail->execute($order, NotificationType::Confirmation);
    }

    /** @return array<int, int> ticket_type_id => quantity, ascending id (the lock order) */
    private function itemsByTicketType(Order $order): array
    {
        $items = $order->items->pluck('quantity', 'ticket_type_id')->map(fn ($q): int => (int) $q)->all();
        ksort($items);

        return $items;
    }
}
