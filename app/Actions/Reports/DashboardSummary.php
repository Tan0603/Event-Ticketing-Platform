<?php

namespace App\Actions\Reports;

use App\Enums\OrderStatus;
use App\Support\StorefrontCache;
use App\Support\TenantTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardSummary
{
    /**
     * Tenant-wide numbers for the admin home page. Every figure is an SQL aggregate (a fixed handful of queries,
     * whatever the number of orders), cached briefly per tenant and dropped whenever an order is paid, refunded
     * or expired. Money is integer minor units. "Today" and "this month" use the tenant's timezone.
     *
     * Sales rule (decision 6): an order counts as a sale on the day it was PAID, even if it is refunded later;
     * the refund is a separate figure on the day it was made. So net = gross - refunds.
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
        $tz = TenantTime::timezone();
        $now = CarbonImmutable::now($tz);
        $dayStart = $now->startOfDay();
        $dayEnd = $dayStart->addDay();
        $monthStart = $now->startOfMonth();
        $monthEnd = $monthStart->addMonth();
        $utc = fn (CarbonImmutable $t): string => $t->utc()->toIso8601String();
        $nowIso = $now->utc()->toIso8601String();

        $orders = collect(DB::select('SELECT status, COUNT(*) AS n FROM orders GROUP BY status'))->pluck('n', 'status');

        return [
            'currency' => (string) tenant('currency'),
            'timezone' => $tz,
            'orders_by_status' => collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $s): array => [$s->value => (int) ($orders[$s->value] ?? 0)])->all(),
            'sales' => [
                'today' => $this->salesBetween($utc($dayStart), $utc($dayEnd)),
                'this_month' => $this->salesBetween($utc($monthStart), $utc($monthEnd)),
                'all_time' => $this->allTimeSales(),
            ],
            'upcoming_events' => $this->upcomingEvents($nowIso),
            'active_holds' => $this->activeHolds($nowIso),
            'check_in_today' => $this->checkInToday($utc($dayStart), $utc($dayEnd)),
            'tickets' => $this->ticketTotals(),
            'top_events' => $this->topEvents(),
        ];
    }

    /**
     * Gross sales (order totals, fees included) of the orders paid in [from, to), and the refunds made in the same window.
     *
     * @return array<string, int>
     */
    private function salesBetween(string $from, string $to): array
    {
        $paid = DB::selectOne(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(total_minor), 0) AS gross, COALESCE(SUM(fee_minor), 0) AS fees FROM orders WHERE paid_at >= ? AND paid_at < ?',
            [$from, $to],
        );
        $refunds = DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS total FROM refunds WHERE created_at >= ? AND created_at < ?', [$from, $to]);

        return [
            'orders_paid' => (int) $paid->orders,
            'gross_minor' => (int) $paid->gross,
            'fees_minor' => (int) $paid->fees,
            'refunds' => (int) $refunds->n,
            'refunded_minor' => (int) $refunds->total,
            'net_minor' => (int) $paid->gross - (int) $refunds->total,
        ];
    }

    /** @return array<string, int> */
    private function allTimeSales(): array
    {
        $sales = DB::selectOne('SELECT COUNT(*) AS orders, COALESCE(SUM(total_minor), 0) AS gross, COALESCE(SUM(fee_minor), 0) AS fees FROM orders WHERE paid_at IS NOT NULL');
        $refunds = DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS total FROM refunds');

        return [
            'orders_paid' => (int) $sales->orders,
            'gross_minor' => (int) $sales->gross,
            'fees_minor' => (int) $sales->fees,
            'refunds' => (int) $refunds->n,
            'refunded_minor' => (int) $refunds->total,
            'net_minor' => (int) $sales->gross - (int) $refunds->total,
        ];
    }

    /**
     * Tickets sold against capacity for the published events that have not started, soonest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function upcomingEvents(string $nowIso): array
    {
        $rows = DB::select(
            "SELECT e.id, e.title, e.starts_at,
                    COALESCE(SUM(tt.sold), 0) AS sold, COALESCE(SUM(tt.held), 0) AS held, COALESCE(SUM(tt.quantity), 0) AS capacity
               FROM events e LEFT JOIN ticket_types tt ON tt.event_id = e.id
              WHERE e.status = 'published' AND e.starts_at > ?
              GROUP BY e.id, e.title, e.starts_at
              ORDER BY e.starts_at, e.id
              LIMIT ?",
            [$nowIso, (int) config('ticketing.reports.dashboard_upcoming_events')],
        );

        return array_map(fn (object $r): array => [
            'id' => (int) $r->id,
            'title' => (string) $r->title,
            'starts_at' => TenantTime::iso(CarbonImmutable::parse($r->starts_at)),
            'sold' => (int) $r->sold,
            'held' => (int) $r->held,
            'capacity' => (int) $r->capacity,
        ], $rows);
    }

    /**
     * Pending orders whose hold has not expired yet, and the tickets they keep out of sale.
     *
     * @return array<string, int>
     */
    private function activeHolds(string $nowIso): array
    {
        $row = DB::selectOne(
            "SELECT COUNT(DISTINCT o.id) AS orders, COALESCE(SUM(oi.quantity), 0) AS tickets
               FROM orders o JOIN order_items oi ON oi.order_id = o.id
              WHERE o.status = 'pending' AND o.expires_at > ?",
            [$nowIso],
        );

        return ['orders' => (int) $row->orders, 'tickets' => (int) $row->tickets];
    }

    /**
     * How many of the tickets of today's events (published events running at any time today) are already in.
     * The rate is integer basis points (no floats), null when there are no tickets yet.
     *
     * @return array<string, mixed>
     */
    private function checkInToday(string $from, string $to): array
    {
        $rows = DB::select(
            "SELECT e.id, e.title,
                    COUNT(t.id) FILTER (WHERE t.status <> 'void') AS total,
                    COUNT(t.id) FILTER (WHERE t.status = 'checked_in') AS checked_in
               FROM events e LEFT JOIN tickets t ON t.event_id = e.id
              WHERE e.status = 'published' AND e.starts_at < ? AND e.ends_at >= ?
              GROUP BY e.id, e.title, e.starts_at
              ORDER BY e.starts_at, e.id",
            [$to, $from],
        );

        $rate = fn (int $in, int $total): array => ['rate_bps' => $total === 0 ? null : intdiv($in * 10000, $total)];
        $events = array_map(fn (object $r): array => [
            'id' => (int) $r->id, 'title' => (string) $r->title, 'total' => (int) $r->total, 'checked_in' => (int) $r->checked_in,
        ] + $rate((int) $r->checked_in, (int) $r->total), $rows);

        $total = array_sum(array_column($events, 'total'));
        $in = array_sum(array_column($events, 'checked_in'));

        return ['events' => $events, 'total' => $total, 'checked_in' => $in] + $rate($in, $total);
    }

    /** @return array<string, int> */
    private function ticketTotals(): array
    {
        $stock = DB::selectOne('SELECT COALESCE(SUM(sold), 0) AS sold, COALESCE(SUM(held), 0) AS held, COALESCE(SUM(quantity), 0) AS capacity FROM ticket_types');
        $tickets = DB::selectOne("SELECT COUNT(*) FILTER (WHERE status = 'checked_in') AS checked_in, COUNT(*) FILTER (WHERE status = 'valid') AS valid FROM tickets");

        return [
            'sold' => (int) $stock->sold,
            'held' => (int) $stock->held,
            'capacity' => (int) $stock->capacity,
            'checked_in' => (int) $tickets->checked_in,
            'awaiting_check_in' => (int) $tickets->valid,
        ];
    }

    /** @return array<int, array<string, int|string>> */
    private function topEvents(): array
    {
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

        return array_map(fn (object $row): array => [
            'id' => (int) $row->id, 'title' => (string) $row->title, 'orders' => (int) $row->orders, 'net_minor' => (int) $row->net_minor,
        ], $top);
    }
}
