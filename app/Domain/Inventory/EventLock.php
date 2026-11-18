<?php

namespace App\Domain\Inventory;

use App\Models\Tenant\Event;

/**
 * Takes the first lock of the global lock order (event -> order -> ticket types -> promo code -> tickets) for any
 * code path that changes stock.
 *
 * An event without a capacity is locked FOR SHARE: any number of checkouts, payments and expiries run in parallel,
 * and only cancelling or editing the event (FOR UPDATE) waits for them. An event WITH a capacity has one shared
 * counter (`events.reserved`) that every such path updates, so they take the row FOR UPDATE instead and queue up on
 * it: two sharers that both tried to update the row would deadlock.
 */
final class EventLock
{
    public static function acquire(int $eventId): Event
    {
        // Decided before locking; capacity changes hold the row FOR UPDATE, so re-check once we have the lock.
        $capped = Event::query()->whereKey($eventId)->whereNotNull('capacity')->exists();

        $event = $capped
            ? Event::query()->lockForUpdate()->findOrFail($eventId)
            : Event::query()->sharedLock()->findOrFail($eventId);

        if (! $capped && $event->capacity !== null) {
            // A capacity was set in the meantime: this checkout now has to update the counter, so it needs the row exclusively.
            $event = Event::query()->lockForUpdate()->findOrFail($eventId);
        }

        return $event;
    }
}
