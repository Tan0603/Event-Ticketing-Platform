<?php

namespace App\Actions\Events;

use App\Models\Tenant\Event;
use App\Support\TenantTime;
use Illuminate\Pagination\LengthAwarePaginator;

class ListEvents
{
    /**
     * Paginated admin list. Ticket-type totals come from SQL aggregates (one query, no N+1).
     *
     * @param  array<string, mixed>  $filters  status, q, from, to, sort, direction, per_page
     * @return LengthAwarePaginator<int, Event>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $sort = $filters['sort'] ?? 'starts_at';
        $direction = $filters['direction'] ?? 'desc';
        $perPage = (int) ($filters['per_page'] ?? config('ticketing.pagination.default_per_page'));

        return Event::query()
            ->withCount('ticketTypes')
            ->withSum('ticketTypes as capacity', 'quantity')
            ->withSum('ticketTypes as tickets_sold', 'sold')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes($term, '\\%_').'%';

                return $q->where(fn ($w) => $w->where('title', 'ilike', $like)->orWhere('venue', 'ilike', $like));
            })
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('starts_at', '>=', TenantTime::parse($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('starts_at', '<=', TenantTime::parse($to)))
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction) // stable order across pages
            ->paginate($perPage)
            ->withQueryString();
    }
}
