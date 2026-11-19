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

        /** @var array{taken: array<int, int>, reserved: int} $counters sold + held per ticket type, and the event's shared counter */
        $counters = StorefrontCache::remember('taken:'.$static['id'], (int) config('ticketing.cache.availability_ttl'), fn (): array => [
            'taken' => TicketType::query()
                ->where('event_id', $static['id'])
                ->selectRaw('id, sold + held as taken')
                ->pluck('taken', 'id')
                ->map(fn ($n): int => (int) $n)
                ->all(),
            'reserved' => (int) Event::query()->whereKey($static['id'])->value('reserved'),
        ]);
        $taken = $counters['taken'];

        // Event-level shared capacity: what is left caps every tier (display only; checkout decides in PostgreSQL).
        $capacity = $static['capacity'] ?? null;
        $capacityRemaining = $capacity === null ? null : max(0, $capacity - $counters['reserved']);

        $now = now();
        $startsAt = CarbonImmutable::parse($static['starts_at']);
        $endsAt = CarbonImmutable::parse($static['ends_at']);

        $byId = array_column($static['ticket_types'], null, 'id');

        $ticketTypes = array_map(function (array $tier) use ($taken, $now, $endsAt, $byId, $capacityRemaining): array {
            $available = max(0, $tier['quantity'] - ($taken[$tier['id']] ?? 0));
            if ($capacityRemaining !== null) {
                $available = min($available, $capacityRemaining);
            }
            $salesStart = $tier['sales_start_at'] === null ? null : CarbonImmutable::parse($tier['sales_start_at']);
            $salesEnd = $tier['sales_end_at'] === null ? $endsAt : CarbonImmutable::parse($tier['sales_end_at']);

            // Tier progression, judged from the same cached rows and counters (no extra query): the tier is locked
            // until its predecessor's stock is gone or its sales window has ended. Display only; checkout decides.
            $predecessor = ($tier['unlocks_after_ticket_type_id'] ?? null) === null ? null : ($byId[$tier['unlocks_after_ticket_type_id']] ?? null);
            $locked = false;
            if ($predecessor !== null) {
                $predecessorEnd = $predecessor['sales_end_at'] === null ? $endsAt : CarbonImmutable::parse($predecessor['sales_end_at']);
                $stockGone = $predecessor['quantity'] - ($taken[$predecessor['id']] ?? 0) <= 0; // the predecessor's own stock, not the event's
                $locked = ! ($stockGone || $now->gte($predecessorEnd));
            }

            return [
                'id' => $tier['id'],
                'name' => $tier['name'],
                'description' => $tier['description'],
                'price_minor' => $tier['price_minor'],
                'unlocks_after' => $predecessor === null ? null : ['id' => $predecessor['id'], 'name' => $predecessor['name']],
                'available' => $available,
                'max_per_order' => $tier['max_per_order'],
                'sales_start_at' => $salesStart,
                'sales_end_at' => $salesEnd,
                'sales_status' => SalesStatus::resolve(EventStatus::Published, $salesStart, $salesEnd, $available, $now, $locked)->value,
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
            'capacity' => $capacity,
            'capacity_remaining' => $capacityRemaining,
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
            ->with(['ticketTypes' => fn ($q) => $q->without('unlocksAfter')]) // the predecessor is resolved from the sibling rows
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
            'capacity' => $event->capacity,
            'ticket_types' => $event->ticketTypes->map(fn (TicketType $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'price_minor' => $t->price_minor,
                'quantity' => $t->quantity,
                'max_per_order' => $t->max_per_order,
                'sales_start_at' => $t->sales_start_at?->toIso8601String(),
                'sales_end_at' => $t->sales_end_at?->toIso8601String(),
                'unlocks_after_ticket_type_id' => $t->unlocks_after_ticket_type_id,
            ])->all(),
        ];
    }
}
