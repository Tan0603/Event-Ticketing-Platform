<?php

namespace App\Console;

use App\Enums\TenantStatus;
use App\Models\Central\Tenant;

/**
 * A scheduled task never touches tenant data itself. It dispatches one small job per ACTIVE tenant (not
 * provisioning, not failed), so tenants run in parallel and one slow or broken tenant cannot block the rest.
 */
final class DispatchForActiveTenants
{
    /** @param class-string $job a job whose constructor takes the tenant id */
    public function __construct(private readonly string $job) {}

    public function __invoke(): void
    {
        Tenant::query()->where('status', TenantStatus::Active)->orderBy('id')
            ->pluck('id')
            ->each(fn (string $id) => ($this->job)::dispatch($id));
    }
}
