<?php

namespace App\Console;

use App\Enums\TenantStatus;
use App\Models\Central\Tenant;
use App\Support\TenantDatabase;
use Illuminate\Support\Facades\Log;

/**
 * A scheduled task never touches tenant data itself. It dispatches one small job per ACTIVE tenant (not
 * provisioning, not failed), so tenants run in parallel and one slow or broken tenant cannot block the rest.
 *
 * An active tenant whose database no longer exists is skipped with a warning (one query lists all existing tenant
 * databases): its jobs could only fail. Purge such a tenant with `tenant:purge {id} --force`.
 */
final class DispatchForActiveTenants
{
    /** @param class-string $job a job whose constructor takes the tenant id */
    public function __construct(private readonly string $job) {}

    public function __invoke(): void
    {
        $existing = array_flip(TenantDatabase::listWithPrefix((string) config('tenancy.database.prefix')));

        foreach (Tenant::query()->where('status', TenantStatus::Active)->orderBy('id')->cursor() as $tenant) {
            if (! isset($existing[TenantDatabase::name($tenant)])) {
                Log::warning('Active tenant has no database, skipping scheduled job.', ['tenant' => $tenant->id, 'job' => $this->job]);

                continue;
            }

            ($this->job)::dispatch($tenant->id);
        }
    }
}
