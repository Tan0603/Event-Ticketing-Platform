<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use Illuminate\Support\Facades\DB;

class PublishEvent
{
    public function execute(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            /** @var Event $locked */
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($locked->status !== EventStatus::Draft) {
                throw new BusinessRuleException('invalid_event_state', "Only a draft event can be published (this one is {$locked->status->value}).");
            }

            if ($locked->ends_at->isPast()) {
                throw new BusinessRuleException('event_already_ended', 'An event that has already ended cannot be published.');
            }

            if (! $locked->ticketTypes()->exists()) {
                throw new BusinessRuleException('no_ticket_types', 'Add at least one ticket type before publishing.');
            }

            $locked->status = EventStatus::Published;
            $locked->published_at = now();
            $locked->save();

            return $locked;
        });
    }
}
