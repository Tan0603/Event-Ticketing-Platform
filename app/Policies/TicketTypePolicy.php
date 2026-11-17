<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Tenant\TicketType;
use App\Models\Tenant\User;

class TicketTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, TicketType $ticketType): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, TicketType $ticketType): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, TicketType $ticketType): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
