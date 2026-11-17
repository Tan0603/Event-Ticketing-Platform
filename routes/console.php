<?php

use App\Console\DispatchForActiveTenants;
use App\Jobs\ExpirePendingOrdersJob;
use App\Jobs\QueueEventRemindersJob;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work. Every task fans out ONE job per active tenant (see DispatchForActiveTenants); the
 * per-tenant jobs are idempotent, so an overlap or a missed minute is harmless.
 */
Schedule::call(new DispatchForActiveTenants(ExpirePendingOrdersJob::class))
    ->name('expire-pending-orders')->everyMinute()->withoutOverlapping(5)->onOneServer();

Schedule::call(new DispatchForActiveTenants(QueueEventRemindersJob::class))
    ->name('queue-event-reminders')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
