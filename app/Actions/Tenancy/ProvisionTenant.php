<?php

namespace App\Actions\Tenancy;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use App\Support\TenantDatabase;
use App\Support\TenantKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The single provisioning implementation, used by the queued job AND `php artisan tenant:provision`.
 *
 * Every step is "ensure", not "create", so a retry after a partial run carries on safely:
 * no "database already exists", no second admin.
 */
class ProvisionTenant
{
    public function run(string $tenantId, string $adminName, string $adminEmail, string $passwordHash): Tenant
    {
        // The DB state is the real guard; the lock just stops two workers doing the same work at once.
        return Cache::lock(TenantKey::central('provision', $tenantId), 300)
            ->block(10, fn (): Tenant => $this->provision($tenantId, $adminName, $adminEmail, $passwordHash));
    }

    /** Permanent failure: mark the tenant failed and drop its database so nothing is orphaned. */
    public function fail(string $tenantId, string $reason): void
    {
        $tenant = Tenant::query()->find($tenantId);

        // Never touch an active tenant, and never touch a tenant that does not exist.
        if ($tenant === null || $tenant->status !== TenantStatus::Provisioning) {
            return;
        }

        $tenant->status = TenantStatus::Failed;
        $tenant->failure_reason = Str::limit($reason, 500, '');
        $tenant->save();

        TenantDatabase::dropIfExists($tenant);
    }

    private function provision(string $tenantId, string $adminName, string $adminEmail, string $passwordHash): Tenant
    {
        $tenant = Tenant::query()->findOrFail($tenantId);

        // Active: idempotent no-op. Failed: never resurrected by a stray retry.
        if ($tenant->status !== TenantStatus::Provisioning) {
            return $tenant;
        }

        TenantDatabase::ensureExists($tenant);

        $this->migrate($tenant);

        $tenant->run(function () use ($adminName, $adminEmail, $passwordHash): void {
            // UNIQUE(email) + firstOrCreate: a retry can never create a second admin.
            User::query()->firstOrCreate(
                ['email' => strtolower($adminEmail)],
                ['name' => $adminName, 'password' => $passwordHash, 'role' => UserRole::Admin],
            );
        });

        $tenant->webhook_secret ??= bin2hex(random_bytes(32));
        $tenant->failure_reason = null;
        $tenant->status = TenantStatus::Active;
        $tenant->save();

        return $tenant;
    }

    private function migrate(Tenant $tenant): void
    {
        $exitCode = Artisan::call('tenants:migrate', ['--tenants' => [$tenant->getTenantKey()]]);

        if ($exitCode !== 0) {
            throw new RuntimeException("Tenant migrations failed for [{$tenant->getTenantKey()}]: ".trim(Artisan::output()));
        }
    }
}
