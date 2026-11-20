<?php

namespace App\Actions\Events;

use App\Exceptions\BusinessRuleException;
use App\Models\Tenant\Event;
use App\Models\Tenant\Order;
use App\Support\StorefrontCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DeleteEvent
{
    private const FOREIGN_KEY_VIOLATION = '23503';

    public function execute(Event $event): void
    {
        try {
            DB::transaction(function () use ($event): void {
                /** @var Event $locked */
                $locked = Event::query()->lockForUpdate()->findOrFail($event->id);

                // An event with any order (even a failed or expired one) keeps its history: cancel it instead.
                // hasSales() stays as a defensive check; orders (event_id, status) is indexed.
                if ($locked->hasSales() || Order::query()->where('event_id', $locked->id)->exists()) {
                    throw self::hasOrders();
                }

                $locked->ticketTypes()->delete();
                $locked->delete();
                StorefrontCache::flush();
            });
        } catch (QueryException $e) {
            // Safety net: the foreign keys (orders, order_items, tickets) have the last word. The transaction is
            // already rolled back here, so the client gets the same 409 instead of a 500.
            if (($e->errorInfo[0] ?? $e->getCode()) === self::FOREIGN_KEY_VIOLATION) {
                throw self::hasOrders();
            }

            throw $e;
        }
    }

    private static function hasOrders(): BusinessRuleException
    {
        return new BusinessRuleException('event_has_orders', 'An event with orders cannot be deleted. Cancel it instead.');
    }
}
