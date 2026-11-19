<?php

namespace App\Actions\Orders;

use App\Domain\Inventory\EventLock;
use App\Enums\OrderStatus;
use App\Models\Tenant\Order;
use App\Support\StorefrontCache;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExpirePendingOrders
{
    public function __construct(private readonly ReleaseOrder $release) {}

    /**
     * Expire every pending order whose hold ran out and give its tickets back. Runs inside one tenant.
     *
     * Each order is its own short transaction (lock order: event, then order, then ticket types), so one bad
     * order cannot block the others and the locks are held for milliseconds. The state is checked again under
     * the lock: an order paid a moment ago is left alone. Safe to run twice or concurrently.
     *
     * @return int how many orders were expired
     */
    public function execute(): int
    {
        $batch = (int) config('ticketing.expiry_batch_size');
        $expired = 0;
        $skip = [];

        do {
            $ids = Order::query()
                ->where('status', OrderStatus::Pending)->where('expires_at', '<=', now())
                ->whereNotIn('id', $skip)
                ->orderBy('expires_at')->limit($batch)->pluck('id');

            foreach ($ids as $id) {
                try {
                    $expired += $this->expire((int) $id) ? 1 : 0;
                } catch (Throwable $e) {
                    report($e);
                    $skip[] = $id; // try again on the next run, but do not loop on it now
                }
            }
        } while ($ids->count() === $batch);

        if ($expired > 0) {
            StorefrontCache::flush(); // available counts changed
        }

        return $expired;
    }

    private function expire(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $known = Order::query()->find($orderId, ['id', 'event_id']);
            if ($known === null) {
                return false;
            }

            EventLock::acquire($known->event_id);
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->with('items')->findOrFail($orderId);

            if ($order->status !== OrderStatus::Pending || $order->expires_at->isFuture()) {
                return false; // paid, failed or extended since we looked
            }

            $this->release->execute($order, OrderStatus::Expired);

            return true;
        });
    }
}
