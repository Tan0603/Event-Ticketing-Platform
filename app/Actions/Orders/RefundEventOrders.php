<?php

namespace App\Actions\Orders;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Jobs\RefundOrderJob;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;

class RefundEventOrders
{
    /**
     * Refund every paid order of a cancelled event. One queued job per order, so a slow or failing order
     * never blocks the rest and each refund is its own transaction.
     *
     * @return int how many refunds were queued
     */
    public function execute(Event $event, ?int $refundedBy): int
    {
        if ($event->status !== EventStatus::Cancelled) {
            throw new BusinessRuleException('invalid_event_state', 'Only a cancelled event can have all its orders refunded.');
        }

        $queued = 0;

        Order::query()->where('event_id', $event->id)->whereIn('status', [OrderStatus::Paid, OrderStatus::RefundPending])
            ->select('id')->chunkById(200, function ($orders) use ($refundedBy, &$queued): void {
                foreach ($orders as $order) {
                    RefundOrderJob::dispatch((string) tenant('id'), $order->id, $refundedBy, 'Event cancelled');
                    $queued++;
                }
            });

        return $queued;
    }
}
