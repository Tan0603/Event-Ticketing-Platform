<?php

namespace App\Jobs;

use App\Actions\Orders\QueueEventReminders;
use App\Jobs\Concerns\RunsInTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Queues the "your event is coming up" e-mails of ONE tenant. */
class QueueEventRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInTenant, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly string $tenantId) {}

    public function handle(QueueEventReminders $action): void
    {
        $this->inTenant(fn () => $action->execute());
    }
}
