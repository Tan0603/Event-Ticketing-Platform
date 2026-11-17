<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Named limiters. Every key is built with TenantKey so limiter state is namespaced and
 * can never be shared between tenants. A configured limit of 0 disables that limiter.
 */
final class RateLimiters
{
    public static function register(): void
    {
        // Each sign-up creates a database, so this is strictly per IP.
        RateLimiter::for('register', function (Request $request): array {
            $ip = (string) $request->ip();

            return [
                self::limit('register_per_minute', 60)->by(TenantKey::central('register', 'min', 'ip', $ip)),
                self::limit('register_per_day', 86400)->by(TenantKey::central('register', 'day', 'ip', $ip)),
            ];
        });

        RateLimiter::for('register-status', fn (Request $request): Limit => self::limit('register_status_per_minute', 60)
            ->by(TenantKey::central('register-status', 'ip', (string) $request->ip())));
    }

    private static function limit(string $configKey, int $windowSeconds): Limit
    {
        $max = (int) config("ticketing.rate_limits.{$configKey}");

        if ($max <= 0) {
            return Limit::none();
        }

        return $windowSeconds === 86400 ? Limit::perDay($max) : Limit::perMinute($max);
    }
}
