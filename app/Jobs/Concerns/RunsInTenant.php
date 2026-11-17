<?php

namespace App\Jobs\Concerns;

use App\Models\Central\Tenant;
use Closure;

/** For tenant jobs: they carry a tenant id (never a model) and run inside that tenant, whoever called them. */
trait RunsInTenant
{
    /** @param Closure(): mixed $callback */
    private function inTenant(Closure $callback): mixed
    {
        if (tenancy()->initialized && tenant('id') === $this->tenantId) {
            return $callback();
        }

        return Tenant::query()->findOrFail($this->tenantId)->run($callback);
    }
}
