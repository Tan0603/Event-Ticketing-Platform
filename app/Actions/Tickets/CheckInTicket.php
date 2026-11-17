<?php

namespace App\Actions\Tickets;

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use App\Models\Tenant\Ticket;
use App\Models\Tenant\User;
use App\Support\TenantTime;
use Illuminate\Support\Facades\DB;

class CheckInTicket
{
    /**
     * Admit one ticket of one event, exactly once.
     *
     * The whole decision is ONE statement: `UPDATE ... WHERE code = ? AND event_id = ? AND status = 'valid' ... RETURNING`.
     * Two gates scanning the same code queue on the row lock; the second re-evaluates `status = 'valid'` after the
     * first committed, matches nothing and gets no row back. There is no read-then-write in PHP, so there is no window
     * in which both can succeed.
     *
     * Only when nothing was updated do we look the ticket up, purely to tell the scanner why.
     *
     * @throws BusinessRuleException ticket_not_found (404, also for a ticket of another event), already_checked_in, ticket_void, event_cancelled (409)
     */
    public function execute(User $staff, Event $event, string $code, ?string $gateName = null): Ticket
    {
        $code = strtoupper(trim($code));
        $now = now()->toIso8601String(); // the application clock, so time is the same everywhere (and fakeable in tests)

        $rows = DB::select(
            'UPDATE tickets SET status = ?, checked_in_at = ?::timestamptz, checked_in_by = ?, gate_name = ?, updated_at = ?::timestamptz
              WHERE code = ? AND event_id = ? AND status = ?
                AND event_id IN (SELECT id FROM events WHERE status = ?)
          RETURNING id',
            [TicketStatus::CheckedIn->value, $now, $staff->id, $gateName, $now, $code, $event->id, TicketStatus::Valid->value, EventStatus::Published->value],
        );

        if ($rows === []) {
            $this->explain($code, $event);
        }

        return Ticket::query()->with(['ticketType:id,name', 'order:id,buyer_name,reference', 'event:id,title', 'checkedInBy:id,name'])->findOrFail($rows[0]->id);
    }

    /** @return never */
    private function explain(string $code, Event $event): void
    {
        // Scoped to this event: a ticket of another event is "not found" here, exactly like an unknown code.
        $ticket = Ticket::query()->with('checkedInBy:id,name')->where('code', $code)->where('event_id', $event->id)->first();

        if ($ticket === null) {
            throw new BusinessRuleException('ticket_not_found', 'No ticket with this code for this event.', 404);
        }

        if ($ticket->status === TicketStatus::CheckedIn) {
            $by = $ticket->checkedInBy?->name;

            throw new BusinessRuleException(
                'already_checked_in',
                'This ticket was already checked in at '.TenantTime::iso($ticket->checked_in_at).($by === null ? '.' : " by {$by}."),
                409,
                [
                    'checked_in_at' => TenantTime::iso($ticket->checked_in_at),
                    'checked_in_by' => $ticket->checkedInBy === null ? null : ['id' => $ticket->checkedInBy->id, 'name' => $ticket->checkedInBy->name],
                    'gate_name' => $ticket->gate_name,
                ],
            );
        }

        if ($ticket->status === TicketStatus::Void) {
            throw new BusinessRuleException('ticket_void', 'This ticket is no longer valid (the order was refunded).');
        }

        throw new BusinessRuleException('event_cancelled', 'This event is not open for check-in.');
    }
}
