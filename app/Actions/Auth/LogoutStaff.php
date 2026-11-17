<?php

namespace App\Actions\Auth;

use App\Models\Tenant\User;

class LogoutStaff
{
    /** Revokes only the token used for this request. */
    public function execute(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
