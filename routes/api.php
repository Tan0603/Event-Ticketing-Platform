<?php

use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\TicketTypeController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Central\RegisterController;
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
