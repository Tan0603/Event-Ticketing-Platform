<?php

namespace App\Jobs;

use App\Actions\Orders\RefundOrder;
use App\Exceptions\BusinessRuleException;
use App\Jobs\Concerns\RunsInTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Refunds ONE order of ONE tenant. Safe to run twice: a refunded order is simply skipped. */
class RefundOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenant, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $orderId,
        public readonly ?int $refundedBy,
        public readonly ?string $reason = null,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(RefundOrder $refund): void
    {
        $this->inTenant(function () use ($refund): void {
            try {
                $refund->execute($this->orderId, $this->refundedBy, $this->reason);
            } catch (BusinessRuleException $e) {
                // Already refunded or no longer paid: nothing to do. Tickets already used: needs a human, not a retry.
                if (! in_array($e->errorCode, ['invalid_order_state', 'tickets_already_used', 'order_not_found'], true)) {
                    throw $e;
                }
            }
        });
    }
}
