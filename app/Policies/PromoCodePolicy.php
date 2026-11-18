<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Tenant\PromoCode;
use App\Models\Tenant\User;

/** Promo codes are an admin-only job. */
class PromoCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, PromoCode $promoCode): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, PromoCode $promoCode): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, PromoCode $promoCode): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
