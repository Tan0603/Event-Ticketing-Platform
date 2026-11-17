<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use App\Support\StorefrontCache;
use Illuminate\Support\Facades\DB;

class CancelEvent
{
    public function execute(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            /** @var Event $locked */
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($locked->status === EventStatus::Cancelled) {
                throw new BusinessRuleException('invalid_event_state', 'This event is already cancelled.');
            }

            // Cancelling stops all sales at once (checkout only accepts published events).
            // Refunding existing orders is a separate admin action, added with the refunds feature.
            $locked->status = EventStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->save();

            // Public shop cache: anything visitors see may have changed.
            StorefrontCache::flush();

            return $locked;
        });
    }
}
