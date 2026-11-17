<?php

namespace App\Actions\Reports;

use App\Models\Tenant\Event;
use App\Support\StorefrontCache;
use Illuminate\Support\Facades\DB;

class EventReport
{
    /**
     * Per-event sales and attendance: one row per ticket type plus totals. All figures are SQL aggregates over
     * the order snapshots (order_items), never recomputed from today's ticket prices.
     *
     * @return array<string, mixed>
     */
    public function execute(Event $event): array
    {
        return StorefrontCache::remember('event-report:'.$event->id, (int) config('ticketing.cache.dashboard_ttl'), fn (): array => $this->compute($event));
    }

    /** @return array<string, mixed> */
    private function compute(Event $event): array
    {
        $types = DB::select('SELECT id, name, price_minor, quantity, sold, held FROM ticket_types WHERE event_id = ? ORDER BY sort_order, id', [$event->id]);

        // Paid and later-refunded orders both count as sales; refunded ones are shown separately.
        $money = collect(DB::select(
            "SELECT oi.ticket_type_id,
                    COALESCE(SUM(oi.quantity), 0) AS sold_gross,
                    COALESCE(SUM(oi.line_total_minor), 0) AS gross_minor,
                    COALESCE(SUM(oi.quantity) FILTER (WHERE o.status = 'refunded'), 0) AS refunded_quantity,
                    COALESCE(SUM(oi.line_total_minor) FILTER (WHERE o.status = 'refunded'), 0) AS refunded_minor
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE o.event_id = ? AND o.paid_at IS NOT NULL
              GROUP BY oi.ticket_type_id",
            [$event->id],
        ))->keyBy('ticket_type_id');

        $attendance = collect(DB::select(
            "SELECT ticket_type_id,
                    COUNT(*) FILTER (WHERE status = 'checked_in') AS checked_in,
                    COUNT(*) FILTER (WHERE status = 'valid') AS awaiting,
                    COUNT(*) FILTER (WHERE status = 'void') AS void
               FROM tickets WHERE event_id = ? GROUP BY ticket_type_id",
            [$event->id],
        ))->keyBy('ticket_type_id');

        $orders = collect(DB::select('SELECT status, COUNT(*) AS n FROM orders WHERE event_id = ? GROUP BY status', [$event->id]))->pluck('n', 'status');
        $fees = DB::selectOne('SELECT COALESCE(SUM(fee_minor), 0) AS fees FROM orders WHERE event_id = ? AND status = ?', [$event->id, 'paid']);

        $rows = array_map(function (object $type) use ($money, $attendance): array {
            $m = $money->get($type->id);
            $a = $attendance->get($type->id);
            $gross = (int) ($m->gross_minor ?? 0);
            $refunded = (int) ($m->refunded_minor ?? 0);

            return [
                'ticket_type_id' => (int) $type->id,
                'name' => (string) $type->name,
                'price_minor' => (int) $type->price_minor,
                'capacity' => (int) $type->quantity,
                'sold' => (int) $type->sold,
                'held' => (int) $type->held,
                'available' => (int) $type->quantity - (int) $type->sold - (int) $type->held,
                'gross_minor' => $gross,
                'refunded_minor' => $refunded,
                'net_minor' => $gross - $refunded,
                'checked_in' => (int) ($a->checked_in ?? 0),
                'awaiting_check_in' => (int) ($a->awaiting ?? 0),
                'void' => (int) ($a->void ?? 0),
            ];
        }, $types);

        $sum = fn (string $field): int => array_sum(array_column($rows, $field));
        $sold = $sum('sold');

        return [
            'event' => ['id' => $event->id, 'title' => $event->title, 'status' => $event->status->value],
            'currency' => (string) tenant('currency'),
            'ticket_types' => $rows,
            'totals' => [
                'capacity' => $sum('capacity'),
                'sold' => $sold,
                'held' => $sum('held'),
                'gross_minor' => $sum('gross_minor'),
                'refunded_minor' => $sum('refunded_minor'),
                'net_minor' => $sum('net_minor'),
                'fees_minor' => (int) $fees->fees,
                'checked_in' => $sum('checked_in'),
                'awaiting_check_in' => $sum('awaiting_check_in'),
                'void' => $sum('void'),
            ],
            'orders_by_status' => ['pending' => (int) ($orders['pending'] ?? 0), 'paid' => (int) ($orders['paid'] ?? 0), 'failed' => (int) ($orders['failed'] ?? 0), 'expired' => (int) ($orders['expired'] ?? 0), 'refund_pending' => (int) ($orders['refund_pending'] ?? 0), 'refunded' => (int) ($orders['refunded'] ?? 0)],
        ];
    }
}
