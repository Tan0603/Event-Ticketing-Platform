<?php

namespace App\Actions\Reports;

use App\Enums\TicketStatus;
use App\Models\Tenant\Event;
use App\Models\Tenant\Ticket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAttendees
{
    /**
     * Everyone holding a ticket for an event, one page at a time, filterable by ticket type and check-in status.
     * Relations are eager-loaded (a fixed number of extra queries per page), never lazily per row.
     *
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function execute(Event $event, ?int $ticketTypeId, ?TicketStatus $status, int $perPage): LengthAwarePaginator
    {
        return Ticket::query()
            ->where('event_id', $event->id)
            ->when($ticketTypeId !== null, fn ($q) => $q->where('ticket_type_id', $ticketTypeId))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->with(['ticketType:id,name', 'order:id,reference,buyer_name,buyer_email', 'checkedInBy:id,name'])
            ->orderBy('id')
            ->paginate($perPage);
    }
}
