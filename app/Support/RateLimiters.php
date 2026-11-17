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

        // Per tenant + email + IP. TenantKey::for() makes the key tenant-scoped, so tenant A's
        // failed logins can never throttle tenant B.
        RateLimiter::for('login', fn (Request $request): Limit => self::limit('login_per_minute', 60)
            ->by(TenantKey::for('login', strtolower((string) $request->input('email')), (string) $request->ip())));

        // Public browsing: per tenant + IP, so one tenant's busy shop never throttles another's.
        RateLimiter::for('storefront', fn (Request $request): Limit => self::limit('storefront_per_minute', 60)
            ->by(TenantKey::for('storefront', (string) $request->ip())));

        // Two independent limits per tenant: a strict one per buyer e-mail (stops one person hoarding stock) and a
        // generous one per IP (shared offices / NAT must still be able to buy).
        RateLimiter::for('checkout', fn (Request $request): array => [
            self::limit('checkout_per_minute_email', 60)->by(TenantKey::for('checkout', 'email', strtolower((string) $request->input('buyer_email')))),
            self::limit('checkout_per_minute_ip', 60)->by(TenantKey::for('checkout', 'ip', (string) $request->ip())),
        ]);

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
