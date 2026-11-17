<?php

namespace App\Actions\Tickets;

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Ticket;
use App\Models\Tenant\User;
use App\Support\TenantTime;
use Illuminate\Support\Facades\DB;

class CheckInTicket
{
    /**
     * Admit one ticket, exactly once.
     *
     * The whole decision is ONE statement: `UPDATE ... WHERE code = ? AND status = 'valid' ... RETURNING`. Two
     * scanners racing on the same code queue on the row lock; the second one re-evaluates `status = 'valid'`
     * after the first committed, matches nothing and gets no row back. There is no read-then-write in PHP, so
     * there is no window in which both can succeed.
     *
     * Only when nothing was updated do we look the ticket up, purely to tell the scanner why.
     *
     * @throws BusinessRuleException ticket_not_found (404), already_checked_in, ticket_void, wrong_event, event_cancelled (409)
     */
    public function execute(User $staff, string $code, ?int $eventId = null): Ticket
    {
        $code = strtoupper(trim($code));

        $sql = 'UPDATE tickets SET status = ?, checked_in_at = ?::timestamptz, checked_in_by = ?, updated_at = ?::timestamptz
                WHERE code = ? AND status = ?
                  AND event_id IN (SELECT id FROM events WHERE status = ?)';
        $now = now()->toIso8601String(); // the application clock, so time is the same everywhere (and fakeable in tests)
        $bindings = [TicketStatus::Used->value, $now, $staff->id, $now, $code, TicketStatus::Valid->value, EventStatus::Published->value];

        if ($eventId !== null) {
            $sql .= ' AND event_id = ?';
            $bindings[] = $eventId;
        }

        $rows = DB::select($sql.' RETURNING id', $bindings);

        if ($rows === []) {
            $this->explain($code, $eventId);
        }

        return Ticket::query()->with(['ticketType:id,name', 'order:id,buyer_name,reference', 'event:id,title'])->findOrFail($rows[0]->id);
    }

    /** @return never */
    private function explain(string $code, ?int $eventId): void
    {
        $ticket = Ticket::query()->with('event:id,status')->where('code', $code)->first();

        if ($ticket === null) {
            throw new BusinessRuleException('ticket_not_found', 'No ticket has this code.', 404);
        }

        if ($eventId !== null && $ticket->event_id !== $eventId) {
            throw new BusinessRuleException('wrong_event', 'This ticket is for a different event.');
        }

        if ($ticket->status === TicketStatus::Used) {
            throw new BusinessRuleException('already_checked_in', 'This ticket was already used at '.TenantTime::iso($ticket->checked_in_at).'.');
        }

        if ($ticket->status === TicketStatus::Void) {
            throw new BusinessRuleException('ticket_void', 'This ticket is no longer valid (the order was refunded).');
        }

        throw new BusinessRuleException('event_cancelled', 'This event was cancelled.');
    }
}
