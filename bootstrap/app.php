<?php

use App\Exceptions\ApiErrors;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\EnsureTokenBelongsToTenant;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\IdentifyTenantByHeader;
use App\Http\Middleware\IdentifyTenantByPath;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API only: never redirect guests to a login page; AuthenticationException renders as JSON 401.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->api(prepend: [ForceJsonResponse::class]);

        $middleware->alias([
            'tenant' => IdentifyTenantByHeader::class,
            'tenant.path' => IdentifyTenantByPath::class,
            'tenant.active' => EnsureTenantIsActive::class,
            'tenant.token' => EnsureTokenBelongsToTenant::class,
            'webhook.signature' => VerifyWebhookSignature::class,
        ]);

        // Correctness, not style: tenancy must be initialized BEFORE auth:sanctum, otherwise the
        // token is looked up on the central connection. Order: identify -> ensure active -> auth.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnsureTenantIsActive::class);
        $middleware->prependToPriorityList(before: EnsureTenantIsActive::class, prepend: IdentifyTenantByPath::class);
        $middleware->prependToPriorityList(before: IdentifyTenantByPath::class, prepend: IdentifyTenantByHeader::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ApiErrors::register($exceptions);
    })->create();
