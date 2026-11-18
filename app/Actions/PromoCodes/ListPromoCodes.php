<?php

namespace App\Actions\PromoCodes;

use App\Models\Tenant\PromoCode;
use Illuminate\Pagination\LengthAwarePaginator;

class ListPromoCodes
{
    /**
     * @param  array<string, mixed>  $filters  event_id, is_active, q (code prefix), per_page
     * @return LengthAwarePaginator<int, PromoCode>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        return PromoCode::query()
            ->when($filters['event_id'] ?? null, fn ($q, $eventId) => $q->where('event_id', $eventId))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when($filters['q'] ?? null, fn ($q, string $term) => $q->where('code', 'like', addcslashes(PromoCode::normalize($term), '\\%_').'%'))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? config('ticketing.pagination.default_per_page')))
            ->withQueryString();
    }
}
