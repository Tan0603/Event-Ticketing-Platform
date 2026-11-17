<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;

/**
 * Tenancy wiring. Deliberately NOT wired: stancl's automatic create-database/migrate pipeline on
 * TenantCreated, or delete-database on TenantDeleted. Provisioning is done by
 * App\Actions\Tenancy\ProvisionTenant (retry-safe, shared by POST /register and `tenant:provision`)
 * and teardown by `tenant:purge` / ProvisionTenant::fail().
 */
class TenancyServiceProvider extends ServiceProvider
{
    /** @return array<class-string, array<int, class-string>> */
    public function events(): array
    {
        return [
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],
        ];
    }

    public function register(): void
    {
        // Tenant ids may contain '-', which would need quoted identifiers in Postgres.
        // Ids cannot contain '_', so this mapping is collision-free.
        DatabaseConfig::generateDatabaseNamesUsing(
            fn (TenantWithDatabase $tenant): string => config('tenancy.database.prefix')
                .str_replace('-', '_', (string) $tenant->getTenantKey())
        );
    }

    public function boot(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }
}
