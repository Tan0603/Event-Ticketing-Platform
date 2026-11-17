<?php

/*
 * Every tunable value lives here (no magic numbers in code).
 * Overridable per environment via .env.
 */
return [
    // How long checkout holds inventory before the order expires.
    'hold_minutes' => (int) env('TICKETING_HOLD_MINUTES', 10),

    // Tenant IDs that cannot be registered.
    'reserved_tenant_ids' => [
        'api', 'admin', 'www', 'register', 'central', 'app', 'auth', 'login',
        'logout', 'webhooks', 'tenants', 'events', 'checkout', 'horizon', 'up',
    ],

    // Default fee settings for new tenants (basis points: 500 = 5.00 %; minor units).
    'default_fee_percent_bps' => (int) env('TICKETING_DEFAULT_FEE_BPS', 500),
    'default_fee_fixed_minor' => (int) env('TICKETING_DEFAULT_FEE_FIXED', 50),

    'cache' => [
        // Storefront availability may be this stale (seconds). Never used to decide a checkout.
        'availability_ttl' => (int) env('TICKETING_CACHE_AVAILABILITY_TTL', 15),
        'storefront_ttl' => (int) env('TICKETING_CACHE_STOREFRONT_TTL', 600),
        'event_list_ttl' => (int) env('TICKETING_CACHE_LIST_TTL', 60),
        'dashboard_ttl' => (int) env('TICKETING_CACHE_DASHBOARD_TTL', 60),
    ],

    // Requests per minute unless stated. 0 disables the limiter (stress env only).
    'rate_limits' => [
        'register_per_minute' => (int) env('TICKETING_REGISTER_RATE_LIMIT', 5),
        'register_per_day' => (int) env('TICKETING_REGISTER_DAILY_LIMIT', 20),
        'register_status_per_minute' => (int) env('TICKETING_REGISTER_STATUS_LIMIT', 60),
        'checkout_per_minute_email' => (int) env('TICKETING_CHECKOUT_EMAIL_LIMIT', 10),
        // High per IP so the 200-request stress test reaches the inventory locking path.
        'checkout_per_minute_ip' => (int) env('TICKETING_CHECKOUT_IP_LIMIT', 300),
        'login_per_minute' => (int) env('TICKETING_LOGIN_RATE_LIMIT', 5),
        'webhook_per_minute' => (int) env('TICKETING_WEBHOOK_RATE_LIMIT', 300),
    ],

    'reminder_hours_before' => 24,
    'max_per_order_default' => 10,
    'max_per_order_cap' => 100,

    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],
];
