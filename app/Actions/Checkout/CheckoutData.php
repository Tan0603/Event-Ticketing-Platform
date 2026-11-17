<?php

namespace App\Actions\Checkout;

final readonly class CheckoutData
{
    /**
     * @param  array<int, int>  $items  ticket_type_id => quantity
     */
    public function __construct(
        public string $eventSlug,
        public string $buyerName,
        public string $buyerEmail,
        public array $items,
        public ?string $idempotencyKey,
    ) {}

    /** Identifies "the same request" for idempotency: a reused key with a different body is refused. */
    public function fingerprint(): string
    {
        $items = $this->items;
        ksort($items);

        return hash('sha256', json_encode([$this->eventSlug, $this->buyerName, strtolower($this->buyerEmail), $items], JSON_THROW_ON_ERROR));
    }
}
