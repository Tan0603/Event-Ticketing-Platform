<?php

namespace App\Jobs\Concerns;

use App\Exceptions\TenantDatabaseMissing;
use App\Models\Central\Tenant;
use App\Support\TenantDatabase;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * For tenant jobs: they carry a tenant id (never a model) and run inside that tenant, whoever called them.
 *
 * The tenant's database is checked BEFORE tenancy is initialised. If it is missing and we let stancl try,
 * DatabaseTenancyBootstrapper throws before the cache and filesystem bootstrappers ran, and the revert() that
 * follows sets `app('cache')` to null and throws on the filesystem. A long-lived queue worker would then fail
 * every later job of every tenant.
 */
trait RunsInTenant
{
    /**
     * Run the callback inside the job's tenant. A missing tenant database fails the job at once (no retries).
     *
     * @param  Closure(): mixed  $callback
     *
     * @throws TenantDatabaseMissing
     */
    private function inTenant(Closure $callback): mixed
    {
        try {
            return $this->runInTenant($callback);
        } catch (TenantDatabaseMissing $e) {
            $this->fail($e); // a retry cannot create the database

            throw $e;
        }
    }

    /**
     * For `failed()` handlers: the same, but a missing tenant database is only logged (there is nothing left to
     * record the failure on), so the failure handler itself never throws again.
     *
     * @param  Closure(): mixed  $callback
     */
    private function inTenantOnFailure(Closure $callback): mixed
    {
        try {
            return $this->runInTenant($callback);
        } catch (TenantDatabaseMissing) {
            return null; // already logged by runInTenant()
        }
    }

    /** @param Closure(): mixed $callback */
    private function runInTenant(Closure $callback): mixed
    {
        if (tenancy()->initialized && tenant('id') === $this->tenantId) {
            return $callback();
        }

        $tenant = Tenant::query()->findOrFail($this->tenantId);

        if (! TenantDatabase::exists($tenant)) {
            Log::warning('Tenant database is missing, skipping tenant job.', ['tenant' => $tenant->id, 'job' => static::class]);

            throw TenantDatabaseMissing::forTenant($tenant->id);
        }

        return $tenant->run($callback);
    }
}
