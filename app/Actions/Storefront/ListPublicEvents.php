<?php

namespace App\Actions\Storefront;

use App\Enums\EventStatus;
use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use App\Support\StorefrontCache;
use App\Support\TenantTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ListPublicEvents
{
    /**
     * Published events that have not ended yet, soonest first. One query per page: the cheapest price and the
     * remaining tickets are SQL aggregates, so there is no per-event query.
     *
     * @param  array<string, mixed>  $filters  q, from, to, per_page, page
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? config('ticketing.pagination.default_per_page'));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $key = 'list:'.md5(json_encode([$filters['q'] ?? null, $filters['from'] ?? null, $filters['to'] ?? null, $perPage, $page]));

        /** @var array{rows: array<int, array<string, mixed>>, total: int} $data */
        $data = StorefrontCache::remember($key, (int) config('ticketing.cache.event_list_ttl'), function () use ($filters, $perPage, $page): array {
            $paginator = $this->query($filters)->paginate($perPage, ['*'], 'page', $page);

            return [
                'rows' => $paginator->getCollection()->map(fn (Event $event): array => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'slug' => $event->slug,
                    'description' => $event->description,
                    'venue_name' => $event->venue_name,
                    'venue_address' => $event->venue_address,
                    'starts_at' => $event->starts_at->toIso8601String(),
                    'ends_at' => $event->ends_at->toIso8601String(),
                    'from_price_minor' => ($price = $event->getAttribute('from_price_minor')) === null ? null : (int) $price,
                    'tickets_available' => (int) $event->getAttribute('tickets_available'),
                ])->all(),
                'total' => $paginator->total(),
            ];
        });

        return new LengthAwarePaginator($data['rows'], $data['total'], $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Event>
     */
    private function query(array $filters): Builder
    {
        return Event::query()
            ->where('status', EventStatus::Published)
            ->where('ends_at', '>', now())
            ->select('events.*')
            ->selectSub(
                TicketType::query()->selectRaw('MIN(price_minor)')->whereColumn('ticket_types.event_id', 'events.id'),
                'from_price_minor',
            )
            ->selectSub(
                TicketType::query()->selectRaw('COALESCE(SUM(GREATEST(quantity - sold - held, 0)), 0)')->whereColumn('ticket_types.event_id', 'events.id'),
                'tickets_available',
            )
            ->when($filters['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '\\%_').'%';

                return $q->where(fn ($w) => $w->where('title', 'ilike', $like)->orWhere('venue_name', 'ilike', $like));
            })
            ->when($filters['from'] ?? null, fn ($q, string $from) => $q->where('starts_at', '>=', TenantTime::parse($from)))
            ->when($filters['to'] ?? null, fn ($q, string $to) => $q->where('starts_at', '<=', TenantTime::parse($to)))
            ->orderBy('starts_at')
            ->orderBy('id');
    }
}
