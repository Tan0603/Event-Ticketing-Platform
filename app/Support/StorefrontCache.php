<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cache for the public shop, namespaced per tenant with TenantKey.
 *
 * Invalidation is a version bump: every key contains the current version, so bumping it makes all old
 * entries unreachable at once (they expire on their own TTL). That works on any cache driver, needs no
 * cache tags, and is one O(1) write however many pages were cached.
 *
 * Only display data lives here. Stock decisions are made in PostgreSQL, never from this cache.
 */
final class StorefrontCache
{
    /**
     * @param  Closure(): mixed  $callback
     */
    public static function remember(string $name, int $ttlSeconds, Closure $callback): mixed
    {
        // The version is read once, before computing: a value computed from data that changes mid-way is
        // stored under the old version and is simply never read again.
        return Cache::remember(TenantKey::for('storefront', 'v'.self::version(), $name), $ttlSeconds, $callback);
    }

    /**
     * Forget everything cached for the current tenant. Runs after the surrounding transaction commits, so a
     * concurrent reader cannot re-cache the pre-commit data under the new version.
     */
    public static function flush(): void
    {
        DB::afterCommit(function (): void {
            Cache::increment(TenantKey::for('storefront', 'version'));
        });
    }

    private static function version(): int
    {
        return (int) Cache::get(TenantKey::for('storefront', 'version'), 0);
    }
}
