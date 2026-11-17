<?php

namespace App\Actions\Orders;

use App\Enums\EventStatus;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;

class QueueEventReminders
{
    public function __construct(private readonly QueueOrderEmail $queueEmail) {}

    /**
     * Queue a reminder for every paid order of a published event that starts within the reminder window.
     * Runs inside one tenant. Every order is claimed through order_notifications (UNIQUE order_id, type), so the
     * task can run every few minutes and each buyer still gets exactly one reminder.
     *
     * @return int reminders queued by this run
     */
    public function execute(): int
    {
        $queued = 0;

        $events = Event::query()
            ->where('status', EventStatus::Published)
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours((int) config('ticketing.reminder_hours_before')))
            ->pluck('id');

        foreach ($events as $eventId) {
            Order::query()
                ->where('event_id', $eventId)->where('status', OrderStatus::Paid)
                ->chunkById(200, function ($orders) use (&$queued): void {
                    foreach ($orders as $order) {
                        $queued += $this->queueEmail->execute($order, NotificationType::Reminder) ? 1 : 0;
                    }
                });
        }

        return $queued;
    }
}
