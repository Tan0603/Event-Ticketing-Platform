<?php

namespace App\Support;

use LogicException;

/**
 * Builds every raw Redis / lock / rate-limiter key. Never hand-write these strings:
 * RedisTenancyBootstrapper is not enabled, so isolation is enforced by this prefix.
 */
final class TenantKey
{
    /** Key for the tenant that is currently initialized. Fails closed outside tenant context. */
    public static function for(string ...$parts): string
    {
        $id = tenant('id');

        if (! is_string($id) || $id === '') {
            throw new LogicException('TenantKey::for() called outside tenant context.');
        }

        return self::forTenant($id, ...$parts);
    }

    /** Key for an explicit tenant id (e.g. scheduler fan-out, webhooks before init). */
    public static function forTenant(string $tenantId, string ...$parts): string
    {
        return implode(':', ['t', $tenantId, ...$parts]);
    }

    /** Key for central (non-tenant) concerns such as sign-up rate limiting. */
    public static function central(string ...$parts): string
    {
        return implode(':', ['c', ...$parts]);
    }
}
