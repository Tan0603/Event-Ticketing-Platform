<?php

namespace App\Actions\TicketTypes;

use App\Enums\EventStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use App\Support\StorefrontCache;
use Illuminate\Support\Facades\DB;

class DeleteTicketType
{
    public function execute(TicketType $ticketType): void
    {
        DB::transaction(function () use ($ticketType): void {
            /** @var Event $event */
            $event = Event::query()->lockForUpdate()->findOrFail($ticketType->event_id);

            if ($event->status === EventStatus::Published && $event->ticketTypes()->count() === 1) {
                throw new BusinessRuleException('last_ticket_type', 'A published event must keep at least one ticket type.');
            }

            // Atomic: deletes only while nothing is sold or held, however many checkouts are running.
            $deleted = TicketType::query()
                ->whereKey($ticketType->id)
                ->where('sold', 0)
                ->where('held', 0)
                ->delete();

            if ($deleted === 0) {
                if (! TicketType::query()->whereKey($ticketType->id)->exists()) {
                    return; // already gone
                }

                throw new BusinessRuleException('ticket_type_has_sales', 'A ticket type with sold or reserved tickets cannot be deleted.');
            }

            StorefrontCache::flush();
        });
    }
}
