<?php

namespace App\Actions\Payments;

final readonly class WebhookResult
{
    public function __construct(
        /** What we did: paid, paid_late, failed, duplicate, already_paid, amount_mismatch, late_payment_no_stock, unknown_order, ignored. */
        public string $outcome,
        public ?string $orderReference = null,
        public int $httpStatus = 200,
        public ?int $orderId = null,
    ) {}
}
