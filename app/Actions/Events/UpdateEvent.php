<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use App\Support\StorefrontCache;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateEvent
{
    /**
     * @param  array<string, mixed>  $attributes  only the keys the client sent (title, slug, description, venue_name, venue_address, starts_at, ends_at, capacity)
     */
    public function execute(Event $event, array $attributes): Event
    {
        return DB::transaction(function () use ($event, $attributes): Event {
            // Lock order: event first. Serialises edits with publish/cancel/ticket-type changes on this event.
            /** @var Event $locked */
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($locked->status === EventStatus::Cancelled) {
                throw new BusinessRuleException('event_cancelled', 'A cancelled event cannot be edited.');
            }

            if (isset($attributes['slug']) && $attributes['slug'] !== $locked->slug && $locked->status === EventStatus::Published) {
                throw new BusinessRuleException('slug_locked', 'The slug of a published event cannot change: its public link would break.');
            }

            $capacityChanged = array_key_exists('capacity', $attributes);
            $locked->fill($attributes);
            if ($capacityChanged) {
                $this->applyCapacity($locked);
            }

            if ($locked->ends_at->lte($locked->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => ['The end must be after the start.']]);
            }

            // Moving the end earlier must not strand a tier that sells past it.
            if ($locked->isDirty('ends_at') && $locked->ticketTypes()->where('sales_end_at', '>', $locked->ends_at)->exists()) {
                throw ValidationException::withMessages(['ends_at' => ['A ticket type sells past this end time. Shorten its sales window first.']]);
            }

            try {
                $locked->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['slug' => ['This slug is already used by another event.']]);
            }

            // Public shop cache: anything visitors see may have changed.
            StorefrontCache::flush();

            return $locked;
        });
    }

    /**
     * Set or clear the shared capacity. The event row is locked FOR UPDATE, so no checkout, payment or expiry of this
     * event is running: `reserved` is recomputed from the ticket types in one SQL aggregate and the new capacity may
     * not be below it. Clearing the capacity zeroes the counter (it is only maintained while a capacity is set).
     */
    private function applyCapacity(Event $event): void
    {
        if ($event->capacity === null) {
            $event->forceFill(['reserved' => 0]);

            return;
        }

        $reserved = (int) DB::table('ticket_types')->where('event_id', $event->id)->selectRaw('COALESCE(SUM(sold + held), 0) AS reserved')->value('reserved');

        if ($event->capacity < $reserved) {
            throw ValidationException::withMessages(['capacity' => ["The capacity cannot be lower than the {$reserved} tickets already sold or held."]]);
        }

        $event->forceFill(['reserved' => $reserved]);
    }
}
