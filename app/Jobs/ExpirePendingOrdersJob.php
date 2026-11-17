<?php

namespace App\Jobs;

use App\Actions\Orders\ExpirePendingOrders;
use App\Jobs\Concerns\RunsInTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Releases the held tickets of expired pending orders of ONE tenant. */
class ExpirePendingOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenant, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly string $tenantId) {}

    public function handle(ExpirePendingOrders $action): void
    {
        $this->inTenant(fn () => $action->execute());
    }
}
