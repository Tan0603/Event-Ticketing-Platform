<?php

use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TicketTypeController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Central\RegisterController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\EventController as StorefrontEventController;
use App\Http\Controllers\Storefront\TicketController;
use App\Http\Controllers\Webhook\PaymentWebhookController;
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
/*
 * Payment provider webhook: it cannot send our header, so the tenant is the {tenant} path segment, and the
 * signature is checked with THAT tenant's secret before the body is read.
 */
Route::post('/webhooks/payments/{tenant}', [PaymentWebhookController::class, 'handle'])
    ->middleware(['tenant.path', 'tenant.active', 'throttle:webhook', 'webhook.signature']);

/*
 * Links inside the buyer's e-mail: signed (tamper-proof, expiring) and addressed by tenant path, because a
 * link in an inbox cannot carry the X-Tenant-ID header.
 */
Route::middleware(['tenant.path', 'tenant.active', 'throttle:storefront', 'signed'])->prefix('public/{tenant}')->group(function () {
    Route::get('/orders/{reference}/tickets', [TicketController::class, 'show'])->name('public.tickets.show');
    Route::get('/tickets/{code}/qr', [TicketController::class, 'qr'])->name('public.tickets.qr');
});

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

        // Door staff and admins scan tickets at the entrance.
        Route::post('/check-in', [CheckInController::class, 'store'])->middleware(['can:check-in', 'throttle:check-in']);

        Route::middleware('can:access-admin')->prefix('admin')->group(function () {
            Route::apiResource('events', EventController::class);
            Route::post('events/{event}/publish', [EventController::class, 'publish']);
            Route::post('events/{event}/cancel', [EventController::class, 'cancel']);
            Route::post('events/{event}/refund-orders', [EventController::class, 'refundOrders']);

            Route::get('settings', [SettingsController::class, 'show']);
            Route::get('dashboard', [ReportController::class, 'dashboard']);
            Route::get('reports/sales', [ReportController::class, 'sales']);
            Route::get('events/{event}/report', [ReportController::class, 'event']);

            Route::get('orders', [OrderController::class, 'index']);
            Route::get('orders/{order:reference}', [OrderController::class, 'show']);
            Route::post('orders/{order:reference}/refund', [OrderController::class, 'refund']);

            Route::get('events/{event}/ticket-types', [TicketTypeController::class, 'index']);
            Route::post('events/{event}/ticket-types', [TicketTypeController::class, 'store']);
            Route::apiResource('ticket-types', TicketTypeController::class)->only(['show', 'update', 'destroy'])->parameters(['ticket-types' => 'ticketType']);
        });
    });
});
