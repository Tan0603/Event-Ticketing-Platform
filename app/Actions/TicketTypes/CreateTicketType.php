<?php

namespace App\Actions\TicketTypes;

use App\Enums\EventStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use App\Support\StorefrontCache;
use Illuminate\Support\Facades\DB;

class CreateTicketType
{
    /**
     * @param  array<string, mixed>  $attributes  name, description, price_minor, quantity, max_per_order,
     *                                            sales_start_at, sales_end_at, sort_order (all validated by the request)
     */
    public function execute(Event $event, array $attributes): TicketType
    {
        return DB::transaction(function () use ($event, $attributes): TicketType {
            // Lock order: event first, so this cannot interleave with cancel / date edits.
            /** @var Event $locked */
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($locked->status === EventStatus::Cancelled) {
                throw new BusinessRuleException('event_cancelled', 'Ticket types cannot be added to a cancelled event.');
            }

            SalesWindow::assertValid($locked, $attributes['sales_start_at'] ?? null, $attributes['sales_end_at'] ?? null);

            TierPredecessor::assertValid($locked->id, null, $attributes[TierPredecessor::FIELD] ?? null);

            $attributes['max_per_order'] ??= (int) config('ticketing.max_per_order_default');
            $attributes['sort_order'] ??= ((int) $locked->ticketTypes()->reorder()->max('sort_order')) + 1;

            $ticketType = new TicketType($attributes);
            $ticketType->event()->associate($locked);
            $ticketType->save();

            // Public shop cache: anything visitors see may have changed.
            StorefrontCache::flush();

            return $ticketType;
        });
    }
}
