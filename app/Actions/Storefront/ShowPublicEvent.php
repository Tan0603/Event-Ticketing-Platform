<?php

namespace App\Actions\Storefront;

use App\Enums\EventStatus;
use App\Enums\EventTiming;
use App\Enums\SalesStatus;
use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use App\Support\StorefrontCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ShowPublicEvent
{
    /**
     * One published event with its ticket types.
     *
     * Two caches with different lifetimes: the descriptive data (long TTL) and the sold + held counters (a few
     * seconds). Availability and sales status are derived from both at read time, so the status flips on time
     * even when the descriptive data is cached for minutes. This is display only; checkout never trusts it.
     *
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException when the event does not exist or is not published
     */
    public function execute(string $slug): array
    {
        /** @var array<string, mixed>|null $static */
        $static = StorefrontCache::remember('event:'.$slug, (int) config('ticketing.cache.storefront_ttl'), fn (): ?array => $this->load($slug));

        if ($static === null) {
            throw (new ModelNotFoundException)->setModel(Event::class);
        }

        /** @var array<int, int> $taken ticket type id => sold + held */
        $taken = StorefrontCache::remember('taken:'.$static['id'], (int) config('ticketing.cache.availability_ttl'), fn (): array => TicketType::query()
            ->where('event_id', $static['id'])
            ->selectRaw('id, sold + held as taken')
            ->pluck('taken', 'id')
            ->map(fn ($n): int => (int) $n)
            ->all());

        $now = now();
        $startsAt = CarbonImmutable::parse($static['starts_at']);
        $endsAt = CarbonImmutable::parse($static['ends_at']);

        $ticketTypes = array_map(function (array $tier) use ($taken, $now, $endsAt): array {
            $available = max(0, $tier['quantity'] - ($taken[$tier['id']] ?? 0));
            $salesStart = $tier['sales_start_at'] === null ? null : CarbonImmutable::parse($tier['sales_start_at']);
            $salesEnd = $tier['sales_end_at'] === null ? $endsAt : CarbonImmutable::parse($tier['sales_end_at']);

            return [
                'id' => $tier['id'],
                'name' => $tier['name'],
                'description' => $tier['description'],
                'price_minor' => $tier['price_minor'],
                'available' => $available,
                'max_per_order' => $tier['max_per_order'],
                'sales_start_at' => $salesStart,
                'sales_end_at' => $salesEnd,
                'sales_status' => SalesStatus::resolve(EventStatus::Published, $salesStart, $salesEnd, $available, $now)->value,
            ];
        }, $static['ticket_types']);

        return [
            'id' => $static['id'],
            'title' => $static['title'],
            'slug' => $static['slug'],
            'description' => $static['description'],
            'venue_name' => $static['venue_name'],
            'venue_address' => $static['venue_address'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timing' => EventTiming::resolve($now, $startsAt, $endsAt)->value,
            'ticket_types' => $ticketTypes,
        ];
    }

    /** @return array<string, mixed>|null null (never cached) for an unknown or unpublished event */
    private function load(string $slug): ?array
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', EventStatus::Published)
            ->with('ticketTypes')
            ->first();

        if ($event === null) {
            return null;
        }

        return [
            'id' => $event->id,
            'title' => $event->title,
            'slug' => $event->slug,
            'description' => $event->description,
            'venue_name' => $event->venue_name,
            'venue_address' => $event->venue_address,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'ticket_types' => $event->ticketTypes->map(fn (TicketType $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'price_minor' => $t->price_minor,
                'quantity' => $t->quantity,
                'max_per_order' => $t->max_per_order,
                'sales_start_at' => $t->sales_start_at?->toIso8601String(),
                'sales_end_at' => $t->sales_end_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
