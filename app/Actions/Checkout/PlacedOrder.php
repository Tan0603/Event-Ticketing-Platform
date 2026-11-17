<?php

namespace App\Actions\Checkout;

use App\Models\Tenant\Order;

final readonly class PlacedOrder
{
    public function __construct(
        public Order $order,
        /** True when an earlier request with the same Idempotency-Key already created this order. */
        public bool $replayed,
    ) {}
}
