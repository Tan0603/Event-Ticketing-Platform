<?php

namespace App\Actions\TicketTypes;

use App\Enums\EventStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use App\Support\StorefrontCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DeleteTicketType
{
    private const FOREIGN_KEY_VIOLATION = '23503';

    public function execute(TicketType $ticketType): void
    {
        try {
            DB::transaction(fn () => $this->delete($ticketType));
        } catch (QueryException $e) {
            // Safety net: the order_items foreign key has the last word. The transaction is already rolled
            // back here, so the client gets the same 409 instead of a 500.
            if (($e->errorInfo[0] ?? $e->getCode()) === self::FOREIGN_KEY_VIOLATION) {
                throw self::hasOrders();
            }

            throw $e;
        }
    }

    private function delete(TicketType $ticketType): void
    {
        /** @var Event $event */
        $event = Event::query()->lockForUpdate()->findOrFail($ticketType->event_id);

        if ($event->status === EventStatus::Published && $event->ticketTypes()->count() === 1) {
            throw new BusinessRuleException('last_ticket_type', 'A published event must keep at least one ticket type.');
        }

        // Atomic: deletes only while nothing is sold or held and no order line refers to it (any order status),
        // however many checkouts are running.
        $deleted = TicketType::query()
            ->whereKey($ticketType->id)
            ->where('sold', 0)
            ->where('held', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_items')->whereColumn('order_items.ticket_type_id', 'ticket_types.id'))
            ->delete();

        if ($deleted === 0) {
            if (! TicketType::query()->whereKey($ticketType->id)->exists()) {
                return; // already gone
            }

            throw self::hasOrders();
        }

        StorefrontCache::flush();
    }

    private static function hasOrders(): BusinessRuleException
    {
        return new BusinessRuleException('ticket_type_has_sales', 'A ticket type with orders cannot be deleted.');
    }
}
