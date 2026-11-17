<?php

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
 * Tenant routes (X-Tenant-ID header + tenant.active) are added per feature.
 */
