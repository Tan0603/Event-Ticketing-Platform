<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;

/**
 * Tenancy wiring. Deliberately NOT wired: stancl's automatic create-database/migrate
 * pipeline on TenantCreated. Provisioning is done by App\Actions\Tenancy\ProvisionTenant
 * so it is retry-safe and shared by POST /register and `tenant:provision`.
 */
class TenancyServiceProvider extends ServiceProvider
{
    /** @return array<class-string, array<int, mixed>> */
    public function events(): array
    {
        return [
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],
            // Used by `tenant:purge` to free a failed id.
            Events\TenantDeleted::class => [
                JobPipeline::make([Jobs\DeleteDatabase::class])
                    ->send(fn (Events\TenantDeleted $event) => $event->tenant)
                    ->shouldBeQueued(false),
            ],
        ];
    }

    public function register(): void
    {
        // tenant ids may contain '-', which would need quoted identifiers in Postgres.
        // Ids cannot contain '_', so this mapping is collision-free.
        DatabaseConfig::generateDatabaseNamesUsing(
            fn (TenantWithDatabase $tenant): string => 'tenant_'.str_replace('-', '_', (string) $tenant->getTenantKey())
        );
    }

    public function boot(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }
}
