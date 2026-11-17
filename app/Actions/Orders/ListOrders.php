<?php

namespace App\Actions\Orders;

use App\Models\Tenant\Order;
use Illuminate\Pagination\LengthAwarePaginator;

class ListOrders
{
    /**
     * Paginated admin list of orders, newest first.
     *
     * @param  array<string, mixed>  $filters  event_id, status, email, reference, per_page
     * @return LengthAwarePaginator<int, Order>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        return Order::query()
            ->with('event:id,title,slug')
            ->when($filters['event_id'] ?? null, fn ($q, $id) => $q->where('event_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['reference'] ?? null, fn ($q, $reference) => $q->where('reference', strtoupper((string) $reference)))
            ->when($filters['email'] ?? null, fn ($q, $email) => $q->where('buyer_email', strtolower((string) $email)))
            ->orderByDesc('created_at')->orderByDesc('id') // stable across pages
            ->paginate((int) ($filters['per_page'] ?? config('ticketing.pagination.default_per_page')))
            ->withQueryString();
    }
}
