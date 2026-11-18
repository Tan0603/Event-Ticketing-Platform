<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Tenant\AttendeeExport;
use App\Models\Tenant\Event;
use App\Models\Tenant\User;

/** Attendee data is personal data: admins only (door staff only scan tickets). */
class AttendeeExportPolicy
{
    public function create(User $user, Event $event): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, AttendeeExport $export): bool
    {
        return $user->role === UserRole::Admin;
    }
}
