<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The central row says the tenant exists, but its PostgreSQL database does not. Tenant jobs throw this BEFORE
 * initialising tenancy: stancl's bootstrappers fail half way and their revert() then corrupts the process.
 */
class TenantDatabaseMissing extends RuntimeException
{
    public static function forTenant(string $tenantId): self
    {
        return new self("The database of tenant [{$tenantId}] does not exist. Purge the tenant with `tenant:purge {$tenantId} --force`.");
    }
}
