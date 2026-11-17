<?php

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

        // Admin routes (events, ticket types, orders, reports ...) are added per feature inside:
        // Route::middleware('can:access-admin')->prefix('admin')->group(...)
    });
});
