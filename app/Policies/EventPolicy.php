<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Tenant\Event;
use App\Models\Tenant\User;

/** Managing events is an admin-only job (door staff only scan tickets). */
class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Event $event): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Event $event): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->isAdmin($user);
    }

    public function publish(User $user, Event $event): bool
    {
        return $this->isAdmin($user);
    }

    public function cancel(User $user, Event $event): bool
    {
        return $this->isAdmin($user);
    }

    public function refundOrders(User $user, Event $event): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
