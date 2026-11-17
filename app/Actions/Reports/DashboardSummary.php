<?php

namespace App\Actions\Reports;

use App\Enums\EventStatus;
use App\Enums\OrderStatus;
use App\Models\Tenant\Event;
use App\Support\StorefrontCache;
use Illuminate\Support\Facades\DB;

class DashboardSummary
{
    /**
     * Tenant-wide numbers for the admin home page. Every figure is an SQL aggregate (a handful of queries,
     * whatever the number of orders), cached briefly per tenant and dropped whenever an order is paid, refunded
     * or expired. Money is integer minor units.
     *
     * Sales rule (decision 6): an order counts as a sale on the day it was PAID, even if it is refunded later;
     * the refund is a separate figure. So net = gross - refunds.
     *
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        return StorefrontCache::remember('dashboard', (int) config('ticketing.cache.dashboard_ttl'), fn (): array => $this->compute());
    }

    /** @return array<string, mixed> */
    private function compute(): array
    {
        $orders = collect(DB::select('SELECT status, COUNT(*) AS n FROM orders GROUP BY status'))->pluck('n', 'status');

        $sales = DB::selectOne('SELECT COUNT(*) AS orders, COALESCE(SUM(total_minor), 0) AS gross, COALESCE(SUM(fee_minor), 0) AS fees FROM orders WHERE paid_at IS NOT NULL');
        $refunds = DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS total FROM refunds');
        $stock = DB::selectOne('SELECT COALESCE(SUM(sold), 0) AS sold, COALESCE(SUM(held), 0) AS held, COALESCE(SUM(quantity), 0) AS capacity FROM ticket_types');
        $tickets = DB::selectOne("SELECT COUNT(*) FILTER (WHERE status = 'used') AS used, COUNT(*) FILTER (WHERE status = 'valid') AS valid FROM tickets");

        $top = DB::select(
            "SELECT e.id, e.title,
                    COALESCE(SUM(o.total_minor) FILTER (WHERE o.status = 'paid'), 0) AS net_minor,
                    COUNT(*) FILTER (WHERE o.status = 'paid') AS orders
               FROM events e JOIN orders o ON o.event_id = e.id
              WHERE o.paid_at IS NOT NULL
              GROUP BY e.id, e.title
              ORDER BY net_minor DESC, e.id
              LIMIT 5",
        );

        return [
            'currency' => (string) tenant('currency'),
            'orders_by_status' => collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s): array => [$s->value => (int) ($orders[$s->value] ?? 0)])->all(),
            'sales' => [
                'orders_paid' => (int) $sales->orders,
                'gross_minor' => (int) $sales->gross,
                'fees_minor' => (int) $sales->fees,
                'refunds' => (int) $refunds->n,
                'refunded_minor' => (int) $refunds->total,
                'net_minor' => (int) $sales->gross - (int) $refunds->total,
            ],
            'tickets' => [
                'sold' => (int) $stock->sold,
                'held' => (int) $stock->held,
                'capacity' => (int) $stock->capacity,
                'checked_in' => (int) $tickets->used,
                'awaiting_check_in' => (int) $tickets->valid,
            ],
            'upcoming_events' => Event::query()->where('status', EventStatus::Published)->where('starts_at', '>', now())->count(),
            'top_events' => array_map(fn (object $row): array => [
                'id' => (int) $row->id, 'title' => (string) $row->title, 'orders' => (int) $row->orders, 'net_minor' => (int) $row->net_minor,
            ], $top),
        ];
    }
}
