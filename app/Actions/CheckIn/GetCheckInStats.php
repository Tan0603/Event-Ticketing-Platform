<?php

namespace App\Actions\CheckIn;

use App\Models\Tenant\Event;
use Illuminate\Support\Facades\DB;

class GetCheckInStats
{
    /**
     * Door numbers for one event: how many tickets, how many are in, how many are still to come (valid), per ticket type.
     * Void tickets (refunded) are not admissions any more, so they are counted apart and left out of the total.
     * Live (not cached): the door staff are looking at this while the queue moves.
     *
     * @return array<string, mixed>
     */
    public function execute(Event $event): array
    {
        $tiers = DB::select(
            "SELECT tt.id, tt.name,
                    COUNT(t.id) FILTER (WHERE t.status <> 'void') AS total,
                    COUNT(t.id) FILTER (WHERE t.status = 'checked_in') AS checked_in,
                    COUNT(t.id) FILTER (WHERE t.status = 'valid') AS remaining,
                    COUNT(t.id) FILTER (WHERE t.status = 'void') AS void
               FROM ticket_types tt
               LEFT JOIN tickets t ON t.ticket_type_id = tt.id
              WHERE tt.event_id = ?
              GROUP BY tt.id, tt.name, tt.sort_order
              ORDER BY tt.sort_order, tt.id",
            [$event->id],
        );

        $totals = DB::selectOne(
            "SELECT COUNT(*) FILTER (WHERE status <> 'void') AS total,
                    COUNT(*) FILTER (WHERE status = 'checked_in') AS checked_in,
                    COUNT(*) FILTER (WHERE status = 'valid') AS remaining,
                    COUNT(*) FILTER (WHERE status = 'void') AS void
               FROM tickets WHERE event_id = ?",
            [$event->id],
        );

        return [
            'event' => ['id' => $event->id, 'title' => $event->title],
            'total' => (int) $totals->total,
            'checked_in' => (int) $totals->checked_in,
            'remaining' => (int) $totals->remaining,
            'void' => (int) $totals->void,
            'by_ticket_type' => array_map(fn (object $t): array => [
                'ticket_type_id' => (int) $t->id,
                'name' => $t->name,
                'total' => (int) $t->total,
                'checked_in' => (int) $t->checked_in,
                'remaining' => (int) $t->remaining,
                'void' => (int) $t->void,
            ], $tiers),
        ];
    }
}
