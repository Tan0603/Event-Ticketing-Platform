<?php

namespace App\Actions\TicketTypes;

use App\Enums\EventStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use App\Models\Tenant\TicketType;
use App\Support\StorefrontCache;
use Illuminate\Support\Facades\DB;

class UpdateTicketType
{
    /**
     * @param  array<string, mixed>  $attributes  only the keys the client sent
     */
    public function execute(TicketType $ticketType, array $attributes): TicketType
    {
        return DB::transaction(function () use ($ticketType, $attributes): TicketType {
            // Lock order: event, then the ticket type.
            /** @var Event $event */
            $event = Event::query()->lockForUpdate()->findOrFail($ticketType->event_id);

            if ($event->status === EventStatus::Cancelled) {
                throw new BusinessRuleException('event_cancelled', 'Ticket types of a cancelled event cannot be edited.');
            }

            /** @var TicketType $locked */
            $locked = TicketType::query()->lockForUpdate()->findOrFail($ticketType->id);
            $locked->setRelation('event', $event);

            // `quantity` is handled separately below: it must be checked against sold + held atomically.
            $newQuantity = $attributes['quantity'] ?? null;
            unset($attributes['quantity']);

            if (array_key_exists(TierPredecessor::FIELD, $attributes)) {
                TierPredecessor::assertValid($event->id, $locked->id, $attributes[TierPredecessor::FIELD]); // under the event lock: no concurrent loop
            }

            $locked->fill($attributes);
            SalesWindow::assertValid($event, $locked->sales_start_at, $locked->sales_end_at);
            $locked->save();

            if ($newQuantity !== null && $newQuantity !== $locked->quantity) {
                // Conditional UPDATE: only applies while sold + held still fit. The CHECK constraint backs it up.
                $updated = TicketType::query()
                    ->whereKey($locked->id)
                    ->whereRaw('sold + held <= ?', [$newQuantity])
                    ->update(['quantity' => $newQuantity]);

                if ($updated === 0) {
                    throw new BusinessRuleException(
                        'quantity_below_sold',
                        "Quantity cannot be lower than the {$locked->sold} sold and {$locked->held} held tickets.",
                    );
                }
            }

            // Public shop cache: anything visitors see may have changed.
            StorefrontCache::flush();

            return $locked->refresh()->setRelation('event', $event);
        });
    }
}
