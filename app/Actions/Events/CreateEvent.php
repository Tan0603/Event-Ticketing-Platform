<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Models\Tenant\Event;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateEvent
{
    public function execute(EventData $data): Event
    {
        $event = new Event([
            'title' => $data->title,
            'slug' => $data->slug ?? $this->uniqueSlug($data->title),
            'description' => $data->description,
            'venue_name' => $data->venueName,
            'venue_address' => $data->venueAddress,
            'starts_at' => $data->startsAt,
            'ends_at' => $data->endsAt,
            'capacity' => $data->capacity,
        ]);

        $event->status = EventStatus::Draft;

        try {
            $event->save();
        } catch (UniqueConstraintViolationException) {
            // The UNIQUE index is the real guard; this also covers two requests racing for one slug.
            throw ValidationException::withMessages(['slug' => ['This slug is already used by another event.']]);
        }

        return $event;
    }

    /** Best-effort friendly slug; the UNIQUE index still has the last word. */
    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'event', 100, '');
        $slug = $base;

        for ($i = 2; Event::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
