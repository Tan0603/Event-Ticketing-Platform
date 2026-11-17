<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Tenant\Order;
use App\Models\Tenant\User;

/** Looking at orders and refunding them is an admin-only job (door staff only scan tickets). */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->isAdmin($user);
    }

    public function refund(User $user, Order $order): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
