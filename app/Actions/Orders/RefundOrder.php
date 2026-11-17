<?php

namespace App\Actions\Orders;

use App\Domain\Inventory\TicketStock;
use App\Domain\Orders\OrderStateMachine;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Models\Tenant\Refund;
use App\Models\Tenant\Ticket;
use App\Support\StorefrontCache;
use Illuminate\Support\Facades\DB;

class RefundOrder
{
    public function __construct(private readonly QueueOrderEmail $queueEmail) {}

    /**
     * Refund a paid order in full: the tickets go back on sale, its tickets stop working, the buyer is told.
     *
     * One transaction, in the global lock order: event -> order -> ticket types (ascending id) -> tickets.
     * Everything or nothing: if any ticket was already used at the door the whole refund rolls back.
     *
     * @throws BusinessRuleException order_not_found (404), invalid_order_state, tickets_already_used (409)
     */
    public function execute(int $orderId, ?int $refundedBy, ?string $reason = null): Order
    {
        $order = DB::transaction(function () use ($orderId, $refundedBy, $reason): Order {
            $known = Order::query()->find($orderId, ['id', 'event_id']);
            if ($known === null) {
                throw new BusinessRuleException('order_not_found', 'No such order.', 404);
            }

            Event::query()->sharedLock()->findOrFail($known->event_id);
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->with('items')->findOrFail($orderId);

            if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::RefundPending], true)) {
                throw new BusinessRuleException('invalid_order_state', "Only a paid or refund-pending order can be refunded; this one is {$order->status->value}.");
            }

            // A refund_pending order never held stock or tickets (the late payment found none), so there is nothing to give back.
            $seats = 0;
            if ($order->status === OrderStatus::Paid) {
                $items = $order->items->pluck('quantity', 'ticket_type_id')->map(fn ($q): int => (int) $q)->all();
                ksort($items); // ascending ticket type id
                foreach ($items as $ticketTypeId => $quantity) {
                    TicketStock::returnSold($ticketTypeId, $quantity);
                    $seats += $quantity;
                }
            }

            // Void every ticket still valid. A scan racing with us holds the row lock: we wait, then see it as
            // used and void fewer than the order has, which aborts the refund below.
            $voided = Ticket::query()->where('order_id', $order->id)->where('status', TicketStatus::Valid)
                ->toBase()->update(['status' => TicketStatus::Void->value, 'updated_at' => now()]);

            if ($voided !== $seats) {
                throw new BusinessRuleException('tickets_already_used', 'Some of these tickets were already used at the door, so the order cannot be refunded.');
            }

            OrderStateMachine::transition($order, OrderStatus::Refunded);

            $refund = new Refund;
            $refund->forceFill([
                'order_id' => $order->id,
                'amount_minor' => $order->total_minor,
                'currency' => $order->currency,
                'reason' => $reason,
                'refunded_by' => $refundedBy,
            ])->save();

            $this->queueEmail->execute($order, NotificationType::Refund);
            StorefrontCache::flush();

            return $order;
        });

        return $order->load(['event', 'items', 'refund']);
    }
}
