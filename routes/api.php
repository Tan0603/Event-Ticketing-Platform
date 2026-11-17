<?php

use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\TicketTypeController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Central\RegisterController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\EventController as StorefrontEventController;
use Illuminate\Support\Facades\Route;

/*
 * Central routes: no X-Tenant-ID header, tenancy is never initialized.
 */
Route::post('/register', [RegisterController::class, 'store'])
    ->middleware('throttle:register');

Route::get('/register/{tenantId}', [RegisterController::class, 'show'])
    ->middleware('throttle:register-status');

/*
 * Tenant routes: X-Tenant-ID header, tenant must be active. Order matters: tenancy first, then auth.
 */
Route::middleware(['tenant', 'tenant.active'])->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Public shop: no login, cached, throttled per tenant + IP.
    Route::middleware('throttle:storefront')->group(function () {
        Route::get('/events', [StorefrontEventController::class, 'index']);
        Route::get('/events/{slug}', [StorefrontEventController::class, 'show']);
        Route::get('/orders/{reference}', [CheckoutController::class, 'show']);
    });

    Route::post('/events/{slug}/checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout');

    Route::middleware(['auth:sanctum', 'tenant.token'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::middleware('can:access-admin')->prefix('admin')->group(function () {
            Route::apiResource('events', EventController::class);
            Route::post('events/{event}/publish', [EventController::class, 'publish']);
            Route::post('events/{event}/cancel', [EventController::class, 'cancel']);

            Route::get('events/{event}/ticket-types', [TicketTypeController::class, 'index']);
            Route::post('events/{event}/ticket-types', [TicketTypeController::class, 'store']);
            Route::apiResource('ticket-types', TicketTypeController::class)->only(['show', 'update', 'destroy'])->parameters(['ticket-types' => 'ticketType']);
        });
    });
});
