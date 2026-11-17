<?php

namespace App\Actions\Events;

use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use Illuminate\Support\Facades\DB;

class DeleteEvent
{
    public function execute(Event $event): void
    {
        DB::transaction(function () use ($event): void {
            /** @var Event $locked */
            $locked = Event::query()->lockForUpdate()->findOrFail($event->id);

            // An event with sales keeps its history: cancel it instead.
            if ($locked->hasSales()) {
                throw new BusinessRuleException('event_has_orders', 'An event with sold or reserved tickets cannot be deleted. Cancel it instead.');
            }

            $locked->ticketTypes()->delete();
            $locked->delete();
        });
    }
}
