<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Tenant\User;
use App\Support\RateLimiters;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiters::register();

        // Role gates: the single place roles are interpreted. Policies build on these in later phases.
        Gate::define('access-admin', fn (User $user): bool => $user->role === UserRole::Admin);
        Gate::define('check-in', fn (User $user): bool => in_array($user->role, [UserRole::Admin, UserRole::DoorStaff], true));
    }
}
