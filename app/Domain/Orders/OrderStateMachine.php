<?php

namespace App\Domain\Orders;

use App\Domain\Orders\Exceptions\InvalidOrderTransition;
use App\Enums\OrderStatus;
use App\Models\Tenant\Order;

/**
 * The only code allowed to write `orders.status`.
 *
 * A transition is one conditional UPDATE (`WHERE status = <expected current status>`), so two racing requests
 * (webhook + expiry job, duplicate webhooks ...) cannot both win: the loser changes zero rows and gets an
 * InvalidOrderTransition. The Order model additionally refuses any other write to the column.
 */
final class OrderStateMachine
{
    /** @var array<string, array<int, OrderStatus>> */
    private const ALLOWED = [
        'pending' => [OrderStatus::Paid, OrderStatus::Failed, OrderStatus::Expired],
        // A payment that arrives after the expiry job ran may still be honoured if stock is left.
        'expired' => [OrderStatus::Paid],
        'paid' => [OrderStatus::Refunded],
        'failed' => [],
        'refunded' => [],
    ];

    /** Each target status stamps its own timestamp column. */
    private const STAMP = [
        'paid' => 'paid_at',
        'failed' => 'failed_at',
        'expired' => 'expired_at',
        'refunded' => 'refunded_at',
    ];

    public static function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value], true);
    }

    /**
     * Create an order in its initial status (pending). The initial status is the one allowed direct write.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function begin(array $attributes): Order
    {
        $order = new Order;
        $order->forceFill($attributes + ['status' => OrderStatus::Pending]);
        $order->save();

        return $order;
    }

    /**
     * @throws InvalidOrderTransition when the move is not allowed or the order is no longer in `$order->status`
     */
    public static function transition(Order $order, OrderStatus $to): Order
    {
        $from = $order->status;

        if (! self::canTransition($from, $to)) {
            throw new InvalidOrderTransition($from, $to);
        }

        $now = now();
        $changes = ['status' => $to->value, self::STAMP[$to->value] => $now, 'updated_at' => $now];

        $updated = Order::query()
            ->whereKey($order->getKey())
            ->where('status', $from->value)
            ->toBase()
            ->update($changes);

        if ($updated === 0) {
            throw new InvalidOrderTransition($from, $to);
        }

        $order->forceFill(['status' => $to, self::STAMP[$to->value] => $now, 'updated_at' => $now])->syncOriginal();

        return $order;
    }
}
